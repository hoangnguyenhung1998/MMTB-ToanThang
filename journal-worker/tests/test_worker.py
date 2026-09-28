import tempfile
import time
import unittest
import os
from pathlib import Path
from unittest.mock import Mock, patch

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

    def claim_daily_rescue(self):
        return None

    def download_image(self, _image_url, _job_id):
        return self.image_path

    def complete_journal(self, job_id, payload):
        self.completed = (job_id, payload)
        return {"status": "COMPLETED"}

    def fail(self, _job_id, _error, _retryable):
        raise AssertionError("A successful job must not be failed")

    def claim_intake(self):
        return None


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


class FakeDailyRescueLaravelClient:
    def __init__(self, image_path: Path):
        self.image_path = image_path
        self.completed = None

    def claim_daily_rescue(self):
        return {
            "id": 41,
            "ocr_job_id": 9,
            "attempt": 1,
            "attempts": 1,
            "max_attempts": 3,
            "image_url": "/api/ocr/v1/daily-ai-rescue/jobs/41/image",
        }

    def download_image(self, _image_url, _job_id):
        return self.image_path

    def complete_daily_rescue(self, attempt_id, attempt, payload):
        self.completed = (attempt_id, attempt, payload)
        return {"final_resolution": "RESOLVED"}

    def fail_daily_rescue(self, *_args, **_kwargs):
        raise AssertionError("A successful rescue must not be failed")


class FakeDailyRescueVisionClient:
    class Result:
        class Extraction:
            classification = "DAILY_PHOTO"

        extraction = Extraction()

        @staticmethod
        def api_payload(provider, model):
            return {
                "provider": provider,
                "model": model,
                "prompt_version": "daily_photo_rescue_v1",
                "schema_version": "daily_photo_rescue_v1",
                "result": {
                    "classification": "DAILY_PHOTO",
                    "machine": "T-XX0717",
                    "capture_date": "2026-09-28",
                    "capture_time": "10:31",
                    "ambiguities": [],
                },
                "raw_response": "{}",
                "usage": {},
            }

    def extract(self, _image_path):
        return self.Result()


class WorkerTest(unittest.TestCase):
    def test_completes_explicit_daily_rescue_and_deletes_original_download(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            image = root / "original.jpg"
            image.write_bytes(b"original")
            settings = Settings(
                laravel_api_url="http://office/api/ocr/v1",
                laravel_api_token="token",
                worker_id="journal-test",
                vision_api_base_url="http://vision/v1",
                vision_api_key="key",
                vision_model="vision-model",
                vision_provider="9router-openai-compatible",
                data_dir=root / "data",
            )
            worker = JournalWorker(settings)
            laravel = FakeDailyRescueLaravelClient(image)
            worker.laravel = laravel
            worker.daily_rescue_vision = FakeDailyRescueVisionClient()

            self.assertTrue(worker.step())

            self.assertFalse(image.exists())
            self.assertEqual((41, 1), laravel.completed[:2])
            self.assertEqual("T-XX0717", laravel.completed[2]["result"]["machine"])
            self.assertEqual("9router-openai-compatible", laravel.completed[2]["provider"])

    @patch.dict(os.environ, {"DAILY_PHOTOS_ONLY": "false"})
    def test_weekly_journal_gets_priority_after_bounded_daily_rescue_streak(self):
        worker = object.__new__(JournalWorker)
        worker.settings = Mock(daily_rescue_max_consecutive=3)
        worker.daily_rescue_streak = 3
        worker._refresh_machine_catalog = Mock()
        worker.laravel = Mock()
        journal_job = {"id": 88, "document_type": "WEEKLY_JOURNAL"}
        worker.laravel.claim.return_value = journal_job
        worker._process_journal = Mock(return_value=True)

        self.assertTrue(worker.step())

        worker._refresh_machine_catalog.assert_called_once()
        worker.laravel.claim.assert_called_once()
        worker.laravel.claim_daily_rescue.assert_not_called()
        worker.laravel.claim_handover.assert_not_called()
        worker.laravel.claim_intake.assert_not_called()
        worker._process_journal.assert_called_once_with(journal_job)
        self.assertEqual(0, worker.daily_rescue_streak)

    @patch.dict(os.environ, {"DAILY_PHOTOS_ONLY": "true"})
    def test_intake_gets_priority_after_bounded_daily_rescue_streak(self):
        worker = object.__new__(JournalWorker)
        worker.settings = Mock(daily_rescue_max_consecutive=3)
        worker.daily_rescue_streak = 3
        worker.laravel = Mock()
        worker.laravel.claim_handover.return_value = None
        intake_job = {"id": 89}
        worker.laravel.claim_intake.return_value = intake_job
        worker._process_intake = Mock(return_value=True)

        self.assertTrue(worker.step())

        worker.laravel.claim.assert_not_called()
        worker.laravel.claim_daily_rescue.assert_not_called()
        worker._process_intake.assert_called_once_with(intake_job)
        self.assertEqual(0, worker.daily_rescue_streak)

    @patch.dict(os.environ, {"DAILY_PHOTOS_ONLY": "false"})
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

    @patch.dict(os.environ, {"DAILY_PHOTOS_ONLY": "true"})
    def test_daily_mode_skips_weekly_but_keeps_intake_and_handover(self):
        worker = object.__new__(JournalWorker)
        worker.settings = Mock(daily_rescue_max_consecutive=3)
        worker._refresh_machine_catalog = Mock()
        worker.laravel = Mock()
        worker.laravel.claim_daily_rescue.return_value = None
        worker.laravel.claim_handover.return_value = None
        worker.laravel.claim_intake.return_value = {"id": 12}
        worker._process_intake = Mock(return_value=True)
        worker.vision = Mock()
        self.assertTrue(worker.step())
        worker.laravel.claim.assert_not_called()
        worker.laravel.claim_handover.assert_called_once()
        worker._process_intake.assert_called_once_with({"id": 12})
        worker.vision.extract.assert_not_called()


if __name__ == "__main__":
    unittest.main()
