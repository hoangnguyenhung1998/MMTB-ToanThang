import time
import unittest

from mmtb_ocr_worker.api_client import WorkerApiError
from mmtb_ocr_worker.lease import LeaseHeartbeat, LeaseOwnershipLost


class FakeClient:
    timeout_seconds = 1

    def __init__(self):
        self.renew_calls = []
        self.error = None

    def renew(self, job_id, attempt):
        self.renew_calls.append((job_id, attempt))
        if self.error is not None:
            raise self.error
        return {"lease_expires_at": "2026-09-09T08:05:00+07:00"}


class LeaseHeartbeatTest(unittest.TestCase):
    def test_renews_while_caller_is_blocked_and_stops_cleanly(self):
        client = FakeClient()
        heartbeat = LeaseHeartbeat(client, 7, 2, "worker-1", 1, 0.05)
        heartbeat.start()

        # The foreground remains blocked for longer than the original lease,
        # while the independent loop continues extending the same attempt.
        time.sleep(1.05)

        heartbeat.stop()
        calls_after_stop = len(client.renew_calls)
        time.sleep(0.1)

        self.assertGreaterEqual(calls_after_stop, 10)
        self.assertEqual(calls_after_stop, len(client.renew_calls))
        self.assertFalse(heartbeat.lost)

    def test_stale_renew_marks_ownership_lost(self):
        client = FakeClient()
        client.error = WorkerApiError("stale attempt", retryable=False)
        heartbeat = LeaseHeartbeat(client, 7, 1, "worker-1", 1, 0.01)
        heartbeat.start()

        deadline = time.monotonic() + 1
        while not heartbeat.lost and time.monotonic() < deadline:
            time.sleep(0.01)

        heartbeat.stop()
        self.assertTrue(heartbeat.lost)
        with self.assertRaises(LeaseOwnershipLost):
            heartbeat.prepare_finalization()


if __name__ == "__main__":
    unittest.main()
