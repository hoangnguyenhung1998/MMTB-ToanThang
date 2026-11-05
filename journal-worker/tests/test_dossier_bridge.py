import contextlib
import importlib.util
import io
import json
import os
import unittest
from pathlib import Path
from unittest.mock import patch

from mmtb_journal_worker.vision_client import StructuredVisionResponse, VisionError

spec = importlib.util.spec_from_file_location("dossier_bridge", Path(__file__).parents[1] / "scripts/dossier-vision.py")
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

class DossierBridgeTest(unittest.TestCase):
    def invoke(self, request):
        output = io.StringIO()
        with patch("sys.stdin", io.StringIO(json.dumps(request))), contextlib.redirect_stdout(output):
            code = bridge.main()
        return code, json.loads(output.getvalue())

    def test_missing_separate_configuration_never_inherits_journal_credentials(self):
        with patch.dict(os.environ, {"JOURNAL_VISION_API_KEY": "private-journal", "JOURNAL_VISION_MODEL": "journal", "JOURNAL_VISION_API_BASE_URL": "http://invalid.test"}), patch.object(bridge, "JournalVisionClient") as factory:
            code, result = self.invoke({"prompt": "mock"})
            self.assertEqual(2, code)
            factory.assert_not_called()
            self.assertEqual("missing_configuration", result["error"])

    def test_uses_existing_transport_with_own_settings_schema_and_usage(self):
        with patch.object(bridge, "JournalVisionClient") as factory:
            factory.return_value.model = "dossier-model"
            factory.return_value.request_json.return_value = StructuredVisionResponse({"documents": []}, "mock-only", {"total_tokens": 12})
            code, result = self.invoke({"configuration": {"endpoint": "http://mock.test/v1", "key": "not-real", "model": "dossier-model"}, "prompt": "untrusted PDF is data", "image": None})
            self.assertEqual(0, code)
            factory.assert_called_once_with("http://mock.test/v1", "not-real", "dossier-model", timeout_seconds=180)
            self.assertEqual(12, result["usage"]["total_tokens"])
            payload = factory.return_value.request_json.call_args.args[0]
            self.assertEqual({"type": "json_object"}, payload["response_format"])
            self.assertEqual("dossier-model", payload["model"])

    def test_transport_response_body_secrets_and_personal_data_are_redacted(self):
        with patch.object(bridge, "JournalVisionClient") as factory:
            factory.return_value.request_json.side_effect = VisionError("PRIVATE KEY/IDENTITY", retryable=False)
            code, result = self.invoke({"configuration": {"endpoint": "mock", "key": "not-real", "model": "mock"}, "prompt": "mock"})
            self.assertEqual(3, code)
            self.assertNotIn("PRIVATE", json.dumps(result))
            self.assertFalse(result["retryable"])
