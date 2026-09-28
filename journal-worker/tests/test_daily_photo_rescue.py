import json
import tempfile
import unittest
from pathlib import Path

import httpx

from mmtb_journal_worker.daily_photo_rescue_models import DailyPhotoRescueExtraction
from mmtb_journal_worker.daily_photo_rescue_vision_client import (
    DailyPhotoRescueVisionClient,
    PROMPT_VERSION,
    SCHEMA_VERSION,
)
from mmtb_journal_worker.vision_client import JournalVisionClient, VisionError


class DailyPhotoRescueTest(unittest.TestCase):
    def test_reads_only_original_image_and_returns_versioned_usage_payload(self):
        result = {
            "classification": "DAILY_PHOTO",
            "machine": "T-XX0717",
            "capture_date": "2026-09-28",
            "capture_time": "10:31",
            "ambiguities": [],
        }

        def handler(request: httpx.Request) -> httpx.Response:
            body = json.loads(request.content)
            prompt = body["messages"][0]["content"][0]["text"]
            image_url = body["messages"][0]["content"][1]["image_url"]["url"]
            self.assertIn(PROMPT_VERSION, prompt)
            self.assertIn("ORIGINAL source image", prompt)
            self.assertNotIn("T-OLD9999", prompt)
            self.assertNotIn("candidate_metadata", prompt)
            self.assertTrue(image_url.startswith("data:image/jpeg;base64,"))
            return httpx.Response(
                200,
                json={
                    "choices": [{"message": {"content": json.dumps(result)}}],
                    "usage": {
                        "prompt_tokens": 101,
                        "completion_tokens": 19,
                        "total_tokens": 120,
                    },
                },
            )

        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "original.jpg"
            image.write_bytes(b"original-source-bytes")
            http = httpx.Client(transport=httpx.MockTransport(handler))
            transport = JournalVisionClient("http://vision/v1", "key", "vision-model", 30, http)
            result_object = DailyPhotoRescueVisionClient(transport).extract(image)

        payload = result_object.api_payload("9router-openai-compatible", "vision-model")
        self.assertEqual("DAILY_PHOTO", payload["result"]["classification"])
        self.assertEqual("10:31", payload["result"]["capture_time"])
        self.assertEqual(PROMPT_VERSION, payload["prompt_version"])
        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(120, payload["usage"]["total_tokens"])

    def test_invalid_structured_response_is_terminal_at_vision_layer(self):
        def handler(_request: httpx.Request) -> httpx.Response:
            return httpx.Response(
                200,
                json={"choices": [{"message": {"content": json.dumps({
                    "classification": "MAYBE_DAILY",
                    "machine": "invented",
                    "ambiguities": "not-an-array",
                })}}]},
            )

        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "original.jpg"
            image.write_bytes(b"image")
            http = httpx.Client(transport=httpx.MockTransport(handler))
            transport = JournalVisionClient("http://vision/v1", "key", "vision-model", 30, http)
            with self.assertRaises(VisionError) as raised:
                DailyPhotoRescueVisionClient(transport).extract(image)

        self.assertFalse(raised.exception.retryable)

    def test_non_daily_result_cannot_leak_extracted_daily_fields(self):
        extraction = DailyPhotoRescueExtraction.model_validate({
            "classification": "NON_DAILY_HOUR_METER",
            "machine": "T-INVENTED",
            "capture_date": "2026-09-28",
            "capture_time": "10:31",
            "ambiguities": [],
        })

        self.assertIsNone(extraction.machine)
        self.assertIsNone(extraction.capture_date)
        self.assertIsNone(extraction.capture_time)


if __name__ == "__main__":
    unittest.main()
