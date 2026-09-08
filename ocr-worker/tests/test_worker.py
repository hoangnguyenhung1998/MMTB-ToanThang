import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

from mmtb_ocr_worker.api_client import WorkerApiError
from mmtb_ocr_worker.health import WorkerHealth
from mmtb_ocr_worker.worker import OcrWorker


class WorkerClient:
    timeout_seconds = 1

    def __init__(self, image_path: Path, renew_error=None):
        self.image_path = image_path
        self.complete_calls = []
        self.fail_calls = []
        self.renew_calls = 0
        self.renew_error = renew_error

    def claim(self, document_types):
        return {
            "id": 9,
            "attempt": 2,
            "attempts": 2,
            "lease_seconds": 1,
            "document_type": "DAILY_TIMEMARK",
            "image_url": "/image",
        }

    def download_image(self, image_url, job_id):
        return self.image_path

    def renew(self, job_id, attempt):
        self.renew_calls += 1
        if self.renew_error is not None:
            raise self.renew_error
        return {"lease_expires_at": "2026-09-09T08:05:00+07:00"}

    def complete_timemark(self, job_id, attempt, payload):
        self.complete_calls.append((job_id, attempt, payload))

    def fail(self, job_id, attempt, error, retryable):
        self.fail_calls.append((job_id, attempt, error, retryable))
        return {"status": "RETRY"}


class BlockingRecognizer:
    def recognize(self, image_path, progress=None):
        time.sleep(0.05)
        if progress:
            progress("finished", 0, "asset", 50)
        return SimpleNamespace(api_payload=lambda: {"confidence": 0.99})


class WorkerLeaseSafetyTest(unittest.TestCase):
    def worker(self, root: Path, client, processing_budget_seconds=900) -> OcrWorker:
        worker = OcrWorker.__new__(OcrWorker)
        worker.settings = SimpleNamespace(
            worker_id="worker-1",
            lease_renew_interval_seconds=0.01,
            processing_budget_seconds=processing_budget_seconds,
        )
        worker.client = client
        worker.recognizer = BlockingRecognizer()
        worker.health = WorkerHealth(root / "health.json")
        worker._refresh_machine_catalog = Mock()
        return worker

    def test_lost_ownership_does_not_send_completion_or_failure(self):
        with tempfile.TemporaryDirectory() as temporary:
            image = Path(temporary) / "image.jpg"
            image.write_bytes(b"image")
            client = WorkerClient(
                image,
                renew_error=WorkerApiError("stale attempt", retryable=False),
            )
            worker = self.worker(Path(temporary), client)

            self.assertTrue(worker.step())

            self.assertGreaterEqual(client.renew_calls, 1)
            self.assertEqual([], client.complete_calls)
            self.assertEqual([], client.fail_calls)

    def test_processing_budget_reports_retryable_failure_at_safe_boundary(self):
        with tempfile.TemporaryDirectory() as temporary:
            image = Path(temporary) / "image.jpg"
            image.write_bytes(b"image")
            client = WorkerClient(image)
            worker = self.worker(Path(temporary), client, processing_budget_seconds=0.01)

            self.assertTrue(worker.step())

            self.assertEqual([], client.complete_calls)
            self.assertEqual(1, len(client.fail_calls))
            self.assertEqual((9, 2), client.fail_calls[0][:2])
            self.assertIn("processing budget", client.fail_calls[0][2])
            self.assertTrue(client.fail_calls[0][3])


if __name__ == "__main__":
    unittest.main()
