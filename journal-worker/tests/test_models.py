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

    def test_rejects_empty_document(self):
        with self.assertRaises(ValidationError):
            JournalExtraction(confidence=0.2, rows=[])


if __name__ == "__main__":
    unittest.main()
