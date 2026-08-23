import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from mmtb_reconciliation_worker.config import Settings


class ConfigTest(unittest.TestCase):
    def test_loads_required_settings_and_defaults(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
            "LARAVEL_OPENCLAW_API_URL": "https://example.test/api/openclaw/v1/",
            "OPENCLAW_AGENT_API_TOKEN": "secret",
        }, clear=True):
            settings = Settings.from_environment(Path(directory))
        self.assertEqual("https://example.test/api/openclaw/v1", settings.laravel_api_url)
        self.assertEqual(14, settings.lookback_days)
        self.assertEqual("mmtb-reconciliation", settings.openclaw_session_key)
        self.assertEqual((Path(directory) / "data").resolve(), settings.data_dir.resolve())

    def test_rejects_missing_token(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(ValueError):
                Settings.from_environment(Path(directory))
