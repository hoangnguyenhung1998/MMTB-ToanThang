from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

from pydantic import ValidationError

from .models import ReconciliationResult


class OpenClawError(RuntimeError):
    def __init__(self, message: str, retryable: bool = True):
        super().__init__(message)
        self.retryable = retryable


class OpenClawClient:
    def __init__(self, command: str, session_key: str, timeout_seconds: int,
                 agent_id: str | None = None, model: str | None = None,
                 thinking: str = "medium"):
        self.command = command
        self.session_key = session_key
        self.timeout_seconds = timeout_seconds
        self.agent_id = agent_id
        self.model = model
        self.thinking = thinking

    def reconcile(self, job: dict, image_paths: dict[int, Path]) -> ReconciliationResult:
        prompt = self._prompt(job, image_paths)
        job_session_key = f"{self.session_key}-job-{int(job['id'])}"
        prompt_path: Path | None = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w", suffix=".txt", prefix=f"mmtb-reconciliation-{job['id']}-",
                encoding="utf-8", delete=False,
            ) as prompt_file:
                prompt_file.write(prompt)
                prompt_path = Path(prompt_file.name)
            agent_args = ["agent", "--session-key", job_session_key,
                          "--message-file", str(prompt_path), "--thinking", self.thinking,
                          "--timeout", str(self.timeout_seconds), "--json"]
            if self.agent_id:
                agent_args[1:1] = ["--agent", self.agent_id]
            if self.model:
                agent_args[1:1] = ["--model", self.model]
            command = self._build_command(agent_args)
            process = subprocess.run(
                command,
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=self.timeout_seconds + 30,
                check=False,
                shell=False,
            )
        except FileNotFoundError as exc:
            raise OpenClawError(f"OpenClaw command was not found: {self.command}", retryable=False) from exc
        except subprocess.TimeoutExpired as exc:
            raise OpenClawError("OpenClaw reconciliation timed out.", retryable=True) from exc
        finally:
            if prompt_path is not None:
                prompt_path.unlink(missing_ok=True)
        if process.returncode != 0:
            detail = process.stderr.strip()[-2000:] or "unknown CLI error"
            raise OpenClawError(f"OpenClaw exited with code {process.returncode}: {detail}")
        try:
            envelope = json.loads(process.stdout)
            text = self._response_text(envelope)
            return ReconciliationResult.model_validate_json(self._strip_fence(text))
        except (json.JSONDecodeError, ValidationError, KeyError, TypeError) as exc:
            raise OpenClawError(f"OpenClaw returned invalid reconciliation JSON: {exc}", retryable=True) from exc

    def _build_command(self, arguments: list[str]) -> list[str]:
        resolved = shutil.which(self.command) or self.command
        if os.name == "nt" and resolved.lower().endswith((".cmd", ".bat")):
            command_line = subprocess.list2cmdline([resolved, *arguments])
            return [os.environ.get("COMSPEC", "cmd.exe"), "/d", "/s", "/c", command_line]
        return [resolved, *arguments]

    @staticmethod
    def _response_text(envelope: dict) -> str:
        payloads = envelope.get("payloads") or envelope.get("result", {}).get("payloads") or []
        for payload in payloads:
            if isinstance(payload, dict) and payload.get("text"):
                return str(payload["text"])
        raise KeyError("payloads[].text")

    @staticmethod
    def _strip_fence(value: str) -> str:
        value = value.strip()
        if value.startswith("```"):
            value = value.split("\n", 1)[1]
            value = value.rsplit("```", 1)[0]
        return value.strip()

    @staticmethod
    def _prompt(job: dict, image_paths: dict[int, Path]) -> str:
        evidence = [
            {"ocr_job_id": ocr_id, "local_image_path": str(path.resolve())}
            for ocr_id, path in sorted(image_paths.items())
        ]
        payload = {**job, "source_images": evidence}
        return (
            "Bạn là AI hậu kiểm thiết bị MMTB. Hãy đối chiếu ảnh chấm công hằng ngày, "
            "nhật trình tuần và thông tin điều động. Chỉ kết luận từ bằng chứng được cung cấp; "
            "không đoán mã máy hoặc dữ liệu bị thiếu. Đọc các ảnh tại local_image_path. "
            "Trả về DUY NHẤT một JSON object, không Markdown, theo schema: "
            '{"outcome":"MATCHED|WARNING|EXCEPTION|UNRESOLVED","summary":"...",'
            '"confidence":0.0,"findings":[{"code":"...","severity":"INFO|WARNING|CRITICAL",'
            '"title":"...","description":"...","evidence":{},"suggested_action":"...",'
            '"confidence":0.0}]}. '
            "Dùng UNRESOLVED nếu bằng chứng không đủ; không tự sửa dữ liệu Laravel.\n\n"
            f"DỮ LIỆU JOB:\n{json.dumps(payload, ensure_ascii=False, default=str)}"
        )
