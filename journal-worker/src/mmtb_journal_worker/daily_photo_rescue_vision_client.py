from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

from pydantic import ValidationError

from .daily_photo_rescue_models import DailyPhotoRescueExtraction
from .vision_client import JournalVisionClient, VisionError


PROMPT_VERSION = "daily_photo_rescue_v1"
SCHEMA_VERSION = "daily_photo_rescue_v1"


@dataclass(frozen=True)
class DailyPhotoRescueVisionResult:
    extraction: DailyPhotoRescueExtraction
    raw_response: str
    usage: dict[str, Any]

    def api_payload(self, provider: str, model: str) -> dict[str, Any]:
        return {
            "provider": provider,
            "model": model,
            "prompt_version": PROMPT_VERSION,
            "schema_version": SCHEMA_VERSION,
            "result": self.extraction.api_payload(),
            "raw_response": self.raw_response,
            "usage": self.usage,
        }


class DailyPhotoRescueVisionClient:
    def __init__(self, transport: JournalVisionClient):
        self.transport = transport

    def extract(self, image_path: Path) -> DailyPhotoRescueVisionResult:
        response = self.transport.request_json(self._build_payload(image_path))
        try:
            extraction = DailyPhotoRescueExtraction.model_validate(response.data)
        except ValidationError as exc:
            raise VisionError(
                f"Vision response failed {SCHEMA_VERSION} validation: {exc}",
                retryable=False,
            ) from exc
        return DailyPhotoRescueVisionResult(extraction, response.raw_content, response.usage)

    def _build_payload(self, image_path: Path) -> dict[str, Any]:
        instruction = (
            f"Prompt version {PROMPT_VERSION}. Inspect only the attached ORIGINAL source image. "
            "Do not use or infer from OCR text, previous candidates, rotations, message time, received time, "
            "sender identity, filenames, or any external metadata. Classify first. "
            "Use DAILY_PHOTO only when the image visibly contains a Daily/TimeMark capture overlay. "
            "For DAILY_PHOTO, extract only the visibly readable machine code, capture date and capture time. "
            "Preserve the literal machine code; do not fuzzy-correct it and do not invent a prefix or missing character. "
            "Return null for every field that is not confidently visible. Date format is YYYY-MM-DD and time format is HH:MM. "
            "Put every unresolved conflict or uncertainty in ambiguities. Distinguish the TimeMark capture overlay from "
            "hour-meter, odometer, trip, kilometre or instrument counter values. Use NON_DAILY_HOUR_METER only for an "
            "obvious hour-meter/counter photo; do not extract the counter value. Use NON_DAILY_OTHER only when the image is "
            "clearly not a Daily Photo. A screen, dashboard, instrument or number alone is not enough to classify non-daily. "
            "Use UNKNOWN whenever classification is uncertain. Return only JSON with exactly this schema: "
            "{classification: DAILY_PHOTO|NON_DAILY_HOUR_METER|NON_DAILY_OTHER|UNKNOWN, "
            "machine: string|null, capture_date: YYYY-MM-DD|null, capture_time: HH:MM|null, ambiguities: string[]}"
        )
        return {
            "model": self.transport.model,
            "messages": [{
                "role": "user",
                "content": [
                    {"type": "text", "text": instruction},
                    {
                        "type": "image_url",
                        "image_url": {"url": self.transport.image_data_url(image_path)},
                    },
                ],
            }],
            "temperature": 0,
            "response_format": {"type": "json_object"},
        }
