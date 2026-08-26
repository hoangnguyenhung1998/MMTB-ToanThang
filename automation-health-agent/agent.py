from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

AGENT_VERSION = "0.1.0"
TIMESTAMP_PATTERN = re.compile(r"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})")
JOB_PATTERN = re.compile(r"(?:job|command)\s+#?(\d+)", re.IGNORECASE)
SUCCESS_PATTERN = re.compile(r"\b(completed|sent|stored|started|connected)\b", re.IGNORECASE)
ERROR_PATTERN = re.compile(r"\b(warning|error|failed|exception|traceback)\b", re.IGNORECASE)


def load_dotenv(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


@dataclass(frozen=True)
class ServiceDefinition:
    service_key: str
    name: str
    service_type: str
    task_name: str | None
    log_path: Path | None


class LogSnapshot:
    def __init__(self, path: Path | None):
        self.lines = self._tail(path, 250)

    @staticmethod
    def _tail(path: Path | None, limit: int) -> list[str]:
        if path is None or not path.exists():
            return []
        try:
            with path.open("r", encoding="utf-8", errors="replace") as handle:
                return list(handle.readlines())[-limit:]
        except OSError:
            return []

    def last_success_at(self) -> str | None:
        for line in reversed(self.lines):
            if SUCCESS_PATTERN.search(line):
                return self._timestamp(line)
        return None

    def current_job(self) -> str | None:
        for line in reversed(self.lines):
            match = JOB_PATTERN.search(line)
            if match:
                return f"job-{match.group(1)}"
        return None

    def last_error(self) -> str | None:
        for line in reversed(self.lines):
            if ERROR_PATTERN.search(line):
                return line.strip()[-1800:]
        return None

    def consecutive_errors(self) -> int:
        count = 0
        for line in reversed(self.lines):
            if ERROR_PATTERN.search(line):
                count += 1
            elif SUCCESS_PATTERN.search(line):
                break
        return count

    @staticmethod
    def _timestamp(line: str) -> str | None:
        match = TIMESTAMP_PATTERN.match(line)
        if not match:
            return None
        try:
            return datetime.strptime(match.group(1), "%Y-%m-%d %H:%M:%S").astimezone().isoformat()
        except ValueError:
            return None


class WindowsTaskReader:
    def read(self, names: list[str]) -> dict[str, dict[str, Any]]:
        if os.name != "nt" or not names:
            return {}
        encoded_names = ",".join(json.dumps(name) for name in names)
        script = (
            f"$names=@({encoded_names}); $out=@(); foreach($name in $names) {{ "
            "$task=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue; "
            "if($task){$info=Get-ScheduledTaskInfo -TaskName $name; "
            "$out += [PSCustomObject]@{name=$name;state=[string]$task.State;"
            "last_result=$info.LastTaskResult;last_run=[string]$info.LastRunTime}}}; "
            "$out | ConvertTo-Json -Compress"
        )
        result = subprocess.run(
            ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
            capture_output=True, text=True, timeout=20, check=False,
        )
        if result.returncode != 0 or not result.stdout.strip():
            return {}
        data = json.loads(result.stdout)
        rows = data if isinstance(data, list) else [data]
        return {row["name"]: row for row in rows}


class HealthAgent:
    def __init__(self, root: Path, openclaw_command: str):
        self.root = root
        self.openclaw_command = openclaw_command
        self.task_reader = WindowsTaskReader()
        self.definitions = [
            ServiceDefinition("zalo-collector", "Zalo Collector", "ZALO_COLLECTOR", "MMTB-ZaloCollector", root / "collector/data/collector.log"),
            ServiceDefinition("ocr-worker", "OCR Worker", "OCR_WORKER", "MMTB-RapidOCRWorker", root / "ocr-worker/data/worker.log"),
            ServiceDefinition("journal-worker", "Journal Worker", "JOURNAL_WORKER", "MMTB-JournalWorker", root / "journal-worker/data/worker.log"),
            ServiceDefinition("reconciliation-worker", "Reconciliation Worker", "RECONCILIATION_WORKER", "MMTB-OpenClawReconciliationWorker", root / "reconciliation-worker/data/worker.log"),
            ServiceDefinition("openclaw-gateway", "OpenClaw Gateway", "OPENCLAW_GATEWAY", None, None),
        ]

    def snapshot(self) -> list[dict[str, Any]]:
        tasks = self.task_reader.read([definition.task_name for definition in self.definitions if definition.task_name])
        services = []
        for definition in self.definitions:
            if definition.service_type == "OPENCLAW_GATEWAY":
                services.append(self._openclaw_snapshot(definition))
            else:
                services.append(self._task_snapshot(definition, tasks.get(definition.task_name or "")))
        return services

    def _task_snapshot(self, definition: ServiceDefinition, task: dict[str, Any] | None) -> dict[str, Any]:
        log = LogSnapshot(definition.log_path)
        state = str(task.get("state", "MISSING")).upper() if task else "MISSING"
        status = "HEALTHY" if state == "RUNNING" else "PAUSED" if state == "DISABLED" else "DEGRADED"
        error = log.last_error()
        if state == "MISSING":
            error = f"Không tìm thấy Scheduled Task {definition.task_name}."
        elif state not in {"RUNNING", "DISABLED"}:
            error = f"Scheduled Task {definition.task_name} đang ở trạng thái {state}." + (f" {error}" if error else "")
        errors = max(log.consecutive_errors(), 1 if status == "DEGRADED" else 0)
        return {
            "service_key": definition.service_key, "name": definition.name,
            "service_type": definition.service_type, "status": status,
            "current_job": log.current_job(), "consecutive_errors": errors,
            "last_success_at": log.last_success_at(), "error_code": None if status == "HEALTHY" else f"TASK_{state}",
            "error_message": error, "metrics": {"task_state": state, "last_task_result": task.get("last_result") if task else None},
        }

    def _openclaw_snapshot(self, definition: ServiceDefinition) -> dict[str, Any]:
        try:
            result = subprocess.run(
                f'"{self.openclaw_command}" gateway status', capture_output=True, text=True,
                timeout=20, shell=True, check=False,
            )
            output = (result.stdout or result.stderr).strip()
            healthy = result.returncode == 0
        except (OSError, subprocess.TimeoutExpired) as exc:
            output, healthy = str(exc), False
        return {
            "service_key": definition.service_key, "name": definition.name,
            "service_type": definition.service_type, "status": "HEALTHY" if healthy else "DEGRADED",
            "consecutive_errors": 0 if healthy else 1,
            "last_success_at": datetime.now().astimezone().isoformat() if healthy else None,
            "error_code": None if healthy else "GATEWAY_UNAVAILABLE",
            "error_message": None if healthy else output[-1800:],
            "metrics": {"command_exit_code": result.returncode if 'result' in locals() else None},
        }


def post_heartbeat(url: str, token: str, payload: dict[str, Any], timeout: int = 20) -> dict[str, Any]:
    request = urllib.request.Request(
        url, data=json.dumps(payload).encode("utf-8"), method="POST",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json", "User-Agent": f"mmtb-health-agent/{AGENT_VERSION}"},
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def main() -> int:
    agent_root = Path(__file__).resolve().parent
    load_dotenv(agent_root / ".env")
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.FileHandler(agent_root / "agent.log", encoding="utf-8"), logging.StreamHandler()],
    )
    url = os.environ.get("AUTOMATION_HEALTH_API_URL", "").strip()
    token = os.environ.get("AUTOMATION_HEALTH_TOKEN", "").strip()
    if not url or not token:
        logging.error("Thiếu AUTOMATION_HEALTH_API_URL hoặc AUTOMATION_HEALTH_TOKEN trong .env.")
        return 2
    root = Path(os.environ.get("AUTOMATION_REPOSITORY_ROOT", str(agent_root.parent))).resolve()
    interval = max(30, int(os.environ.get("AUTOMATION_AGENT_INTERVAL_SECONDS", "60")))
    agent = HealthAgent(root, os.environ.get("OPENCLAW_COMMAND", "openclaw"))
    logging.info("MMTB Automation Health Agent started; root=%s", root)
    while True:
        payload = {
            "agent_version": AGENT_VERSION,
            "metadata": {"computer_name": os.environ.get("COMPUTERNAME"), "platform": sys.platform},
            "services": agent.snapshot(),
        }
        try:
            response = post_heartbeat(url, token, payload)
            logging.info("Heartbeat sent: %s service(s).", len(response.get("services", [])))
        except (OSError, urllib.error.HTTPError, ValueError) as exc:
            logging.warning("Heartbeat failed: %s", exc)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
