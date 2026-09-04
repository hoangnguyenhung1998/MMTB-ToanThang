from __future__ import annotations

from .config import Settings


def processing_delay(settings: Settings, processed_jobs: int) -> float:
    if processed_jobs > 0 and processed_jobs % settings.batch_size == 0:
        return settings.batch_cooldown_seconds
    return settings.delay_between_jobs_seconds
