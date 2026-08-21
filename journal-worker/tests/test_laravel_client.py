import unittest
from pathlib import Path
from unittest.mock import Mock

from mmtb_journal_worker.laravel_client import LaravelJournalClient


class LaravelClientTest(unittest.TestCase):
    def setUp(self):
        self.client = LaravelJournalClient(
            api_url="http://100.64.0.10/mmtbver2/public/api/ocr/v1",
            token="secret",
            worker_id="journal-test",
            timeout_seconds=30,
            temp_dir=Path("data/test-tmp"),
        )

    def test_resolves_relative_image_url(self):
        resolved = self.client._resolve_image_url(
            "/mmtbver2/public/api/ocr/v1/jobs/5/image?worker_id=journal-test"
        )
        self.assertEqual(
            "http://100.64.0.10/mmtbver2/public/api/ocr/v1/jobs/5/image?worker_id=journal-test",
            resolved,
        )

    def test_adds_laravel_deployment_prefix(self):
        resolved = self.client._resolve_image_url(
            "/api/ocr/v1/jobs/5/image?worker_id=journal-test"
        )
        self.assertEqual(
            "http://100.64.0.10/mmtbver2/public/api/ocr/v1/jobs/5/image?worker_id=journal-test",
            resolved,
        )

    def test_maps_png_content_type(self):
        self.assertEqual(".png", self.client._image_suffix("image/png; charset=binary"))

    def test_claims_only_weekly_journal_jobs(self):
        response = Mock()
        response.ok = True
        response.status_code = 204
        self.client.session.request = Mock(return_value=response)

        self.assertIsNone(self.client.claim())

        _method, _url = self.client.session.request.call_args.args
        payload = self.client.session.request.call_args.kwargs["json"]
        self.assertEqual(["WEEKLY_JOURNAL"], payload["document_types"])


if __name__ == "__main__":
    unittest.main()
