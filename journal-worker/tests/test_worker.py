import tempfile
import time
import unittest
from pathlib import Path

from mmtb_journal_worker.config import Settings
from mmtb_journal_worker.models import JournalExtraction, JournalRow
from mmtb_journal_worker.worker import JournalWorker


class FakeLaravelClient:
    def __init__(self, image_path: Path):
        self.image_path = image_path
        self.completed = None

    def claim(self):
        return {
            "id": 5,
            "document_type": "WEEKLY_JOURNAL",
            "image_url": "/api/ocr/v1/jobs/5/image?worker_id=journal-test",
            "attempts": 1,
            "message": {"sent_at": "2026-08-24T11:21:00+07:00"},
        }

    def download_image(self, _image_url, _job_id):
        return self.image_path

    def complete_journal(self, job_id, payload):
        self.completed = (job_id, payload)
        return {"status": "COMPLETED"}

    def fail(self, _job_id, _error, _retryable):
        raise AssertionError("A successful job must not be failed")


class FakeVisionClient:
    def extract(self, _image_path, machine_codes):
        if machine_codes != ["VT-XL5024"]:
            raise AssertionError("Machine catalog was not passed to vision")
        return JournalExtraction(
            asset_code="VT-XL5024",
            confidence=0.92,
            raw_text="journal text",
            rows=[JournalRow(
                work_date="2026-08-20",
                start_time="07:00",
                end_time="11:00",
                work_content="Thi công đào đất",
                confidence=0.9,
            )],
        )


class WorkerTest(unittest.TestCase):
    def test_completes_weekly_job_and_deletes_temporary_image(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            image = root / "journal.jpg"
            image.write_bytes(b"image")
            settings = Settings(
                laravel_api_url="http://office/api/ocr/v1",
                laravel_api_token="token",
                worker_id="journal-test",
                vision_api_base_url="http://vision/v1",
                vision_api_key="key",
                vision_model="model",
                data_dir=root / "data",
            )
            worker = JournalWorker(settings)
            laravel = FakeLaravelClient(image)
            worker.laravel = laravel
            worker.vision = FakeVisionClient()
            worker.machine_codes = ["VT-XL5024"]
            worker.machine_catalog_loaded_at = time.monotonic()

            self.assertTrue(worker.step())

            self.assertFalse(image.exists())
            self.assertEqual(5, laravel.completed[0])
            self.assertEqual(240, laravel.completed[1]["rows"][0]["total_minutes"])
            self.assertEqual("2026-08-20", laravel.completed[1]["rows"][0]["work_date"])


if __name__ == "__main__":
    unittest.main()
