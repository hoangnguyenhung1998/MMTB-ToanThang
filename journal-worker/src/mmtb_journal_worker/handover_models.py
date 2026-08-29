from __future__ import annotations

from datetime import date
from typing import Any
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator
from .intake_models import clean_text


class MachineHandoverExtraction(BaseModel):
    model_config = ConfigDict(extra="ignore")
    asset_code: str | None = None
    handover_date: date | None = None
    project_text: str | None = None
    command_center_text: str | None = None
    machine_type: str | None = None
    model_name: str | None = None
    meter_hours: float | None = Field(default=None, ge=0)
    gps_status: str | None = None
    handover_people: list[str] = Field(default_factory=list)
    technical_findings: list[str] = Field(default_factory=list)
    confidence: float = Field(default=0.0, ge=0, le=1)
    raw_text: str = ""
    review_flags: list[str] = Field(default_factory=list)

    @field_validator("asset_code", "project_text", "command_center_text", "machine_type", "model_name", "gps_status")
    @classmethod
    def text_value(cls, value: Any) -> str | None:
        return clean_text(value)

    @model_validator(mode="after")
    def required_flags(self) -> "MachineHandoverExtraction":
        flags = list(self.review_flags)
        if not self.asset_code: flags.append("MISSING_ASSET_CODE")
        if not self.handover_date: flags.append("MISSING_HANDOVER_DATE")
        if self.confidence < 0.80: flags.append("LOW_CONFIDENCE")
        self.review_flags = list(dict.fromkeys(flags))
        return self

    def api_payload(self) -> dict:
        return {
            "confidence": self.confidence,
            "extraction": {
                "asset_code": self.asset_code,
                "handover_date": self.handover_date.isoformat() if self.handover_date else None,
                "project_text": self.project_text,
                "command_center_text": self.command_center_text,
                "machine_type": self.machine_type,
                "model_name": self.model_name,
                "meter_hours": self.meter_hours,
                "gps_status": self.gps_status,
                "handover_people": self.handover_people,
                "technical_findings": self.technical_findings,
            },
            "review_flags": self.review_flags,
            "raw_text": self.raw_text,
        }
