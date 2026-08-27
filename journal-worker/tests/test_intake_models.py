import unittest
from mmtb_journal_worker.intake_models import MachineIntakeExtraction

class MachineIntakeExtractionTest(unittest.TestCase):
    def test_normalizes_identifiers_and_flags_ambiguous_characters(self):
        result=MachineIntakeExtraction(chassis_no=" kmtpc 243 pgc454794 ",engine_no="6d107-26650512",machine_type="Máy xúc đào",manufacture_year=2016,confidence=.93)
        self.assertEqual("KMTPC243PGC454794",result.chassis_no)
        self.assertIn("VERIFY_AMBIGUOUS_CHASSIS_NO",result.review_flags)

    def test_missing_engine_never_silently_passes(self):
        result=MachineIntakeExtraction(chassis_no="ABC123",confidence=.7)
        self.assertIn("MISSING_ENGINE_NO",result.review_flags)
        self.assertIn("LOW_CONFIDENCE",result.review_flags)

if __name__ == "__main__": unittest.main()
