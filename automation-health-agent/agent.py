from __future__ import annotations

import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
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

    def latest_error_at(self) -> datetime | None:
        for line in reversed(self.lines):
            if ERROR_PATTERN.search(line):
                return self._datetime(line)
        return None

    def consecutive_errors(self, fresh_seconds: int = 600) -> int:
        latest_error_at = self.latest_error_at()
        if latest_error_at is None:
            return 0
        age = (datetime.now(timezone.utc) - latest_error_at.astimezone(timezone.utc)).total_seconds()
        if age > fresh_seconds:
            return 0
        count = 0
        for line in reversed(self.lines):
            if ERROR_PATTERN.search(line):
                count += 1
            elif SUCCESS_PATTERN.search(line):
                break
        return count

    @staticmethod
    def _timestamp(line: str) -> str | None:
        value = LogSnapshot._datetime(line)
        return value.astimezone(timezone.utc).isoformat() if value else None

    @staticmethod
    def _datetime(line: str) -> datetime | None:
        match = TIMESTAMP_PATTERN.match(line)
        if not match:
            return None
        try:
            return datetime.strptime(match.group(1), "%Y-%m-%d %H:%M:%S").astimezone()
        except ValueError:
            return None


def read_health_file(log_path: Path | None) -> dict[str, Any]:
    if log_path is None:
        return {}
    path = log_path.parent / "health.json"
    try:
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
    except (OSError, ValueError):
        return {}


def iso_age_seconds(value: str | None) -> float | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return (datetime.now(timezone.utc) - parsed.astimezone(timezone.utc)).total_seconds()
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

    def start(self, name: str) -> None:
        if os.name != "nt":
            return
        escaped = name.replace("'", "''")
        result = subprocess.run(
            [
                "powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
                f"Start-ScheduledTask -TaskName '{escaped}' -ErrorAction Stop",
            ],
            capture_output=True, text=True, timeout=20, check=False,
        )
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or f"PowerShell exit {result.returncode}").strip())


class HealthAgent:
    def __init__(self, root: Path, openclaw_host: str, openclaw_port: int, error_fresh_seconds: int = 600,
                 api_stale_seconds: int = 300, auto_recovery: bool = True, recovery_cooldown_seconds: int = 300):
        self.root = root
        self.openclaw_host = openclaw_host
        self.openclaw_port = openclaw_port
        self.error_fresh_seconds = error_fresh_seconds
        self.api_stale_seconds = api_stale_seconds
        self.auto_recovery = auto_recovery
        self.recovery_cooldown_seconds = recovery_cooldown_seconds
        self.recovery_attempted_at: dict[str, float] = {}
        self.task_reader = WindowsTaskReader()
        self.definitions = [
            ServiceDefinition("zalo-collector", "Zalo Collector", "ZALO_COLLECTOR", "MMTB-ZaloCollector", root / "collector/data/collector.log"),
            ServiceDefinition("ocr-worker", "OCR Worker", "OCR_WORKER", "MMTB-RapidOCRWorker", root / "ocr-worker/data/worker.log"),
            ServiceDefinition("journal-worker", "Journal Worker", "JOURNAL_WORKER", "MMTB-JournalWorker", root / "journal-worker/data/worker.log"),
            ServiceDefinition("reconciliation-worker", "Reconciliation Worker", "RECONCILIATION_WORKER", "MMTB-OpenClawReconciliationWorker", root / "reconciliation-worker/data/worker.log"),
            ServiceDefinition("openclaw-gateway", "OpenClaw Gateway", "OPENCLAW_GATEWAY", None, None),
        ]

    def snapshot(self) -> list[dict[str, Any]]:
        task_names = [definition.task_name for definition in self.definitions if definition.task_name]
        tasks = self.task_reader.read(task_names)
        self._recover_ready_tasks(tasks)
        services = []
        for definition in self.definitions:
            if definition.service_type == "OPENCLAW_GATEWAY":
                services.append(self._openclaw_snapshot(definition))
            else:
                services.append(self._task_snapshot(definition, tasks.get(definition.task_name or "")))
        return services

    def _recover_ready_tasks(self, tasks: dict[str, dict[str, Any]]) -> None:
        if not self.auto_recovery:
            return
        now = time.monotonic()
        for definition in self.definitions:
            task_name = definition.task_name
            task = tasks.get(task_name or "")
            if not task_name or str((task or {}).get("state", "")).upper() != "READY":
                continue
            last_attempt = self.recovery_attempted_at.get(task_name, 0.0)
            if now - last_attempt < self.recovery_cooldown_seconds:
                continue
            self.recovery_attempted_at[task_name] = now
            try:
                self.task_reader.start(task_name)
                logging.warning("Auto-recovery started Scheduled Task %s.", task_name)
            except Exception:
                logging.exception("Auto-recovery failed for Scheduled Task %s.", task_name)

    def _task_snapshot(self, definition: ServiceDefinition, task: dict[str, Any] | None) -> dict[str, Any]:
        log = LogSnapshot(definition.log_path)
        health = read_health_file(definition.log_path)
        state = str(task.get("state", "MISSING")).upper() if task else "MISSING"
        status = "HEALTHY" if state == "RUNNING" else "PAUSED" if state == "DISABLED" else "DEGRADED"
        error = log.last_error()
        if state == "MISSING":
            error = f"Không tìm thấy Scheduled Task {definition.task_name}."
        elif state not in {"RUNNING", "DISABLED"}:
            error = f"Scheduled Task {definition.task_name} đang ở trạng thái {state}." + (f" {error}" if error else "")
        errors = max(log.consecutive_errors(self.error_fresh_seconds), 1 if status == "DEGRADED" else 0)
        api_age = iso_age_seconds(health.get("last_api_success_at"))
        current_job = health.get("current_job") if "current_job" in health else log.current_job()
        if status == "HEALTHY" and not current_job and api_age is not None and api_age > self.api_stale_seconds:
            status = "DEGRADED"; errors = max(errors, 3)
            error = f"Worker không xác nhận API/loop thành công trong {int(api_age // 60)} phút."
        if status == "HEALTHY" and errors == 0:
            error = None
        return {
            "service_key": definition.service_key, "name": definition.name,
            "service_type": definition.service_type, "status": status,
            "current_job": current_job,
            "current_job_started_at": health.get("current_job_started_at"),
            "consecutive_errors": errors,
            "last_success_at": health.get("last_api_success_at") or log.last_success_at(),
            "last_api_success_at": health.get("last_api_success_at"),
            "last_job_success_at": health.get("last_job_success_at"),
            "error_code": None if status == "HEALTHY" else f"TASK_{state}",
            "error_message": error, "metrics": {
                "task_state": state,
                "last_task_result": task.get("last_result") if task else None,
                "latest_error_at": log.latest_error_at().astimezone(timezone.utc).isoformat() if log.latest_error_at() else None,
                "api_age_seconds": api_age,
            },
        }

    def execute_command(self, command: dict[str, Any]) -> dict[str, Any]:
        service_key = command["service"]["service_key"]
        definition = next((item for item in self.definitions if item.service_key == service_key), None)
        if definition is None:
            raise RuntimeError(f"Dịch vụ không thuộc allowlist: {service_key}")
        action = command["action"]
        if action == "HEALTH_CHECK":
            service = next(item for item in self.snapshot() if item["service_key"] == service_key)
            return {"message": f"Health check: {service['status']}", "service": service}
        task_name = definition.task_name or "OpenClaw Gateway"
        escaped = task_name.replace("'", "''")
        script = match_action(action, escaped, self.openclaw_port if definition.service_type == "OPENCLAW_GATEWAY" else None)
        result = subprocess.run(
            ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
            capture_output=True, text=True, timeout=45, check=False,
        )
        if result.returncode != 0:
            raise RuntimeError((result.stderr or result.stdout or f"PowerShell exit {result.returncode}").strip())
        return {"message": f"{action} đã thực hiện cho {definition.name}.", "task_name": task_name}

    def _openclaw_snapshot(self, definition: ServiceDefinition) -> dict[str, Any]:
        try:
            with socket.create_connection((self.openclaw_host, self.openclaw_port), timeout=3):
                healthy, output = True, ""
        except OSError as exc:
            output, healthy = str(exc), False
        return {
            "service_key": definition.service_key, "name": definition.name,
            "service_type": definition.service_type, "status": "HEALTHY" if healthy else "DEGRADED",
            "consecutive_errors": 0 if healthy else 1,
            "last_success_at": datetime.now(timezone.utc).isoformat() if healthy else None,
            "error_code": None if healthy else "GATEWAY_UNAVAILABLE",
            "error_message": None if healthy else output[-1800:],
            "metrics": {"host": self.openclaw_host, "port": self.openclaw_port, "tcp_reachable": healthy},
        }


def post_heartbeat(url: str, token: str, payload: dict[str, Any], timeout: int = 20) -> dict[str, Any]:
    return post_json(url, token, payload, timeout)


def post_json(url: str, token: str, payload: dict[str, Any], timeout: int = 20) -> dict[str, Any]:
    request = urllib.request.Request(
        url, data=json.dumps(payload).encode("utf-8"), method="POST",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json", "User-Agent": f"mmtb-health-agent/{AGENT_VERSION}"},
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def match_action(action: str, task_name: str, gateway_port: int | None = None) -> str:
    stop_gateway = ""
    if gateway_port is not None:
        stop_gateway = f"$pids=Get-NetTCPConnection -LocalPort {gateway_port} -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique; $pids | ForEach-Object {{ Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }}; "
    if action == "PAUSE":
        return stop_gateway + f"Stop-ScheduledTask -TaskName '{task_name}' -ErrorAction SilentlyContinue; Disable-ScheduledTask -TaskName '{task_name}' -ErrorAction Stop | Out-Null"
    if action == "RETRY":
        return f"Enable-ScheduledTask -TaskName '{task_name}' -ErrorAction Stop | Out-Null; Start-ScheduledTask -TaskName '{task_name}' -ErrorAction Stop"
    if action == "RESTART":
        return stop_gateway + f"Enable-ScheduledTask -TaskName '{task_name}' -ErrorAction Stop | Out-Null; Stop-ScheduledTask -TaskName '{task_name}' -ErrorAction SilentlyContinue; Start-Sleep -Seconds 2; Start-ScheduledTask -TaskName '{task_name}' -ErrorAction Stop"
    raise RuntimeError(f"Lệnh không thuộc allowlist: {action}")


def process_commands(base_url: str, token: str, agent: HealthAgent) -> int:
    response = post_json(f"{base_url}/commands/claim", token, {"agent_id": os.environ.get("COMPUTERNAME", "health-agent"), "limit": 5})
    completed = 0
    for command in response.get("commands", []):
        try:
            result = agent.execute_command(command)
            post_json(f"{base_url}/commands/{command['id']}/complete", token, {"result": result})
            completed += 1
        except Exception as exc:
            post_json(f"{base_url}/commands/{command['id']}/fail", token, {"error": str(exc)[:2000]})
            logging.exception("Command %s failed", command.get("id"))
    return completed


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
    agent = HealthAgent(
        root,
        os.environ.get("OPENCLAW_GATEWAY_HOST", "127.0.0.1"),
        int(os.environ.get("OPENCLAW_GATEWAY_PORT", "18789")),
        max(60, int(os.environ.get("AUTOMATION_LOG_ERROR_FRESH_SECONDS", "600"))),
        max(60, int(os.environ.get("AUTOMATION_API_STALE_SECONDS", "300"))),
        os.environ.get("AUTOMATION_AUTO_RECOVERY_ENABLED", "true").lower() in {"1", "true", "yes", "on"},
        max(60, int(os.environ.get("AUTOMATION_RECOVERY_COOLDOWN_SECONDS", "300"))),
    )
    logging.info("MMTB Automation Health Agent started; root=%s", root)
    command_base_url = url.rsplit("/heartbeat", 1)[0]
    while True:
        payload = {
            "agent_version": AGENT_VERSION,
            "metadata": {"computer_name": os.environ.get("COMPUTERNAME"), "platform": sys.platform},
            "services": agent.snapshot(),
        }
        try:
            response = post_heartbeat(url, token, payload)
            logging.info("Heartbeat sent: %s service(s).", len(response.get("services", [])))
            completed = process_commands(command_base_url, token, agent)
            if completed:
                logging.info("Completed %s remote command(s).", completed)
        except Exception as exc:
            logging.warning("Heartbeat failed: %s", exc)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
