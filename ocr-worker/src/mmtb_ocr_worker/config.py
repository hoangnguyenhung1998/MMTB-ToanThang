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


def _nonnegative_float(name: str, default: float) -> float:
    value = float(os.environ.get(name, str(default)))
    if value < 0:
        raise ValueError(f"{name} must be zero or greater.")
    return value


def _confidence(name: str, default: float) -> float:
    value = float(os.environ.get(name, str(default)))
    if not 0 <= value <= 1:
        raise ValueError(f"{name} must be between 0 and 1.")
    return value


@dataclass(frozen=True)
class Settings:
    api_url: str
    api_token: str
    worker_id: str
    poll_seconds: int = 10
    request_timeout_seconds: int = 30
    classification_min_confidence: float = 0.70
    machine_refresh_seconds: int = 3600
    delay_between_jobs_seconds: float = 3.0
    batch_size: int = 10
    batch_cooldown_seconds: float = 60.0
    data_dir: Path = Path("data")

    @classmethod
    def from_environment(cls, root: Path | None = None) -> "Settings":
        root = (root or Path.cwd()).resolve()
        load_env_file(root / ".env")

        api_url = os.environ.get("LARAVEL_OCR_API_URL", "").strip().rstrip("/")
        api_token = os.environ.get("OCR_WORKER_API_TOKEN", "").strip()
        worker_id = os.environ.get("OCR_WORKER_ID", "rapid-ocr-home-1").strip()
        if not api_url:
            raise ValueError("LARAVEL_OCR_API_URL is required.")
        if not api_token:
            raise ValueError("OCR_WORKER_API_TOKEN is required.")
        if not worker_id:
            raise ValueError("OCR_WORKER_ID is required.")

        return cls(
            api_url=api_url,
            api_token=api_token,
            worker_id=worker_id,
            poll_seconds=_positive_int("OCR_POLL_SECONDS", 10),
            request_timeout_seconds=_positive_int("OCR_REQUEST_TIMEOUT_SECONDS", 30),
            classification_min_confidence=_confidence(
                "OCR_CLASSIFICATION_MIN_CONFIDENCE",
                0.70,
            ),
            machine_refresh_seconds=_positive_int("OCR_MACHINE_REFRESH_SECONDS", 3600),
            delay_between_jobs_seconds=_nonnegative_float("OCR_DELAY_BETWEEN_JOBS_SECONDS", 3.0),
            batch_size=_positive_int("OCR_BATCH_SIZE", 10),
            batch_cooldown_seconds=_nonnegative_float("OCR_BATCH_COOLDOWN_SECONDS", 60.0),
            data_dir=root / "data",
        )
