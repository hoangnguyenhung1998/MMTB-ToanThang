import unittest
from mmtb_journal_worker.handover_models import MachineHandoverExtraction


class MachineHandoverExtractionTest(unittest.TestCase):
    def test_keeps_any_asset_prefix_and_uses_date_only(self):
        result = MachineHandoverExtraction(asset_code="T-LU0040", handover_date="2026-08-29", confidence=.9)
        self.assertEqual("T-LU0040", result.api_payload()["extraction"]["asset_code"])
        self.assertEqual("2026-08-29", result.api_payload()["extraction"]["handover_date"])
        self.assertNotIn("MISSING_HANDOVER_DATE", result.review_flags)

    def test_missing_required_ocr_fields_are_flagged(self):
        result = MachineHandoverExtraction(confidence=.7)
        self.assertIn("MISSING_ASSET_CODE", result.review_flags)
        self.assertIn("MISSING_HANDOVER_DATE", result.review_flags)
        self.assertIn("LOW_CONFIDENCE", result.review_flags)


if __name__ == "__main__": unittest.main()
