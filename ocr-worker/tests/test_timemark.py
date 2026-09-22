import unittest
from datetime import date
from pathlib import Path
from unittest.mock import Mock, patch

import numpy as np

from mmtb_ocr_worker.timemark import TimeMarkRecognizer


class TimeMarkTest(unittest.TestCase):
    def test_aggregates_all_bounded_regions_without_requiring_phone_or_location(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["VT-XL0196 21/09/2026 06:14"], [0.99])):
            result = TimeMarkRecognizer(["VT-XL0196"], engine).recognize(Path("test.jpg"))
        self.assertEqual("06:14:00", result.captured_time)
        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("VT-XL0196", result.asset_code)
        self.assertEqual(20, engine.call_count)
        self.assertEqual(16, len(result.image_fingerprint))

    def test_missing_machine_keeps_date_time_for_sender_mapping(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["05/09/2026 01:55 PM"], [0.99])):
            result = TimeMarkRecognizer(["VT-XL0196"], engine).recognize(Path("test.jpg"))
        self.assertIsNone(result.asset_code)
        self.assertEqual("13:55:00", result.captured_time)
        self.assertEqual(20, engine.call_count)

    def test_progress_telemetry_does_not_change_result(self):
        engine = Mock()
        progress = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["VT-XL0196 21/09/2026 06:14"], [0.99])):
            result = TimeMarkRecognizer(["VT-XL0196"], engine).recognize(Path("test.jpg"), progress=progress)

        self.assertEqual("VT-XL0196", result.asset_code)
        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("06:14:00", result.captured_time)
        self.assertEqual(40, progress.call_count)
        event, rotation, region, duration_ms = progress.call_args.args
        self.assertEqual("finished", event)
        self.assertEqual(270, rotation)
        self.assertEqual("full", region)
        self.assertGreaterEqual(duration_ms, 0)

    def test_targeted_machine_retry_skips_time_only_region(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["T X X 0 7 1 7"], [0.99])):
            result = TimeMarkRecognizer(["T-XX0717"], engine).recognize(Path("test.jpg"), focus=["machine"])

        self.assertEqual("T-XX0717", result.asset_code)
        self.assertEqual(16, engine.call_count)

    def test_normalized_catalog_collision_does_not_select_a_machine(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["T_XX_0717"], [0.99])):
            result = TimeMarkRecognizer(["T-XX0717", "T XX 0717"], engine).recognize(
                Path("test.jpg"), focus=["machine"]
            )

        self.assertIsNone(result.asset_code)
        self.assertIn("machine", result.candidate_metadata["conflicts"])

    def test_aggregates_non_conflicting_machine_time_and_date_from_separate_crops(self):
        engine = Mock()
        outputs = iter([
            (["T-3C0140"], [0.99]),
            (["22:30"], [0.99]),
            (["21 Tháng 9,2026"], [0.99]),
        ])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["T-3C0140"], engine).recognize(Path("test.jpg"))

        self.assertEqual("T-3C0140", result.asset_code)
        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("22:30:00", result.captured_time)
        self.assertEqual([], result.candidate_metadata["conflicts"])
        self.assertEqual("asset", result.candidate_metadata["machine_evidence"][0]["region"])
        self.assertEqual(20, engine.call_count)

    def test_trusted_time_region_normalizes_dash_and_suffix_date_noise(self):
        engine = Mock()
        outputs = iter([
            (["06-57 21 Tháng 9,20265C"], [0.99]),
        ])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["SGC-T-3C0715"], engine).recognize(
                Path("test.jpg"), focus=["date", "time"]
            )

        self.assertIsNone(result.asset_code)
        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("06:57:00", result.captured_time)

    def test_suffix_noise_does_not_invalidate_complete_timemark(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["SGC-T-3C0556 17:33 21 Tháng 9,20265C"], [0.99])):
            result = TimeMarkRecognizer(["SGC-T-3C0556"], engine).recognize(Path("test.jpg"))

        self.assertEqual("SGC-T-3C0556", result.asset_code)
        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("17:33:00", result.captured_time)

    def test_later_conflicting_crop_fails_closed_instead_of_first_value_wins(self):
        engine = Mock()
        outputs = iter([
            (["T-XL0303 06:22 21 Sep,2026"], [0.99]),
            (["22 Sep,2026"], [0.99]),
        ])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["T-XL0303"], engine).recognize(Path("test.jpg"))

        self.assertEqual("T-XL0303", result.asset_code)
        self.assertIsNone(result.captured_date)
        self.assertIn("date", result.candidate_metadata["conflicts"])

    def test_source_aware_time_recovery_ignores_work_interval_and_invalid_time(self):
        engine = Mock()
        outputs = iter([
            (["20Thang9,2026"], [0.99]),
            (["15:04 - 19:02\nTan ca 19:02\n54:62"], [0.99]),
        ])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["VT-XX0880"], engine).recognize(Path("test.jpg"), focus=["date", "time"])

        self.assertEqual("2026-09-20", result.captured_date)
        self.assertEqual("19:02:00", result.captured_time)
        self.assertNotIn("time", result.candidate_metadata["conflicts"])
        reasons = {item["reason"] for item in result.candidate_metadata["time_evidence"]}
        self.assertIn("WORK_INTERVAL", reasons)
        self.assertIn("INVALID_TIME", reasons)

    def test_trusted_timemark_region_repairs_single_trailing_one(self):
        engine = Mock()
        outputs = iter([(["17:011 20Thang9,2026"], [0.99])])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["VT-XX0880"], engine).recognize(Path("test.jpg"), focus=["date", "time"])

        self.assertEqual("17:01:00", result.captured_time)
        self.assertEqual("2026-09-20", result.captured_date)

    def test_future_date_is_discarded_against_received_date_without_invention(self):
        engine = Mock()
        outputs = iter([
            (["06-24 23 Sep 2026"], [0.99]),
            (["21 Sep 2026"], [0.99]),
        ])
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", side_effect=lambda *_: next(outputs, ([], []))):
            result = TimeMarkRecognizer(["VT-XX0880"], engine).recognize(
                Path("test.jpg"), focus=["date", "time"], received_date=date(2026, 9, 22)
            )

        self.assertEqual("2026-09-21", result.captured_date)
        self.assertEqual("06:24:00", result.captured_time)
        self.assertEqual(["2026-09-23"], result.candidate_metadata["discarded_date_candidates"])
        self.assertNotIn("date", result.candidate_metadata["conflicts"])


if __name__ == "__main__":
    unittest.main()
