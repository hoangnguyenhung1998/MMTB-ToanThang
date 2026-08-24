import unittest

from pydantic import ValidationError

from mmtb_journal_worker.models import JournalExtraction, JournalRow, normalize_time


class ModelsTest(unittest.TestCase):
    def test_normalizes_short_time(self):
        self.assertEqual("07:30:00", normalize_time("7h30"))

    def test_calculates_total_minutes(self):
        row = JournalRow(start_time="07:00", end_time="11:00", confidence=0.9)
        self.assertEqual(240, row.total_minutes)

    def test_keeps_missing_fields_for_laravel_review(self):
        row = JournalRow(confidence=0.4)
        self.assertIsNone(row.work_date)
        self.assertIsNone(row.work_content)

    def test_wraps_string_raw_data_for_laravel(self):
        row = JournalRow(raw_data="18/7 | Sáng | Club House", confidence=0.8)
        self.assertEqual({"text": "18/7 | Sáng | Club House"}, row.raw_data)

    def test_api_payload_renumbers_rows_and_normalizes_asset(self):
        extraction = JournalExtraction(
            asset_code=" vt-xl5024 ",
            confidence=0.91,
            raw_text="raw",
            rows=[
                JournalRow(row_number=8, work_date="2026-08-20", confidence=0.9),
                JournalRow(row_number=8, work_date="2026-08-21", confidence=0.8),
            ],
        )
        payload = extraction.api_payload()
        self.assertEqual("VT-XL5024", payload["asset_code"])
        self.assertEqual([1, 2], [row["row_number"] for row in payload["rows"]])

    def test_sop_forward_fills_date_and_infers_year(self):
        extraction = JournalExtraction(
            confidence=0.9,
            rows=[
                JournalRow(work_date="08/03", work_content="Sáng đào gen", confidence=0.9),
                JournalRow(work_content="Chiều lấp gen", confidence=0.9),
                JournalRow(work_date="09/03", work_content="Chờ dầu", confidence=0.9),
            ],
        ).normalize(reference_year=2026)
        self.assertEqual(["2026-03-08", "2026-03-08", "2026-03-09"], [row.work_date for row in extraction.rows])
        self.assertIn("INFERRED_YEAR", extraction.rows[0].raw_data["normalization_flags"])
        self.assertIn("INHERITED_DATE", extraction.rows[1].raw_data["normalization_flags"])

    def test_sop_does_not_invent_date_before_first_explicit_date(self):
        extraction = JournalExtraction(
            confidence=0.9,
            rows=[
                JournalRow(work_content="Đào đất", confidence=0.9),
                JournalRow(work_date="18/07/2026", work_content="Lấp đất", confidence=0.9),
            ],
        ).normalize(reference_year=2026)
        self.assertIsNone(extraction.rows[0].work_date)
        self.assertIn("MISSING_DATE", extraction.rows[0].raw_data["normalization_flags"])
        self.assertEqual("2026-07-18", extraction.rows[1].work_date)

    def test_sop_copies_explanation_status_into_both_fields(self):
        row = JournalExtraction(
            confidence=0.9,
            rows=[JournalRow(work_date="2026-08-20", work_content="Chờ việc do mưa", confidence=0.9)],
        ).normalize(reference_year=2026).rows[0]
        self.assertIn("Mưa nghỉ", row.work_content)
        self.assertEqual("Mưa nghỉ / Không thi công do mưa", row.error_explanation)
        self.assertIn("statuses", row.raw_data)

    def test_sop_keeps_unknown_job_and_marks_review(self):
        row = JournalExtraction(
            confidence=0.9,
            rows=[JournalRow(work_date="2026-08-20", work_content="Công việc hoàn toàn mới", confidence=0.95)],
        ).normalize(reference_year=2026).rows[0]
        self.assertEqual("Công việc hoàn toàn mới", row.work_content)
        self.assertIn("NEW_JOB", row.raw_data["normalization_flags"])
        self.assertLess(row.confidence, 0.8)

    def test_rejects_empty_document(self):
        with self.assertRaises(ValidationError):
            JournalExtraction(confidence=0.2, rows=[])


if __name__ == "__main__":
    unittest.main()
