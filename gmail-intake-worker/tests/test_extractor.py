import unittest
from mmtb_gmail_intake_worker.extractor import AssetCodeExtractor

class AssetCodeExtractorTest(unittest.TestCase):
    def setUp(self): self.extractor=AssetCodeExtractor("","","",30)
    def test_extracts_one_code_from_email_body(self):
        code,confidence,_=self.extractor.extract("Đã cấp mã VT-XL1501 cho máy",[])
        self.assertEqual("VT-XL1501",code); self.assertEqual(.99,confidence)
    def test_does_not_guess_when_email_has_no_code(self):
        code,confidence,_=self.extractor.extract("Đã tiếp nhận hồ sơ",[])
        self.assertIsNone(code); self.assertEqual(0,confidence)
    def test_multiple_codes_require_review_instead_of_choosing(self):
        code,_,_=self.extractor.extract("T-XL1001 và T-XL1002",[])
        self.assertIsNone(code)

if __name__=="__main__": unittest.main()
