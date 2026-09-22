import unittest

from mmtb_ocr_worker.parser import (
    AssetMatcher,
    normalize_asset,
    parse_date,
    parse_date_candidates,
    parse_location,
    parse_operator,
    parse_phone,
    parse_time,
    parse_time_candidates,
)


class ParserTest(unittest.TestCase):
    def test_normalizes_asset_code(self):
        self.assertEqual("VT-LU0216", normalize_asset(" vt–lu_0216 "))

    def test_parses_vietnamese_date(self):
        self.assertEqual("2026-07-31", parse_date("31 Tháng 7, 2026").isoformat())

    def test_parses_numeric_date(self):
        self.assertEqual("2026-08-20", parse_date("20/08/2026").isoformat())

    def test_parses_supported_english_and_vietnamese_dates_with_bounded_noise(self):
        for raw in (
            "21 Sep 2026",
            "21 Sep,2026",
            "21 SEPTEMBER, 2026",
            "P21 Sep,2026",
            "21 Tháng 9 2026",
            "21 tháng 9 năm 2026",
            "21 Tháng 9,20265C",
        ):
            with self.subTest(raw=raw):
                self.assertEqual("2026-09-21", parse_date(raw).isoformat())

    def test_numeric_date_orientation_must_be_deterministic(self):
        self.assertEqual("2026-09-21", parse_date("09/21/2026").isoformat())
        self.assertEqual("2026-08-25", parse_date("25/08/2026").isoformat())
        candidates, ambiguous = parse_date_candidates("08/09/2026")
        self.assertEqual(set(), candidates)
        self.assertTrue(ambiguous)
        self.assertIsNone(parse_date("08/09/2026"))

    def test_rejects_invalid_and_conflicting_dates(self):
        self.assertIsNone(parse_date("31/02/2026"))
        self.assertIsNone(parse_date("21/09/2026 and 22/09/2026"))

    def test_october_name_is_not_corrupted_by_numeric_ocr_normalization(self):
        self.assertEqual("2026-10-21", parse_date("21 October 2026").isoformat())

    def test_parses_time(self):
        self.assertEqual("07:28:00", parse_time("07:28").isoformat())

    def test_24_hour_time_and_meridiem_are_normalized(self):
        for raw, expected in [('6h14', '06:14:00'), ('13:55', '13:55:00'), ('1:55 PM', '13:55:00'),
                              ('12:00 AM', '00:00:00'), ('12:00 PM', '12:00:00'), ('06.14', '06:14:00')]:
            with self.subTest(raw=raw):
                self.assertEqual(expected, parse_time(raw).isoformat())

    def test_date_is_not_misread_as_a_time(self):
        self.assertIsNone(parse_time('05.09.2026'))
        self.assertEqual('06:14:00', parse_time('05.09.2026 06:14').isoformat())

    def test_conflicting_times_are_not_guessed(self):
        self.assertIsNone(parse_time('06:14 18:14'))

    def test_dash_time_is_only_allowed_in_trusted_region(self):
        self.assertIsNone(parse_time('06-57'))
        self.assertEqual('06:57:00', parse_time('06-57', allow_dash=True).isoformat())
        self.assertEqual(set(), parse_time_candidates('plate 15-45'))
        self.assertEqual({'15:45:00'}, {value.isoformat() for value in parse_time_candidates('15-45', allow_dash=True)})

    def test_invalid_time_phone_and_machine_numbers_are_rejected(self):
        self.assertIsNone(parse_time('24:99'))
        self.assertIsNone(parse_time('090:123:4567'))
        self.assertIsNone(parse_time('T-3C0172'))

    def test_parses_phone(self):
        self.assertEqual("0866886292", parse_phone("SĐT: 0866 886 292"))

    def test_parses_operator(self):
        self.assertEqual("Nguyễn Văn Khương", parse_operator("Công ty: Toàn Thắng\nHọ tên: Nguyễn Văn Khương\nSĐT: 0866886292"))

    def test_parses_location(self):
        self.assertEqual(
            "Thành Phố Hải Phòng, P. Đông Hải",
            parse_location("07:28\nThành Phố Hải Phòng, P. Đông Hải\nCông ty: Toàn Thắng"),
        )

    def test_matches_exact_asset_from_catalog(self):
        matcher = AssetMatcher(["VT-LU0216", "T-XL0354"])
        self.assertEqual("VT-LU0216", matcher.match("Ảnh máy VT-LU0216")[0])

    def test_matches_common_ocr_confusion(self):
        matcher = AssetMatcher(["VT-LU0216"])
        code, confidence, _ = matcher.match("VT-LUO216")
        self.assertEqual("VT-LU0216", code)
        self.assertGreaterEqual(confidence, 0.84)

    def test_does_not_replace_unknown_numeric_code_with_nearest_catalog_code(self):
        matcher = AssetMatcher(["VT-LU5020"])
        code, confidence, raw = matcher.match("VT-LU5021")
        self.assertEqual("VT-LU5021", code)
        self.assertLess(confidence, 0.84)
        self.assertEqual("VT-LU5021", raw)

    def test_reports_multiple_valid_machine_candidates_in_one_crop(self):
        matcher = AssetMatcher(["T-XL0303", "T-3C0140"])
        self.assertEqual({"T-XL0303", "T-3C0140"}, matcher.valid_matches("T-XL0303 T-3C0140"))


if __name__ == "__main__":
    unittest.main()
