from __future__ import annotations

from datetime import date, time
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


class DailyPhotoRescueExtraction(BaseModel):
    model_config = ConfigDict(extra="forbid")

    classification: Literal[
        "DAILY_PHOTO",
        "NON_DAILY_HOUR_METER",
        "NON_DAILY_OTHER",
        "UNKNOWN",
    ]
    machine: str | None = Field(default=None, max_length=100)
    capture_date: date | None = None
    capture_time: time | None = None
    ambiguities: list[str] = Field(default_factory=list, max_length=20)

    @field_validator("machine", mode="before")
    @classmethod
    def preserve_visible_machine_literal(cls, value: object) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text or None

    @field_validator("ambiguities", mode="before")
    @classmethod
    def require_ambiguity_list(cls, value: object) -> list[object]:
        if value is None:
            return []
        if not isinstance(value, list):
            raise ValueError("ambiguities must be an array")
        return value

    @model_validator(mode="after")
    def discard_non_daily_extraction_fields(self) -> "DailyPhotoRescueExtraction":
        if self.classification != "DAILY_PHOTO":
            self.machine = None
            self.capture_date = None
            self.capture_time = None
        return self

    def api_payload(self) -> dict:
        payload = self.model_dump(mode="json")
        if self.capture_time is not None:
            payload["capture_time"] = self.capture_time.strftime("%H:%M")
        return payload
