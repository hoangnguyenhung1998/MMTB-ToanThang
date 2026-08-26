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
from .health import WorkerHealth


LOGGER = logging.getLogger("mmtb_reconciliation_worker")


def retry_delay(error: WorkerApiError, poll_seconds: int, failure_streak: int) -> float:
    exponential = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    return max(error.retry_after_seconds or 0.0, exponential)


def recovery_delay(poll_seconds: int, failure_streak: int, worker_id: str) -> float:
    base = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    jitter = (sum(ord(character) for character in worker_id) + failure_streak) % 4
    return base + float(jitter)


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
        self.health = WorkerHealth(settings.data_dir / "health.json")
        self.health.job_finished()

    def step(self) -> bool:
        processed = False
        commands = self.laravel.claim_commands(min(self.settings.claim_limit, 3))
        self.health.api_success()
        for command in commands:
            processed = True
            self._process_command(command)

        today = date.today()
        for days_ago in range(self.settings.lookback_days - 1, -1, -1):
            work_date = (today - timedelta(days=days_ago)).isoformat()
            jobs = self.laravel.claim(work_date, self.settings.claim_limit)
            self.health.api_success()
            for job in jobs:
                processed = True
                self._process(job)
            if days_ago > 0:
                time.sleep(1.0)
        return processed

    def _process_command(self, command: dict) -> None:
        image_paths: dict[int, Path] = {}
        job = command.get("reconciliation_job", {})
        self.health.job_started(command.get("id"))
        try:
            for image in job.get("source_images", []):
                ocr_job_id = int(image["ocr_job_id"])
                image_paths[ocr_job_id] = self.laravel.download_image(
                    image["image_url"], int(job["id"]), ocr_job_id,
                )
            result = self.openclaw.execute_command(command, image_paths)
            self.laravel.complete_command(int(command["id"]), result.api_payload())
            self.health.job_succeeded()
            LOGGER.info("Completed OpenClaw command %s: action=%s",
                        command["id"], command.get("action"))
        except WorkerApiError:
            raise
        except OpenClawError as exc:
            LOGGER.warning("OpenClaw command %s failed: %s", command.get("id"), exc)
            self._report_command_failure(command, str(exc), exc.retryable)
        except Exception as exc:
            LOGGER.exception("OpenClaw command %s failed locally", command.get("id"))
            self._report_command_failure(command, str(exc), int(command.get("attempts", 1)) < 3)
        finally:
            self.health.job_finished()
            for path in image_paths.values():
                path.unlink(missing_ok=True)

    def _report_command_failure(self, command: dict, error: str, retryable: bool) -> None:
        should_retry = retryable and int(command.get("attempts", 1)) < 3
        try:
            self.laravel.fail_command(int(command["id"]), error, should_retry)
        except WorkerApiError as exc:
            LOGGER.error("Could not report failure for OpenClaw command %s: %s", command.get("id"), exc)

    def _process(self, job: dict) -> None:
        image_paths: dict[int, Path] = {}
        self.health.job_started(job.get("id"))
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
            self.health.job_succeeded()
            LOGGER.info("Completed reconciliation job %s: outcome=%s findings=%d",
                        job["id"], submission["outcome"], len(submission.get("findings", [])))
        except WorkerApiError:
            raise
        except OpenClawError as exc:
            LOGGER.warning("Reconciliation job %s failed: %s", job.get("id"), exc)
            self._report_failure(job, str(exc), exc.retryable)
        except Exception as exc:
            LOGGER.exception("Reconciliation job %s failed locally", job.get("id"))
            self._report_failure(job, str(exc), int(job.get("attempts", 1)) < 3)
        finally:
            self.health.job_finished()
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
        failure_streak = 0
        LOGGER.info("MMTB OpenClaw reconciliation worker started as %s", settings.worker_id)
        while True:
            try:
                processed = worker.step()
                failure_streak = 0
                if not processed:
                    time.sleep(settings.poll_seconds)
            except WorkerApiError as exc:
                failure_streak += 1
                delay = retry_delay(exc, settings.poll_seconds, failure_streak)
                LOGGER.warning("%s Retrying in %.0f second(s).", exc, delay)
                time.sleep(delay)
            except Exception:
                failure_streak += 1
                delay = recovery_delay(settings.poll_seconds, failure_streak, settings.worker_id)
                LOGGER.exception("Unexpected worker loop failure; recovering in %.0f second(s).", delay)
                time.sleep(delay)
            except KeyboardInterrupt:
                LOGGER.info("MMTB OpenClaw reconciliation worker stopped")
                return
