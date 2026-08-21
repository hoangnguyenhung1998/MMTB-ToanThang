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
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key:
            os.environ.setdefault(key, value)


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
    vision_api_base_url: str
    vision_api_key: str
    vision_model: str
    poll_seconds: int = 15
    request_timeout_seconds: int = 30
    vision_timeout_seconds: int = 180
    machine_refresh_seconds: int = 3600
    data_dir: Path = Path("data")

    @classmethod
    def from_environment(cls, root: Path | None = None) -> "Settings":
        root = (root or Path.cwd()).resolve()
        load_env_file(root / ".env")

        values = {
            "laravel_api_url": os.environ.get("LARAVEL_OCR_API_URL", "").strip().rstrip("/"),
            "laravel_api_token": os.environ.get("OCR_WORKER_API_TOKEN", "").strip(),
            "worker_id": os.environ.get("JOURNAL_WORKER_ID", "openclaw-home-1").strip(),
            "vision_api_base_url": os.environ.get("JOURNAL_VISION_API_BASE_URL", "").strip().rstrip("/"),
            "vision_api_key": os.environ.get("JOURNAL_VISION_API_KEY", "").strip(),
            "vision_model": os.environ.get("JOURNAL_VISION_MODEL", "").strip(),
        }
        missing = [name for name, value in values.items() if not value]
        if missing:
            raise ValueError(f"Missing required configuration: {', '.join(missing)}")

        return cls(
            **values,
            poll_seconds=_positive_int("JOURNAL_POLL_SECONDS", 15),
            request_timeout_seconds=_positive_int("JOURNAL_REQUEST_TIMEOUT_SECONDS", 30),
            vision_timeout_seconds=_positive_int("JOURNAL_VISION_TIMEOUT_SECONDS", 180),
            machine_refresh_seconds=_positive_int("JOURNAL_MACHINE_REFRESH_SECONDS", 3600),
            data_dir=root / "data",
        )
