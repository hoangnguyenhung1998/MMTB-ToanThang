from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator


class Finding(BaseModel):
    code: str = Field(min_length=1, max_length=100)
    severity: Literal["INFO", "WARNING", "CRITICAL"]
    title: str = Field(min_length=1, max_length=255)
    description: str | None = Field(default=None, max_length=5000)
    evidence: dict[str, Any] | None = None
    suggested_action: str | None = Field(default=None, max_length=5000)
    confidence: float | None = Field(default=None, ge=0, le=1)


class ReconciliationResult(BaseModel):
    outcome: Literal["MATCHED", "WARNING", "EXCEPTION", "UNRESOLVED"]
    summary: str | None = Field(default=None, max_length=5000)
    confidence: float | None = Field(default=None, ge=0, le=1)
    findings: list[Finding] = Field(default_factory=list, max_length=100)

    @field_validator("findings")
    @classmethod
    def require_finding_for_exception(cls, findings: list[Finding], info):
        outcome = info.data.get("outcome")
        if outcome in {"WARNING", "EXCEPTION"} and not findings:
            raise ValueError("WARNING and EXCEPTION outcomes require at least one finding.")
        return findings

    def api_payload(self) -> dict:
        payload = self.model_dump(exclude_none=True)
        if self.outcome == "UNRESOLVED" and payload.get("confidence", 0) > 0.5:
            payload["confidence"] = 0.5
        return payload


class CommandResult(BaseModel):
    summary: str = Field(min_length=1, max_length=10000)
    details: dict[str, Any] = Field(default_factory=dict)
    suggested_actions: list[str] = Field(default_factory=list, max_length=50)

    def api_payload(self) -> dict:
        return {
            "summary": self.summary,
            "result": {
                "details": self.details,
                "suggested_actions": self.suggested_actions,
            },
        }
