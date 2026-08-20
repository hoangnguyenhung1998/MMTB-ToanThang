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


LOGGER = logging.getLogger("mmtb_ocr_worker")


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

    def step(self) -> bool:
        self._refresh_machine_catalog()
        job = self.client.claim(["DAILY_TIMEMARK"])
        if job is None:
            job = self.client.claim(["UNKNOWN"])
        if job is None:
            return False

        image_path: Path | None = None
        try:
            image_path = self.client.download_image(job["image_url"], int(job["id"]))
            if job["document_type"] == "UNKNOWN":
                self._classify(job, image_path)
            else:
                self._recognize_timemark(job, image_path)
        except WorkerApiError:
            raise
        except Exception as exc:
            attempts = int(job.get("attempts", 1))
            LOGGER.exception("OCR job %s failed locally", job["id"])
            self._report_failure(job, str(exc), retryable=attempts < 3)
        finally:
            if image_path is not None:
                image_path.unlink(missing_ok=True)
        return True

    def _classify(self, job: dict, image_path: Path) -> None:
        result = self.classifier.classify(image_path)
        saved = self.client.classify(job["id"], result.document_type, result.confidence)
        LOGGER.info(
            "Classified OCR job %s as %s (%.2f), status=%s",
            job["id"],
            result.document_type,
            result.confidence,
            saved["status"],
        )

    def _recognize_timemark(self, job: dict, image_path: Path) -> None:
        if self.recognizer is None:
            raise RuntimeError("Machine catalog is not loaded.")
        result = self.recognizer.recognize(image_path)
        saved = self.client.complete_timemark(job["id"], result.api_payload())
        LOGGER.info(
            "Completed TimeMark OCR job %s: machine=%s date=%s time=%s status=%s",
            job["id"],
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

    def _report_failure(self, job: dict, error: str, retryable: bool) -> None:
        try:
            self.client.fail(job["id"], error, retryable)
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
        LOGGER.info("MMTB RapidOCR worker started as %s", settings.worker_id)
        while True:
            try:
                processed = worker.step()
                if not processed:
                    time.sleep(settings.poll_seconds)
            except WorkerApiError as exc:
                LOGGER.warning("%s", exc)
                time.sleep(settings.poll_seconds)
            except KeyboardInterrupt:
                LOGGER.info("MMTB RapidOCR worker stopped")
                return
