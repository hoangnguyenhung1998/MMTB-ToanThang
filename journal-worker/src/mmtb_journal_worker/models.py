from __future__ import annotations

from datetime import datetime
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


def normalize_time(value: str | None) -> str | None:
    if value is None or not value.strip():
        return None
    cleaned = value.strip().lower().replace("h", ":").replace(".", ":")
    for pattern in ("%H:%M:%S", "%H:%M"):
        try:
            return datetime.strptime(cleaned, pattern).strftime("%H:%M:%S")
        except ValueError:
            continue
    raise ValueError("time must use HH:MM or HH:MM:SS")


class JournalRow(BaseModel):
    model_config = ConfigDict(extra="ignore")

    row_number: int | None = Field(default=None, ge=1)
    work_date: str | None = None
    start_time: str | None = None
    end_time: str | None = None
    total_minutes: int | None = Field(default=None, ge=0, le=1440)
    work_content: str | None = None
    error_explanation: str | None = None
    quantity: float | None = Field(default=None, ge=0)
    unit: str | None = None
    work_location: str | None = None
    operator_name: str | None = None
    confidence: float = Field(default=0.0, ge=0, le=1)
    raw_data: dict[str, Any] | None = None

    @field_validator("raw_data", mode="before")
    @classmethod
    def normalize_raw_data(cls, value: object) -> object:
        if isinstance(value, str):
            return {"text": value}
        return value

    @field_validator("start_time", "end_time", mode="before")
    @classmethod
    def validate_time(cls, value: object) -> str | None:
        return normalize_time(None if value is None else str(value))

    @model_validator(mode="after")
    def calculate_total_minutes(self) -> "JournalRow":
        if self.total_minutes is not None or not self.start_time or not self.end_time:
            return self
        start = datetime.strptime(self.start_time, "%H:%M:%S")
        end = datetime.strptime(self.end_time, "%H:%M:%S")
        minutes = int((end - start).total_seconds() // 60)
        self.total_minutes = minutes if minutes >= 0 else minutes + 1440
        return self


class JournalExtraction(BaseModel):
    model_config = ConfigDict(extra="ignore")

    asset_code: str | None = None
    confidence: float = Field(default=0.0, ge=0, le=1)
    raw_text: str = ""
    rows: list[JournalRow] = Field(min_length=1)

    @field_validator("asset_code")
    @classmethod
    def normalize_asset_code(cls, value: str | None) -> str | None:
        if value is None or not value.strip():
            return None
        return value.strip().upper().replace(" ", "")

    def normalize(self, reference_year: int | None = None) -> "JournalExtraction":
        from .normalizer import normalize_extraction
        return normalize_extraction(self, reference_year)

    def api_payload(self) -> dict[str, Any]:
        rows = []
        for index, row in enumerate(self.rows, start=1):
            item = row.model_dump(mode="json", exclude_none=True)
            item["row_number"] = index
            item.setdefault("raw_data", {})
            rows.append(item)
        return {
            "asset_code": self.asset_code,
            "confidence": round(self.confidence, 4),
            "raw_text": self.raw_text,
            "rows": rows,
        }
