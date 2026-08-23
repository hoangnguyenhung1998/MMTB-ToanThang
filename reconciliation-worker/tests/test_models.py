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

    def test_caps_unresolved_confidence(self):
        result = ReconciliationResult.model_validate({
            "outcome": "UNRESOLVED", "confidence": 0.98, "findings": []
        })
        self.assertEqual(0.5, result.api_payload()["confidence"])
