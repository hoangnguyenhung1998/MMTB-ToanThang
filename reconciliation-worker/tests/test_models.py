import unittest

from pydantic import ValidationError

from mmtb_reconciliation_worker.models import ReconciliationResult


class ModelsTest(unittest.TestCase):
    def test_accepts_matched_without_findings(self):
        result = ReconciliationResult.model_validate({
            "outcome": "MATCHED", "summary": "Khớp", "confidence": 0.93, "findings": []
        })
        self.assertEqual("MATCHED", result.outcome)

    def test_warning_requires_finding(self):
        with self.assertRaises(ValidationError):
            ReconciliationResult.model_validate({"outcome": "WARNING", "findings": []})
