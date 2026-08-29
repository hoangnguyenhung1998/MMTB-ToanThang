from __future__ import annotations

import logging
import time
from datetime import datetime
from logging.handlers import TimedRotatingFileHandler
from pathlib import Path

from .config import Settings
from .laravel_client import LaravelJournalClient, WorkerApiError
from .process_lock import ProcessLock
from .vision_client import JournalVisionClient, VisionError
from .health import WorkerHealth
from .intake_vision_client import MachineIntakeVisionClient
from .handover_vision_client import MachineHandoverVisionClient


LOGGER = logging.getLogger("mmtb_journal_worker")


def retry_delay(error: WorkerApiError, poll_seconds: int, failure_streak: int) -> float:
    exponential = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    return max(error.retry_after_seconds or 0.0, exponential)


def recovery_delay(poll_seconds: int, failure_streak: int, worker_id: str) -> float:
    base = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    jitter = (sum(ord(character) for character in worker_id) + failure_streak) % 4
    return base + float(jitter)


class JournalWorker:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.laravel = LaravelJournalClient(
            api_url=settings.laravel_api_url,
            token=settings.laravel_api_token,
            worker_id=settings.worker_id,
            timeout_seconds=settings.request_timeout_seconds,
            temp_dir=settings.data_dir / "tmp",
        )
        self.vision = JournalVisionClient(
            api_base_url=settings.vision_api_base_url,
            api_key=settings.vision_api_key,
            model=settings.vision_model,
            timeout_seconds=settings.vision_timeout_seconds,
        )
        self.intake_vision = MachineIntakeVisionClient(settings.vision_api_base_url, settings.vision_api_key, settings.vision_model, settings.vision_timeout_seconds)
        self.handover_vision = MachineHandoverVisionClient(settings.vision_api_base_url, settings.vision_api_key, settings.vision_model, settings.vision_timeout_seconds)
        self.machine_codes: list[str] = []
        self.machine_catalog_loaded_at = 0.0
        self.health = WorkerHealth(settings.data_dir / "health.json")
        self.health.job_finished()

    def step(self) -> bool:
        self._refresh_machine_catalog()
        job = self.laravel.claim()
        if job is None:
            job = self.laravel.claim_handover()
            if job is not None: return self._process_handover(job)
            job = self.laravel.claim_intake()
            if job is not None: return self._process_intake(job)
            self.health.api_success(); return False

        self.health.api_success()
        self.health.job_started(job["id"])

        image_path: Path | None = None
        try:
            image_path = self.laravel.download_image(job["image_url"], int(job["id"]))
            extraction = self.vision.extract(image_path, self.machine_codes)
            extraction.enforce_machine_catalog(self.machine_codes)
            sent_at = job.get("message", {}).get("sent_at")
            reference_year = datetime.fromisoformat(sent_at.replace("Z", "+00:00")).year if sent_at else None
            extraction.normalize(reference_year=reference_year)
            saved = self.laravel.complete_journal(job["id"], extraction.api_payload())
            self.health.job_succeeded()
            LOGGER.info(
                "Completed journal OCR job %s: machine=%s rows=%d confidence=%.2f status=%s",
                job["id"],
                extraction.asset_code or "?",
                len(extraction.rows),
                extraction.confidence,
                saved["status"],
            )
        except WorkerApiError:
            raise
        except VisionError as exc:
            LOGGER.warning("Journal OCR job %s failed: %s", job["id"], exc)
            self._report_failure(job, str(exc), retryable=exc.retryable)
        except Exception as exc:
            attempts = int(job.get("attempts", 1))
            LOGGER.exception("Journal OCR job %s failed locally", job["id"])
            self._report_failure(job, str(exc), retryable=attempts < 3)
        finally:
            self.health.job_finished()
            if image_path is not None:
                image_path.unlink(missing_ok=True)
        return True

    def _process_intake(self, job: dict) -> bool:
        self.health.api_success(); self.health.job_started(job["id"]); image_path: Path | None = None
        try:
            image_path=self.laravel.download_image(job["image_url"],int(job["id"])); extraction=self.intake_vision.extract(image_path,job["document_type"])
            saved=self.laravel.complete_intake(job["id"],extraction.api_payload()); self.health.job_succeeded()
            LOGGER.info("Completed machine intake OCR job %s: case=%s confidence=%.2f status=%s",job["id"],job.get("case",{}).get("reference","?"),extraction.confidence,saved["status"])
        except WorkerApiError: raise
        except VisionError as exc:
            LOGGER.warning("Machine intake OCR job %s failed: %s",job["id"],exc); self._report_intake_failure(job,str(exc),exc.retryable)
        except Exception as exc:
            LOGGER.exception("Machine intake OCR job %s failed locally",job["id"]); self._report_intake_failure(job,str(exc),int(job.get("attempts",1))<3)
        finally:
            self.health.job_finished()
            if image_path is not None: image_path.unlink(missing_ok=True)
        return True

    def _report_intake_failure(self, job: dict, error: str, retryable: bool) -> None:
        try: self.laravel.fail_intake(job["id"],error,retryable and int(job.get("attempts",1))<3)
        except WorkerApiError as report_error: LOGGER.error("Could not report failure for machine intake job %s: %s",job["id"],report_error)

    def _process_handover(self, job: dict) -> bool:
        self.health.api_success(); self.health.job_started(job["id"]); image_path: Path | None = None
        try:
            image_path=self.laravel.download_image(job["image_url"],int(job["id"])); extraction=self.handover_vision.extract(image_path)
            saved=self.laravel.complete_handover(job["id"],extraction.api_payload()); self.health.job_succeeded()
            LOGGER.info("Completed handover OCR job %s: machine=%s confidence=%.2f status=%s",job["id"],job.get("machine",{}).get("asset_code","?"),extraction.confidence,saved["status"])
        except WorkerApiError: raise
        except VisionError as exc:
            LOGGER.warning("Handover OCR job %s failed: %s",job["id"],exc); self._report_handover_failure(job,str(exc),exc.retryable)
        except Exception as exc:
            LOGGER.exception("Handover OCR job %s failed locally",job["id"]); self._report_handover_failure(job,str(exc),int(job.get("attempts",1))<3)
        finally:
            self.health.job_finished()
            if image_path is not None: image_path.unlink(missing_ok=True)
        return True

    def _report_handover_failure(self, job: dict, error: str, retryable: bool) -> None:
        try: self.laravel.fail_handover(job["id"],error,retryable and int(job.get("attempts",1))<3)
        except WorkerApiError as report_error: LOGGER.error("Could not report failure for handover job %s: %s",job["id"],report_error)

    def _refresh_machine_catalog(self) -> None:
        now = time.monotonic()
        if self.machine_codes and now - self.machine_catalog_loaded_at < self.settings.machine_refresh_seconds:
            return
        machines = self.laravel.machines()
        codes = [str(machine["asset_code"]) for machine in machines if machine.get("asset_code")]
        if not codes:
            raise WorkerApiError("Laravel returned an empty machine catalog.", retryable=True)
        self.machine_codes = codes
        self.machine_catalog_loaded_at = now
        LOGGER.info("Loaded %d machine code(s) from Laravel", len(codes))

    def _report_failure(self, job: dict, error: str, retryable: bool) -> None:
        attempts = int(job.get("attempts", 1))
        should_retry = retryable and attempts < 3
        try:
            self.laravel.fail(job["id"], error, should_retry)
        except WorkerApiError as report_error:
            LOGGER.error("Could not report failure for journal job %s: %s", job["id"], report_error)


def configure_logging(data_dir: Path) -> None:
    data_dir.mkdir(parents=True, exist_ok=True)
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    file_handler = TimedRotatingFileHandler(
        data_dir / "worker.log",
        when="midnight",
        backupCount=14,
        encoding="utf-8",
    )
    file_handler.setFormatter(formatter)
    console_handler = logging.StreamHandler()
    console_handler.setFormatter(formatter)
    logging.basicConfig(level=logging.INFO, handlers=[file_handler, console_handler])
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)


def run() -> None:
    root = Path(__file__).resolve().parents[2]
    settings = Settings.from_environment(root)
    configure_logging(settings.data_dir)

    with ProcessLock(settings.data_dir / "worker.lock"):
        worker = JournalWorker(settings)
        failure_streak = 0
        LOGGER.info("MMTB journal worker started as %s", settings.worker_id)
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
                LOGGER.info("MMTB journal worker stopped")
                return
