from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def load_env_file(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def _positive_int(name: str, default: int) -> int:
    value = int(os.environ.get(name, str(default)))
    if value <= 0:
        raise ValueError(f"{name} must be greater than zero.")
    return value


@dataclass(frozen=True)
class Settings:
    laravel_api_url: str
    laravel_api_token: str
    worker_id: str
    openclaw_command: str
    openclaw_agent_id: str | None
    openclaw_session_key: str
    openclaw_model: str | None
    openclaw_thinking: str
    openclaw_timeout_seconds: int
    lookback_days: int
    claim_limit: int
    poll_seconds: int
    request_timeout_seconds: int
    data_dir: Path
    openclaw_workspace_dir: Path

    @classmethod
    def from_environment(cls, root: Path | None = None) -> "Settings":
        root = (root or Path.cwd()).resolve()
        load_env_file(root / ".env")
        api_url = os.environ.get("LARAVEL_OPENCLAW_API_URL", "").strip().rstrip("/")
        token = os.environ.get("OPENCLAW_AGENT_API_TOKEN", "").strip()
        worker_id = os.environ.get("RECONCILIATION_WORKER_ID", "openclaw-reconciliation-home-1").strip()
        if not api_url or not token or not worker_id:
            raise ValueError("Missing required configuration: Laravel URL, token, or worker ID.")

        workspace_value = os.environ.get("OPENCLAW_WORKSPACE_DIR", "").strip()
        workspace_dir = (Path(workspace_value).expanduser() if workspace_value else
                         Path.home() / ".openclaw" / "workspace" / "mmtb-reconciliation")

        return cls(
            laravel_api_url=api_url,
            laravel_api_token=token,
            worker_id=worker_id,
            openclaw_command=os.environ.get("OPENCLAW_COMMAND", "openclaw").strip(),
            openclaw_agent_id=os.environ.get("OPENCLAW_AGENT_ID", "").strip() or None,
            openclaw_session_key=os.environ.get("OPENCLAW_SESSION_KEY", "mmtb-reconciliation").strip(),
            openclaw_model=os.environ.get("OPENCLAW_MODEL", "").strip() or None,
            openclaw_thinking=os.environ.get("OPENCLAW_THINKING", "medium").strip(),
            openclaw_timeout_seconds=_positive_int("OPENCLAW_TIMEOUT_SECONDS", 600),
            lookback_days=_positive_int("RECONCILIATION_LOOKBACK_DAYS", 14),
            claim_limit=_positive_int("RECONCILIATION_CLAIM_LIMIT", 5),
            poll_seconds=_positive_int("RECONCILIATION_POLL_SECONDS", 30),
            request_timeout_seconds=_positive_int("RECONCILIATION_REQUEST_TIMEOUT_SECONDS", 60),
            data_dir=root / "data",
            openclaw_workspace_dir=workspace_dir.resolve(),
        )
