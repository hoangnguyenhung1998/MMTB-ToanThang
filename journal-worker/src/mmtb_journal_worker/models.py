from __future__ import annotations

import json
import re
from datetime import datetime
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


SUPERSCRIPT_DIGITS = str.maketrans("⁰¹²³⁴⁵⁶⁷⁸⁹", "0123456789")
NUMBER_PATTERN = re.compile(r"[-+]?\d+(?:[.,]\d+)?")


def _text(value: object) -> str | None:
    if value is None:
        return None
    if isinstance(value, str):
        result = value.strip()
    elif isinstance(value, (dict, list, tuple)):
        result = json.dumps(value, ensure_ascii=False)
    else:
        result = str(value).strip()
    return result or None


def _number(value: object) -> tuple[float | None, bool]:
    if value is None or value == "":
        return None, False
    if isinstance(value, bool):
        return None, True
    if isinstance(value, (int, float)):
        number = float(value)
        return (number if number >= 0 else None), number < 0

    text = str(value).strip().translate(SUPERSCRIPT_DIGITS)
    match = NUMBER_PATTERN.search(text.replace(" ", ""))
    if not match:
        return None, True
    try:
        number = float(match.group(0).replace(",", "."))
    except ValueError:
        return None, True
    return (number if number >= 0 else None), True


def _confidence(value: object) -> float:
    if value is None or value == "":
        return 0.0
    text = str(value).strip().replace(",", ".")
    percent = "%" in text
    match = NUMBER_PATTERN.search(text)
    if not match:
        return 0.0
    try:
        number = float(match.group(0).replace(",", "."))
    except ValueError:
        return 0.0
    if percent or number > 1:
        number /= 100
    return max(0.0, min(1.0, number))


def normalize_time(value: object) -> str | None:
    if value is None:
        return None
    cleaned = str(value).strip().lower().translate(SUPERSCRIPT_DIGITS)
    if not cleaned:
        return None

    cleaned = cleaned.replace("giờ", "h").replace("gio", "h")
    cleaned = re.sub(r"\s+", "", cleaned)
    cleaned = cleaned.replace(".", ":").replace("h", ":")

    if re.fullmatch(r"\d{1,2}", cleaned):
        cleaned = f"{cleaned}:00"
    elif re.fullmatch(r"\d{3,4}", cleaned):
        cleaned = f"{cleaned[:-2]}:{cleaned[-2:]}"
    elif cleaned.endswith(":"):
        cleaned += "00"

    match = re.fullmatch(r"(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?", cleaned)
    if not match:
        return None
    hour, minute, second = (int(part or 0) for part in match.groups())
    if hour > 23 or minute > 59 or second > 59:
        return None
    return f"{hour:02d}:{minute:02d}:{second:02d}"


def _duration_minutes(value: object) -> tuple[int | None, bool]:
    if value is None or value == "":
        return None, False
    if isinstance(value, bool):
        return None, True
    if isinstance(value, (int, float)):
        minutes = int(round(float(value)))
        return (minutes if 0 <= minutes <= 1440 else None), False

    text = str(value).strip().lower().translate(SUPERSCRIPT_DIGITS).replace(",", ".")
    hour_match = re.search(r"(\d+(?:\.\d+)?)\s*(?:h|giờ|gio)", text)
    minute_match = re.search(r"(\d+)\s*(?:p|phút|phut|min)", text)
    if hour_match:
        minutes = int(round(float(hour_match.group(1)) * 60))
        if minute_match:
            minutes += int(minute_match.group(1))
        return (minutes if minutes <= 1440 else None), True
    if minute_match:
        minutes = int(minute_match.group(1))
        return (minutes if minutes <= 1440 else None), True

    match = NUMBER_PATTERN.search(text)
    if not match:
        return None, True
    minutes = int(round(float(match.group(0).replace(",", "."))))
    return (minutes if 0 <= minutes <= 1440 else None), True


def _raw_data(value: object) -> dict[str, Any]:
    if isinstance(value, dict):
        return dict(value)
    if value is None:
        return {}
    if isinstance(value, str):
        return {"text": value}
    return {"value": value}


def _append_flag(raw: dict[str, Any], flag: str) -> None:
    flags = raw.get("normalization_flags")
    if not isinstance(flags, list):
        flags = []
    if flag not in flags:
        flags.append(flag)
    raw["normalization_flags"] = flags


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

    @model_validator(mode="before")
    @classmethod
    def sanitize_vision_row(cls, value: object) -> dict[str, Any]:
        if not isinstance(value, dict):
            return {
                "confidence": 0.0,
                "raw_data": {
                    "value": value,
                    "normalization_flags": ["INVALID_ROW_SHAPE"],
                },
            }

        data = dict(value)
        raw = _raw_data(data.get("raw_data"))

        row_number, row_changed = _number(data.get("row_number"))
        data["row_number"] = int(row_number) if row_number and row_number >= 1 else None
        if row_changed and data.get("row_number") is None:
            _append_flag(raw, "INVALID_ROW_NUMBER")

        quantity, quantity_changed = _number(data.get("quantity"))
        data["quantity"] = quantity
        if quantity_changed:
            _append_flag(raw, "COERCED_QUANTITY" if quantity is not None else "INVALID_QUANTITY")

        total_minutes, duration_changed = _duration_minutes(data.get("total_minutes"))
        data["total_minutes"] = total_minutes
        if duration_changed:
            _append_flag(raw, "COERCED_DURATION" if total_minutes is not None else "INVALID_DURATION")

        for field in (
            "work_date",
            "work_content",
            "error_explanation",
            "unit",
            "work_location",
            "operator_name",
        ):
            data[field] = _text(data.get(field))

        for field in ("start_time", "end_time"):
            original = data.get(field)
            data[field] = normalize_time(original)
            if original not in (None, "") and data[field] is None:
                _append_flag(raw, f"INVALID_{field.upper()}")

        data["confidence"] = _confidence(data.get("confidence"))
        data["raw_data"] = raw
        return data

    @model_validator(mode="after")
    def calculate_total_minutes(self) -> "JournalRow":
        if not self.start_time or not self.end_time:
            return self
        start = datetime.strptime(self.start_time, "%H:%M:%S")
        end = datetime.strptime(self.end_time, "%H:%M:%S")
        minutes = int((end - start).total_seconds() // 60)
        calculated = minutes if minutes >= 0 else minutes + 1440
        if self.total_minutes is not None and self.total_minutes != calculated:
            raw = self.raw_data or {}
            raw["vision_total_minutes"] = self.total_minutes
            _append_flag(raw, "RECALCULATED_DURATION")
            self.raw_data = raw
        self.total_minutes = calculated
        return self


class JournalExtraction(BaseModel):
    model_config = ConfigDict(extra="ignore")

    asset_code: str | None = None
    confidence: float = Field(default=0.0, ge=0, le=1)
    raw_text: str = ""
    rows: list[JournalRow] = Field(min_length=1)

    @model_validator(mode="before")
    @classmethod
    def sanitize_vision_document(cls, value: object) -> dict[str, Any]:
        if not isinstance(value, dict):
            return {"confidence": 0.0, "raw_text": _text(value) or "", "rows": []}
        data = dict(value)
        rows = data.get("rows")
        if isinstance(rows, dict):
            rows = [rows]
        data["rows"] = rows if isinstance(rows, list) else []
        data["confidence"] = _confidence(data.get("confidence"))
        data["raw_text"] = _text(data.get("raw_text")) or ""
        return data

    @field_validator("asset_code", mode="before")
    @classmethod
    def normalize_asset_code(cls, value: object) -> str | None:
        text = _text(value)
        if text is None:
            return None
        return re.sub(r"\s+", "", text.upper())

    def enforce_machine_catalog(self, machine_codes: list[str]) -> "JournalExtraction":
        if self.asset_code is None:
            return self
        catalog = {re.sub(r"\\s+", "", str(code).upper()) for code in machine_codes}
        if self.asset_code in catalog:
            return self

        unmatched = self.asset_code
        self.asset_code = None
        self.confidence = min(self.confidence, 0.79)
        for row in self.rows:
            raw = row.raw_data or {}
            raw["vision_asset_code"] = unmatched
            _append_flag(raw, "UNMATCHED_ASSET_CODE")
            row.raw_data = raw
            row.confidence = min(row.confidence, 0.49)
        return self

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
