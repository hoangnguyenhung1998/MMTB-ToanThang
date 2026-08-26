from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path


class WorkerHealth:
    def __init__(self, path: Path):
        self.path = path
        self.state: dict = {}
        if path.exists():
            try: self.state = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, ValueError): pass

    def api_success(self) -> None: self._write(last_api_success_at=self._now())
    def job_started(self, job_id: object) -> None: self._write(current_job=f"job-{job_id}", current_job_started_at=self._now())
    def job_succeeded(self) -> None: self._write(last_job_success_at=self._now(), current_job=None, current_job_started_at=None)
    def job_finished(self) -> None: self._write(current_job=None, current_job_started_at=None)
    def _write(self, **values) -> None:
        self.state.update(values); self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_suffix('.tmp'); temporary.write_text(json.dumps(self.state, ensure_ascii=False), encoding='utf-8'); temporary.replace(self.path)
    @staticmethod
    def _now() -> str: return datetime.now(timezone.utc).isoformat()
