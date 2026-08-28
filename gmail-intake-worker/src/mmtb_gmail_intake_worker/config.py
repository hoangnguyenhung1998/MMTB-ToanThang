from __future__ import annotations
import os
from dataclasses import dataclass
from pathlib import Path

def load_env(path: Path) -> None:
    if not path.exists(): return
    for raw in path.read_text(encoding="utf-8-sig").splitlines():
        line=raw.strip()
        if not line or line.startswith("#") or "=" not in line: continue
        key,value=line.split("=",1); os.environ.setdefault(key.strip(),value.strip().strip('"').strip("'"))

@dataclass(frozen=True)
class Settings:
    root: Path
    api_url: str
    api_token: str
    poll_seconds: int
    lookback_days: int
    gmail_query: str
    vision_api_base_url: str
    vision_api_key: str
    vision_model: str
    vision_timeout_seconds: int

    @classmethod
    def load(cls, root: Path) -> "Settings":
        load_env(root / ".env")
        api_url=os.environ.get("LARAVEL_GMAIL_INTAKE_API_URL","").strip().rstrip("/")
        api_token=os.environ.get("GMAIL_INTAKE_WORKER_TOKEN","").strip()
        if not api_url or not api_token: raise ValueError("Missing LARAVEL_GMAIL_INTAKE_API_URL or GMAIL_INTAKE_WORKER_TOKEN")
        return cls(root,api_url,api_token,int(os.environ.get("GMAIL_POLL_SECONDS","60")),int(os.environ.get("GMAIL_LOOKBACK_DAYS","30")),os.environ.get("GMAIL_QUERY","").strip(),os.environ.get("GMAIL_VISION_API_BASE_URL","").strip().rstrip("/"),os.environ.get("GMAIL_VISION_API_KEY","").strip(),os.environ.get("GMAIL_VISION_MODEL","").strip(),int(os.environ.get("GMAIL_VISION_TIMEOUT_SECONDS","180")))
