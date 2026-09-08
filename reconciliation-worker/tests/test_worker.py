import tempfile
import unittest
import os
from pathlib import Path
from unittest.mock import Mock, patch

from mmtb_reconciliation_worker.models import CommandResult, ReconciliationResult
from mmtb_reconciliation_worker.worker import ReconciliationWorker


class WorkerTest(unittest.TestCase):
    @patch.dict(os.environ, {"DAILY_PHOTOS_ONLY": "true"})
    def test_daily_mode_makes_no_api_or_model_calls(self):
        worker = object.__new__(ReconciliationWorker)
        worker.laravel = Mock()
        worker.openclaw = Mock()
        self.assertFalse(worker.step())
        self.assertEqual([], worker.laravel.mock_calls)
        self.assertEqual([], worker.openclaw.mock_calls)

    def test_completes_job_and_deletes_temporary_image(self):
        worker = object.__new__(ReconciliationWorker)
        worker.settings = Mock(worker_id="worker-1", openclaw_model=None)
        worker.laravel = Mock()
        worker.openclaw = Mock()
        worker.health = Mock()
        with tempfile.NamedTemporaryFile(delete=False, suffix=".jpg") as file:
            path = Path(file.name)
        worker.laravel.download_image.return_value = path
        worker.openclaw.reconcile.return_value = ReconciliationResult(
            outcome="UNRESOLVED", summary="Thiếu dữ liệu", findings=[]
        )
        worker.laravel.complete.return_value = {
            "outcome": "UNRESOLVED", "findings": []
        }
        worker._process({
            "id": 5, "attempts": 1,
            "source_images": [{"ocr_job_id": 9, "image_url": "/image"}],
        })
        self.assertFalse(path.exists())
        payload = worker.laravel.complete.call_args.args[1]
        self.assertEqual("openclaw-cli", payload["metadata"]["source"])

    def test_completes_command_and_deletes_temporary_image(self):
        worker = object.__new__(ReconciliationWorker)
        worker.settings = Mock(worker_id="worker-1", openclaw_model=None)
        worker.laravel = Mock()
        worker.openclaw = Mock()
        worker.health = Mock()
        with tempfile.NamedTemporaryFile(delete=False, suffix=".jpg") as file:
            path = Path(file.name)
        worker.laravel.download_image.return_value = path
        worker.openclaw.execute_command.return_value = CommandResult(
            summary="Bằng chứng đầy đủ", details={"image_count": 1}, suggested_actions=[]
        )

        worker._process_command({
            "id": 8,
            "action": "CHECK_EVIDENCE",
            "attempts": 1,
            "reconciliation_job": {
                "id": 5,
                "source_images": [{"ocr_job_id": 9, "image_url": "/image"}],
            },
        })

        self.assertFalse(path.exists())
        payload = worker.laravel.complete_command.call_args.args[1]
        self.assertEqual("Bằng chứng đầy đủ", payload["summary"])
