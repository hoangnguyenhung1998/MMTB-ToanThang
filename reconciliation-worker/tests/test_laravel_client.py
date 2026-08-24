import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from mmtb_reconciliation_worker.laravel_client import LaravelReconciliationClient, WorkerApiError


class LaravelClientTest(unittest.TestCase):
    def client(self, session):
        return LaravelReconciliationClient(
            "https://example.test/subdir/api/openclaw/v1", "secret", "worker-1", 30,
            Path(tempfile.gettempdir()), session,
        )

    def test_claims_work_date(self):
        response = Mock(ok=True, status_code=200)
        response.json.return_value = {"jobs": [{"id": 9}]}
        session = Mock()
        session.headers = {}
        session.request.return_value = response
        jobs = self.client(session).claim("2026-08-23", 5)
        self.assertEqual(9, jobs[0]["id"])
        self.assertEqual("2026-08-23", session.request.call_args.kwargs["json"]["work_date"])

    def test_claims_openclaw_commands(self):
        response = Mock(ok=True, status_code=200)
        response.json.return_value = {"commands": [{"id": 12}]}
        session = Mock()
        session.headers = {}
        session.request.return_value = response

        commands = self.client(session).claim_commands(3)

        self.assertEqual(12, commands[0]["id"])
        self.assertEqual(3, session.request.call_args.kwargs["json"]["limit"])
        self.assertTrue(session.request.call_args.args[1].endswith("/commands/claim"))

    def test_resolves_relative_image_url_with_deployment_prefix(self):
        url = self.client(Mock(headers={}))._resolve_image_url(
            "/api/openclaw/v1/reconciliation/jobs/1/images/2"
        )
        self.assertEqual(
            "https://example.test/subdir/api/openclaw/v1/reconciliation/jobs/1/images/2", url
        )

    def test_reads_retry_after_from_rate_limit_response(self):
        response = Mock(ok=False, status_code=429, text="Too Many Attempts")
        response.headers = {"Retry-After": "31"}
        session = Mock(headers={})
        session.request.return_value = response

        with self.assertRaises(WorkerApiError) as raised:
            self.client(session).claim("2026-08-23", 5)

        self.assertEqual(31.0, raised.exception.retry_after_seconds)
