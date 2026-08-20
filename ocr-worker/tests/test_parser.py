import unittest

from mmtb_ocr_worker.parser import (
    AssetMatcher,
    normalize_asset,
    parse_date,
    parse_location,
    parse_operator,
    parse_phone,
    parse_time,
)


class ParserTest(unittest.TestCase):
    def test_normalizes_asset_code(self):
        self.assertEqual("VT-LU0216", normalize_asset(" vt–lu_0216 "))

    def test_parses_vietnamese_date(self):
        self.assertEqual("2026-07-31", parse_date("31 Tháng 7, 2026").isoformat())

    def test_parses_numeric_date(self):
        self.assertEqual("2026-08-20", parse_date("20/08/2026").isoformat())

    def test_parses_time(self):
        self.assertEqual("07:28:00", parse_time("07:28").isoformat())

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


if __name__ == "__main__":
    unittest.main()
