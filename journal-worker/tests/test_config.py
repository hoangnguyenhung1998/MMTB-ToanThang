import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from mmtb_journal_worker.config import Settings


class ConfigTest(unittest.TestCase):
    def test_loads_required_settings(self):
        values = {
            "LARAVEL_OCR_API_URL": "http://office/api/ocr/v1",
            "OCR_WORKER_API_TOKEN": "laravel-token",
            "JOURNAL_WORKER_ID": "journal-test",
            "JOURNAL_VISION_API_BASE_URL": "http://localhost:3000/v1",
            "JOURNAL_VISION_API_KEY": "vision-token",
            "JOURNAL_VISION_MODEL": "vision-model",
        }
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, values, clear=True):
            settings = Settings.from_environment(Path(directory))

        self.assertEqual("journal-test", settings.worker_id)
        self.assertEqual("vision-model", settings.vision_model)
        self.assertEqual((Path(directory) / "data").resolve(), settings.data_dir)

    def test_rejects_missing_secrets(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {}, clear=True):
            with self.assertRaisesRegex(ValueError, "Missing required configuration"):
                Settings.from_environment(Path(directory))


if __name__ == "__main__":
    unittest.main()
