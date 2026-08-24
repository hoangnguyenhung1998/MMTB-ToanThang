import json
import tempfile
import unittest
from pathlib import Path

import httpx

from mmtb_journal_worker.vision_client import JournalVisionClient, VisionError


class VisionClientTest(unittest.TestCase):
    def test_parses_openai_compatible_response(self):
        result = {
            "asset_code": "VT-XL5024",
            "confidence": 0.93,
            "raw_text": "20/08 thi cong",
            "rows": [{
                "row_number": 1,
                "work_date": "2026-08-20",
                "start_time": "7:00",
                "end_time": "11:00",
                "work_content": "Thi công đào đất",
                "confidence": 0.9,
            }],
        }

        def handler(request: httpx.Request) -> httpx.Response:
            body = json.loads(request.content)
            self.assertEqual(["WEEKLY-JOURNAL-CODE"], self._catalog_from_prompt(body))
            return httpx.Response(
                200,
                json={"choices": [{"message": {"content": json.dumps(result)}}]},
            )

        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "journal.jpg"
            image.write_bytes(b"image")
            client = httpx.Client(transport=httpx.MockTransport(handler))
            vision = JournalVisionClient("http://vision/v1", "key", "model", 30, client)
            extraction = vision.extract(image, ["WEEKLY-JOURNAL-CODE"])

        self.assertEqual("VT-XL5024", extraction.asset_code)
        self.assertEqual(240, extraction.rows[0].total_minutes)

    def test_tolerates_job_23_quantity_suffixes(self):
        result = {
            "asset_code": None,
            "confidence": "58%",
            "raw_text": "journal",
            "rows": [
                {"work_date": "24/08", "work_content": "Đào đất", "quantity": "4c", "confidence": "80%"},
                {"work_content": "Lấp đất", "quantity": "4c", "confidence": 0.8},
                {"work_content": "Lu nền", "quantity": "3c", "confidence": 0.8},
            ],
        }

        def handler(_request: httpx.Request) -> httpx.Response:
            return httpx.Response(200, json={"choices": [{"message": {"content": json.dumps(result)}}]})

        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "journal.jpg"
            image.write_bytes(b"image")
            client = httpx.Client(transport=httpx.MockTransport(handler))
            extraction = JournalVisionClient("http://vision/v1", "key", "model", 30, client).extract(image, [])

        self.assertEqual([4.0, 4.0, 3.0], [row.quantity for row in extraction.rows])
        self.assertEqual(0.58, extraction.confidence)

    def test_schema_failure_is_not_retried_at_vision_layer(self):
        def handler(_request: httpx.Request) -> httpx.Response:
            return httpx.Response(200, json={"choices": [{"message": {"content": '{"rows":[]}'}}]})

        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "journal.jpg"
            image.write_bytes(b"image")
            client = httpx.Client(transport=httpx.MockTransport(handler))
            vision = JournalVisionClient("http://vision/v1", "key", "model", 30, client)
            with self.assertRaises(VisionError) as raised:
                vision.extract(image, [])

        self.assertFalse(raised.exception.retryable)

    @staticmethod
    def _catalog_from_prompt(body: dict) -> list[str]:
        prompt = body["messages"][0]["content"][0]["text"]
        return [code for code in ["WEEKLY-JOURNAL-CODE"] if code in prompt]

    def test_strips_json_markdown_fence(self):
        parsed = JournalVisionClient._parse_json_content('```json\n{"rows": []}\n```')
        self.assertEqual({"rows": []}, parsed)


if __name__ == "__main__":
    unittest.main()
