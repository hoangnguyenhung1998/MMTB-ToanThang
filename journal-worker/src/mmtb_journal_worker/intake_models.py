from __future__ import annotations

import re
from typing import Any
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator


def clean_text(value: Any) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text or None


class MachineIntakeExtraction(BaseModel):
    model_config = ConfigDict(extra="ignore")
    company: str | None = None
    chassis_no: str | None = None
    engine_no: str | None = None
    machine_type: str | None = None
    model_name: str | None = None
    manufacture_year: int | None = Field(default=None, ge=1900, le=2100)
    confidence: float = Field(default=0.0, ge=0, le=1)
    raw_text: str = ""
    review_flags: list[str] = Field(default_factory=list)

    @field_validator("company")
    @classmethod
    def company_value(cls, value: str | None) -> str | None:
        value = clean_text(value)
        return value.upper() if value and value.upper() in {"VINCONS", "VINALPHA"} else None

    @field_validator("chassis_no", "engine_no")
    @classmethod
    def identifier(cls, value: str | None) -> str | None:
        value = clean_text(value)
        return re.sub(r"[^A-Z0-9-]", "", value.upper()) if value else None

    @field_validator("machine_type", "model_name")
    @classmethod
    def text_value(cls, value: str | None) -> str | None:
        return clean_text(value)

    @model_validator(mode="after")
    def add_safety_flags(self) -> "MachineIntakeExtraction":
        flags = list(self.review_flags)
        for field in ("chassis_no", "engine_no"):
            value = getattr(self, field)
            if not value:
                flags.append(f"MISSING_{field.upper()}")
        if self.confidence < 0.85:
            flags.append("LOW_CONFIDENCE")
        self.review_flags = list(dict.fromkeys(flags))
        return self

    def api_payload(self) -> dict:
        return {
            "confidence": self.confidence,
            "extraction": {
                "company": self.company,
                "chassis_no": self.chassis_no,
                "engine_no": self.engine_no,
                "machine_type": self.machine_type,
                "model_name": self.model_name,
                "manufacture_year": self.manufacture_year,
            },
            "review_flags": self.review_flags,
            "raw_text": self.raw_text,
        }
