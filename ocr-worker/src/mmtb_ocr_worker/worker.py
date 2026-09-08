from __future__ import annotations

import logging
import time
from logging.handlers import TimedRotatingFileHandler
from pathlib import Path

from rapidocr import RapidOCR

from .api_client import LaravelOcrClient, WorkerApiError
from .classifier import DocumentClassifier
from .config import Settings
from .process_lock import ProcessLock
from .timemark import TimeMarkRecognizer
from .health import WorkerHealth
from .lease import LeaseHeartbeat, LeaseOwnershipLost
from .load_control import processing_delay


LOGGER = logging.getLogger("mmtb_ocr_worker")


class ProcessingBudgetExceeded(RuntimeError):
    pass


def retry_delay(error: WorkerApiError, poll_seconds: int, failure_streak: int) -> float:
    exponential = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    return max(error.retry_after_seconds or 0.0, exponential)


def recovery_delay(poll_seconds: int, failure_streak: int, worker_id: str) -> float:
    base = min(max(10.0, float(poll_seconds)) * (2 ** min(failure_streak - 1, 4)), 120.0)
    jitter = (sum(ord(character) for character in worker_id) + failure_streak) % 4
    return base + float(jitter)


class OcrWorker:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.client = LaravelOcrClient(
            api_url=settings.api_url,
            token=settings.api_token,
            worker_id=settings.worker_id,
            timeout_seconds=settings.request_timeout_seconds,
            temp_dir=settings.data_dir / "tmp",
        )
        self.engine = RapidOCR()
        self.classifier = DocumentClassifier(settings.classification_min_confidence, self.engine)
        self.recognizer: TimeMarkRecognizer | None = None
        self.machine_catalog_loaded_at = 0.0
        self.health = WorkerHealth(settings.data_dir / "health.json")
        self.health.job_finished()

    def step(self) -> bool:
        self._refresh_machine_catalog()
        job = self.client.claim(["DAILY_TIMEMARK"])
        if job is None:
            job = self.client.claim(["UNKNOWN"])
        if job is None:
            self.health.api_success()
            return False

        self.health.api_success()
        self.health.job_started(job["id"])

        job_id = int(job["id"])
        attempt = int(job.get("attempt", job.get("attempts", 1)))
        started_at = time.monotonic()
        heartbeat = LeaseHeartbeat(
            self.client,
            job_id=job_id,
            attempt=attempt,
            worker_id=self.settings.worker_id,
            lease_seconds=int(job.get("lease_seconds", 300)),
            interval_seconds=self.settings.lease_renew_interval_seconds,
        )
        heartbeat.start()
        LOGGER.info(
            "OCR processing started job_id=%s attempt=%s worker_id=%s document_type=%s budget_seconds=%s",
            job_id,
            attempt,
            self.settings.worker_id,
            job["document_type"],
            self.settings.processing_budget_seconds,
        )

        image_path: Path | None = None
        try:
            download_started = time.monotonic()
            image_path = self.client.download_image(job["image_url"], job_id)
            LOGGER.info(
                "OCR image downloaded job_id=%s attempt=%s worker_id=%s duration_ms=%s",
                job_id,
                attempt,
                self.settings.worker_id,
                round((time.monotonic() - download_started) * 1000),
            )
            self._ensure_active(heartbeat, started_at, job_id, attempt)
            if job["document_type"] == "UNKNOWN":
                self._classify(job, image_path, attempt, heartbeat, started_at)
            else:
                self._recognize_timemark(job, image_path, attempt, heartbeat, started_at)
        except LeaseOwnershipLost as exc:
            LOGGER.warning(
                "OCR result discarded after ownership loss job_id=%s attempt=%s worker_id=%s error=%s",
                job_id,
                attempt,
                self.settings.worker_id,
                exc,
            )
        except ProcessingBudgetExceeded as exc:
            LOGGER.error(
                "OCR processing budget exceeded job_id=%s attempt=%s worker_id=%s elapsed_seconds=%.1f budget_seconds=%s",
                job_id,
                attempt,
                self.settings.worker_id,
                time.monotonic() - started_at,
                self.settings.processing_budget_seconds,
            )
            self._report_failure(job, attempt, str(exc), heartbeat)
        except WorkerApiError:
            raise
        except Exception as exc:
            LOGGER.exception("OCR job %s attempt %s failed locally", job_id, attempt)
            self._report_failure(job, attempt, str(exc), heartbeat)
        finally:
            heartbeat.stop()
            self.health.job_finished()
            if image_path is not None:
                image_path.unlink(missing_ok=True)
            LOGGER.info(
                "OCR processing ended job_id=%s attempt=%s worker_id=%s elapsed_ms=%s ownership_lost=%s",
                job_id,
                attempt,
                self.settings.worker_id,
                round((time.monotonic() - started_at) * 1000),
                heartbeat.lost,
            )
        return True

    def _classify(
        self,
        job: dict,
        image_path: Path,
        attempt: int,
        heartbeat: LeaseHeartbeat,
        started_at: float,
    ) -> None:
        result = self.classifier.classify(image_path)
        self._ensure_active(heartbeat, started_at, int(job["id"]), attempt)
        heartbeat.prepare_finalization()
        LOGGER.info("OCR classification finalizing job_id=%s attempt=%s worker_id=%s", job["id"], attempt, self.settings.worker_id)
        saved = self.client.classify(job["id"], attempt, result.document_type, result.confidence)
        self.health.job_succeeded()
        LOGGER.info(
            "Classified OCR job %s attempt=%s as %s (%.2f), status=%s",
            job["id"],
            attempt,
            result.document_type,
            result.confidence,
            saved["status"],
        )

    def _recognize_timemark(
        self,
        job: dict,
        image_path: Path,
        attempt: int,
        heartbeat: LeaseHeartbeat,
        started_at: float,
    ) -> None:
        if self.recognizer is None:
            raise RuntimeError("Machine catalog is not loaded.")

        job_id = int(job["id"])

        def progress(event: str, rotation: int, region: str, engine_duration_ms: int | None) -> None:
            elapsed_ms = round((time.monotonic() - started_at) * 1000)
            LOGGER.info(
                "TimeMark engine call job_id=%s attempt=%s worker_id=%s event=%s rotation=%s region=%s "
                "engine_duration_ms=%s total_elapsed_ms=%s",
                job_id,
                attempt,
                self.settings.worker_id,
                event,
                rotation,
                region,
                engine_duration_ms if engine_duration_ms is not None else "pending",
                elapsed_ms,
            )
            self._ensure_active(heartbeat, started_at, job_id, attempt)

        timemark_started = time.monotonic()
        result = self.recognizer.recognize(image_path, progress=progress)
        self._ensure_active(heartbeat, started_at, job_id, attempt)
        LOGGER.info(
            "TimeMark OCR finished job_id=%s attempt=%s worker_id=%s duration_ms=%s",
            job_id,
            attempt,
            self.settings.worker_id,
            round((time.monotonic() - timemark_started) * 1000),
        )
        heartbeat.prepare_finalization()
        LOGGER.info("TimeMark completion requested job_id=%s attempt=%s worker_id=%s", job_id, attempt, self.settings.worker_id)
        saved = self.client.complete_timemark(job_id, attempt, result.api_payload())
        self.health.job_succeeded()
        LOGGER.info(
            "Completed TimeMark OCR job %s attempt=%s: machine=%s date=%s time=%s status=%s",
            job_id,
            attempt,
            result.asset_code or "?",
            result.captured_date or "?",
            result.captured_time or "?",
            saved["status"],
        )

    def _refresh_machine_catalog(self) -> None:
        now = time.monotonic()
        if self.recognizer is not None and now - self.machine_catalog_loaded_at < self.settings.machine_refresh_seconds:
            return
        machines = self.client.machines()
        asset_codes = [str(machine["asset_code"]) for machine in machines if machine.get("asset_code")]
        if not asset_codes:
            raise WorkerApiError("Laravel returned an empty machine catalog.", retryable=True)
        self.recognizer = TimeMarkRecognizer(asset_codes, self.engine)
        self.machine_catalog_loaded_at = now
        LOGGER.info("Loaded %d machine code(s) from Laravel", len(asset_codes))

    def _ensure_active(
        self,
        heartbeat: LeaseHeartbeat,
        started_at: float,
        job_id: int,
        attempt: int,
    ) -> None:
        heartbeat.assert_owned()
        elapsed = time.monotonic() - started_at
        if elapsed > self.settings.processing_budget_seconds:
            raise ProcessingBudgetExceeded(
                f"OCR processing budget of {self.settings.processing_budget_seconds} seconds exceeded "
                f"for job {job_id} attempt {attempt}."
            )

    def _report_failure(self, job: dict, attempt: int, error: str, heartbeat: LeaseHeartbeat) -> None:
        try:
            heartbeat.prepare_finalization()
            saved = self.client.fail(job["id"], attempt, error, retryable=True)
            LOGGER.warning(
                "OCR failure reported job_id=%s attempt=%s worker_id=%s status=%s error=%s",
                job["id"],
                attempt,
                self.settings.worker_id,
                saved.get("status", "?"),
                error,
            )
        except LeaseOwnershipLost as ownership_error:
            LOGGER.warning(
                "OCR failure not reported after ownership loss job_id=%s attempt=%s worker_id=%s error=%s",
                job["id"],
                attempt,
                self.settings.worker_id,
                ownership_error,
            )
        except WorkerApiError as report_error:
            LOGGER.error("Could not report failure for OCR job %s: %s", job["id"], report_error)


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


def run() -> None:
    root = Path(__file__).resolve().parents[2]
    settings = Settings.from_environment(root)
    configure_logging(settings.data_dir)

    with ProcessLock(settings.data_dir / "worker.lock"):
        worker = OcrWorker(settings)
        failure_streak = 0
        processed_jobs = 0
        LOGGER.info("MMTB RapidOCR worker started as %s", settings.worker_id)
        while True:
            try:
                processed = worker.step()
                failure_streak = 0
                if processed:
                    processed_jobs += 1
                    delay = processing_delay(settings, processed_jobs)
                    if delay > 0:
                        LOGGER.info(
                            "OCR load control: resting %.0f second(s) after %d processed job(s).",
                            delay,
                            processed_jobs,
                        )
                        time.sleep(delay)
                else:
                    processed_jobs = 0
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
                LOGGER.info("MMTB RapidOCR worker stopped")
                return
