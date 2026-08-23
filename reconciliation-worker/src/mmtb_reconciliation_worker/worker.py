from __future__ import annotations

import logging
import time
import uuid
from datetime import date, timedelta
from logging.handlers import TimedRotatingFileHandler
from pathlib import Path

from .config import Settings
from .laravel_client import LaravelReconciliationClient, WorkerApiError
from .openclaw_client import OpenClawClient, OpenClawError
from .process_lock import ProcessLock


LOGGER = logging.getLogger("mmtb_reconciliation_worker")


class ReconciliationWorker:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.laravel = LaravelReconciliationClient(
            settings.laravel_api_url, settings.laravel_api_token, settings.worker_id,
            settings.request_timeout_seconds, settings.openclaw_workspace_dir / "evidence",
        )
        self.openclaw = OpenClawClient(
            settings.openclaw_command, settings.openclaw_session_key,
            settings.openclaw_timeout_seconds, settings.openclaw_agent_id,
            settings.openclaw_model, settings.openclaw_thinking,
        )

    def step(self) -> bool:
        processed = False
        today = date.today()
        for days_ago in range(self.settings.lookback_days - 1, -1, -1):
            work_date = (today - timedelta(days=days_ago)).isoformat()
            jobs = self.laravel.claim(work_date, self.settings.claim_limit)
            for job in jobs:
                processed = True
                self._process(job)
        return processed

    def _process(self, job: dict) -> None:
        image_paths: dict[int, Path] = {}
        try:
            for image in job.get("source_images", []):
                ocr_job_id = int(image["ocr_job_id"])
                image_paths[ocr_job_id] = self.laravel.download_image(
                    image["image_url"], int(job["id"]), ocr_job_id,
                )
            result = self.openclaw.reconcile(job, image_paths)
            submission = self.laravel.complete(int(job["id"]), {
                "submission_uuid": str(uuid.uuid4()),
                "agent_name": self.settings.worker_id,
                "model": self.settings.openclaw_model or "openclaw-default",
                "metadata": {"source": "openclaw-cli", "image_count": len(image_paths)},
                **result.api_payload(),
            })
            LOGGER.info("Completed reconciliation job %s: outcome=%s findings=%d",
                        job["id"], submission["outcome"], len(submission.get("findings", [])))
        except (WorkerApiError, OpenClawError) as exc:
            LOGGER.warning("Reconciliation job %s failed: %s", job.get("id"), exc)
            self._report_failure(job, str(exc), exc.retryable)
        except Exception as exc:
            LOGGER.exception("Reconciliation job %s failed locally", job.get("id"))
            self._report_failure(job, str(exc), int(job.get("attempts", 1)) < 3)
        finally:
            for path in image_paths.values():
                path.unlink(missing_ok=True)

    def _report_failure(self, job: dict, error: str, retryable: bool) -> None:
        should_retry = retryable and int(job.get("attempts", 1)) < 3
        try:
            self.laravel.fail(int(job["id"]), error, should_retry)
        except WorkerApiError as exc:
            LOGGER.error("Could not report failure for reconciliation job %s: %s", job.get("id"), exc)


def configure_logging(data_dir: Path) -> None:
    data_dir.mkdir(parents=True, exist_ok=True)
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    file_handler = TimedRotatingFileHandler(data_dir / "worker.log", when="midnight",
                                             backupCount=14, encoding="utf-8")
    file_handler.setFormatter(formatter)
    console_handler = logging.StreamHandler()
    console_handler.setFormatter(formatter)
    logging.basicConfig(level=logging.INFO, handlers=[file_handler, console_handler])


def run() -> None:
    root = Path(__file__).resolve().parents[2]
    settings = Settings.from_environment(root)
    configure_logging(settings.data_dir)
    with ProcessLock(settings.data_dir / "worker.lock"):
        worker = ReconciliationWorker(settings)
        LOGGER.info("MMTB OpenClaw reconciliation worker started as %s", settings.worker_id)
        while True:
            try:
                if not worker.step():
                    time.sleep(settings.poll_seconds)
            except WorkerApiError as exc:
                LOGGER.warning("%s", exc)
                time.sleep(settings.poll_seconds)
            except KeyboardInterrupt:
                LOGGER.info("MMTB OpenClaw reconciliation worker stopped")
                return
