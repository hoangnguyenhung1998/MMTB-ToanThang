import unittest

from mmtb_ocr_worker.classifier import classify_text


class ClassifierTest(unittest.TestCase):
    def test_classifies_timemark_overlay(self):
        result = classify_text(
            "VT-LU0216\n07:28\n31 Tháng 7, 2026\nThành Phố Hải Phòng\n"
            "Công ty: Toàn Thắng\nHọ tên: Nguyễn Văn Khương\nSĐT: 0866886292\nTimeMark"
        )
        self.assertEqual("DAILY_TIMEMARK", result.document_type)
        self.assertGreaterEqual(result.confidence, 0.70)

    def test_classifies_weekly_journal(self):
        result = classify_text(
            "NHẬT TRÌNH HOẠT ĐỘNG THIẾT BỊ\nNỘI DUNG CÔNG VIỆC\n"
            "THỜI GIAN LÀM VIỆC\nKHỐI LƯỢNG\nĐƠN VỊ\nCHỮ KÝ",
            table_score=0.90,
        )
        self.assertEqual("WEEKLY_JOURNAL", result.document_type)

    def test_weekly_journal_with_timemark_watermark_stays_weekly(self):
        result = classify_text(
            "TIMEMARK VERIFIED\\nNHẬT TRÌNH HOẠT ĐỘNG THIẾT BỊ\\n"
            "NỘI DUNG CÔNG VIỆC\\nTHỜI GIAN LÀM VIỆC\\nKHỐI LƯỢNG",
            table_score=0.80,
        )
        self.assertEqual("WEEKLY_JOURNAL", result.document_type)

    def test_daily_person_and_machine_without_form_stays_daily(self):
        result = classify_text(
            "VT-LU5021\\n07:28\\nHọ tên: Nguyễn Văn A\\nSĐT: 0866886292\\nTIMEMARK",
            table_score=0.02,
        )
        self.assertEqual("DAILY_TIMEMARK", result.document_type)

    def test_keeps_uncertain_image_unknown(self):
        result = classify_text("Ảnh công trường không có biểu mẫu", table_score=0.05)
        self.assertEqual("UNKNOWN", result.document_type)


if __name__ == "__main__":
    unittest.main()
