import unittest
from pathlib import Path
from unittest.mock import Mock

from mmtb_journal_worker.laravel_client import LaravelJournalClient, WorkerApiError


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

    def test_requests_fresh_http_connection(self):
        self.assertEqual("close", self.client.session.headers["Connection"])

    def test_claims_only_weekly_journal_jobs(self):
        response = Mock()
        response.ok = True
        response.status_code = 204
        self.client.session.request = Mock(return_value=response)

        self.assertIsNone(self.client.claim())

        _method, _url = self.client.session.request.call_args.args
        payload = self.client.session.request.call_args.kwargs["json"]
        self.assertEqual(["WEEKLY_JOURNAL"], payload["document_types"])

    def test_reads_retry_after_from_rate_limit_response(self):
        response = Mock(ok=False, status_code=429, text="Too Many Attempts")
        response.headers = {"Retry-After": "23"}

        with self.assertRaises(WorkerApiError) as raised:
            self.client._ensure_success(response)

        self.assertEqual(23.0, raised.exception.retry_after_seconds)


if __name__ == "__main__":
    unittest.main()
