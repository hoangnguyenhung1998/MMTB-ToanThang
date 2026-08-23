import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from mmtb_reconciliation_worker.models import ReconciliationResult
from mmtb_reconciliation_worker.worker import ReconciliationWorker


class WorkerTest(unittest.TestCase):
    def test_completes_job_and_deletes_temporary_image(self):
        worker = object.__new__(ReconciliationWorker)
        worker.settings = Mock(worker_id="worker-1", openclaw_model=None)
        worker.laravel = Mock()
        worker.openclaw = Mock()
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
