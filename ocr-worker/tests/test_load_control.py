import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from mmtb_ocr_worker.config import Settings
from mmtb_ocr_worker.load_control import processing_delay


class LoadControlTest(unittest.TestCase):
    def settings(self, directory: str) -> Settings:
        environment = {
            "LARAVEL_OCR_API_URL": "https://example.test/api/ocr/v1",
            "OCR_WORKER_API_TOKEN": "test-token",
            "OCR_WORKER_ID": "load-control-test",
        }
        with patch.dict(os.environ, environment, clear=True):
            return Settings.from_environment(Path(directory))

    def test_safe_load_control_defaults(self):
        with tempfile.TemporaryDirectory() as directory:
            settings = self.settings(directory)
            self.assertEqual(3.0, processing_delay(settings, 1))
            self.assertEqual(60.0, processing_delay(settings, 10))
            self.assertEqual(3.0, processing_delay(settings, 11))

    def test_load_control_can_be_tuned_from_environment(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            "LARAVEL_OCR_API_URL": "https://example.test/api/ocr/v1",
            "OCR_WORKER_API_TOKEN": "test-token",
            "OCR_BATCH_SIZE": "5",
            "OCR_DELAY_BETWEEN_JOBS_SECONDS": "1.5",
            "OCR_BATCH_COOLDOWN_SECONDS": "30",
        }, clear=True):
            settings = Settings.from_environment(Path(directory))
            self.assertEqual(1.5, processing_delay(settings, 4))
            self.assertEqual(30.0, processing_delay(settings, 5))

    def test_negative_delay_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            "LARAVEL_OCR_API_URL": "https://example.test/api/ocr/v1",
            "OCR_WORKER_API_TOKEN": "test-token",
            "OCR_DELAY_BETWEEN_JOBS_SECONDS": "-1",
        }, clear=True):
            with self.assertRaisesRegex(ValueError, "zero or greater"):
                Settings.from_environment(Path(directory))


if __name__ == "__main__":
    unittest.main()
