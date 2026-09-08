import unittest
from pathlib import Path
from unittest.mock import Mock, patch

import numpy as np

from mmtb_ocr_worker.timemark import TimeMarkRecognizer


class TimeMarkTest(unittest.TestCase):
    def test_stops_when_required_fields_are_read_without_phone_or_location(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["VT-XL0196 05/09/2026 06:14"], [0.99])):
            result = TimeMarkRecognizer(["VT-XL0196"], engine).recognize(Path("test.jpg"))
        self.assertEqual("06:14:00", result.captured_time)
        self.assertEqual("2026-09-05", result.captured_date)
        self.assertEqual("VT-XL0196", result.asset_code)
        self.assertEqual(1, engine.call_count)
        self.assertEqual(16, len(result.image_fingerprint))

    def test_missing_machine_keeps_date_time_for_sender_mapping(self):
        engine = Mock()
        with patch("mmtb_ocr_worker.timemark.read_image", return_value=np.zeros((200, 200, 3), dtype=np.uint8)), \
             patch("mmtb_ocr_worker.timemark.flatten_ocr_result", return_value=(["05/09/2026 01:55 PM"], [0.99])):
            result = TimeMarkRecognizer(["VT-XL0196"], engine).recognize(Path("test.jpg"))
        self.assertIsNone(result.asset_code)
        self.assertEqual("13:55:00", result.captured_time)
        self.assertEqual(5, engine.call_count)


if __name__ == "__main__":
    unittest.main()
