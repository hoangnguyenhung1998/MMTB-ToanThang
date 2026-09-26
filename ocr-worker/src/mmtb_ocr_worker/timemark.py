from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from pathlib import Path
import time
from collections.abc import Callable

import cv2

from rapidocr import RapidOCR

from .imaging import enhance_for_ocr, flatten_ocr_result, read_image, region, rotate
from .parser import (
    AssetMatcher,
    parse_date_candidates,
    parse_location,
    parse_operator,
    parse_phone,
    parse_time_evidence,
)


@dataclass(frozen=True)
class TimeMarkResult:
    asset_code: str | None
    captured_date: str | None
    captured_time: str | None
    operator_name: str | None
    phone: str | None
    work_location: str | None
    confidence: float
    raw_text: str
    image_fingerprint: str | None = None
    candidate_metadata: dict | None = None

    def api_payload(self) -> dict:
        return {
            "date": self.captured_date,
            "time": self.captured_time,
            "asset_code": self.asset_code,
            "operator_name": self.operator_name,
            "phone": self.phone,
            "work_location": self.work_location,
            "confidence": round(self.confidence, 4),
            "raw_text": self.raw_text,
            "image_fingerprint": self.image_fingerprint,
            "candidate_metadata": self.candidate_metadata or {},
        }


class TimeMarkRecognizer:
    SOURCE_TIERS = {
        0: "PRIMARY_TIMEMARK_0",
        1: "OTHER_0_DEG_TIMEMARK",
        2: "0_DEG_WIDER_FALLBACK",
        3: "ROTATION_FALLBACK",
    }

    def __init__(self, asset_codes: list[str], engine: RapidOCR | None = None):
        self.matcher = AssetMatcher(asset_codes)
        self.engine = engine or RapidOCR()

    def recognize(
        self,
        path: Path,
        progress: Callable[[str, int, str, int | None], None] | None = None,
        focus: list[str] | None = None,
        received_date: date | None = None,
    ) -> TimeMarkResult:
        image = read_image(path)
        best_observed_asset: tuple[str | None, float, str] = (None, 0.0, "")
        candidates: dict[str, dict[int, set]] = {
            "machine": {},
            "date": {},
            "time": {},
        }
        ambiguous_date_priorities: set[int] = set()
        operator_name = None
        phone = None
        work_location = None
        confidences: list[float] = []
        debug_parts: list[str] = []
        machine_evidence: list[dict] = []
        date_evidence: list[dict] = []
        time_evidence: list[dict] = []
        discarded_date_candidates: set[str] = set()
        requested = set(focus or ["machine", "date", "time"])
        locked: set[str] = set()
        ocr_pass_count = 0
        stages_executed: list[str] = []

        def add_candidate(field: str, priority: int, value) -> None:
            candidates[field].setdefault(priority, set()).add(value)

        def resolve(field: str) -> tuple[object | None, bool, set, int | None]:
            field_candidates = candidates[field]
            candidate_priority = min(field_candidates) if field_candidates else None
            if field == "date" and ambiguous_date_priorities:
                ambiguous_priority = min(ambiguous_date_priorities)
                if candidate_priority is None or ambiguous_priority <= candidate_priority:
                    values = field_candidates.get(ambiguous_priority, set())
                    return None, True, values, ambiguous_priority
            if candidate_priority is None:
                return None, False, set(), None
            values = field_candidates[candidate_priority]
            return (next(iter(values)) if len(values) == 1 else None), len(values) > 1, values, candidate_priority

        def lock_resolved_fields() -> None:
            for field in requested:
                value, conflict, _, _ = resolve(field)
                if value is not None or conflict:
                    locked.add(field)

        def stage_is_terminal() -> bool:
            temporal = requested.intersection({"date", "time"})
            # Machine evidence may be collected while finding Date/Time, but it
            # never authorizes extra fallback/rotation by itself.
            return not temporal or temporal.issubset(locked)

        def stage_specs(angle: int, names: tuple[str, ...]) -> list[tuple[int, str, object]]:
            rotated = rotate(image, angle)
            crops = {
                "primary_timemark": region(rotated, 0.00, 0.50, 0.82, 1.00),
                "time_date": region(rotated, 0.00, 0.48, 0.62, 0.73),
                "left_overlay": region(rotated, 0.00, 0.25, 0.75, 0.92),
                "lower_full": region(rotated, 0.00, 0.38, 1.00, 1.00),
                "full": rotated,
            }
            return [(angle, name, crops[name]) for name in names]

        overlay_names = ("left_overlay",) if requested == {"machine"} else ("time_date", "left_overlay")
        stages = [
            ("PRIMARY_TIMEMARK_0", 0, [(0, ("primary_timemark",))]),
            ("OTHER_0_DEG_TIMEMARK", 1, [(0, overlay_names)]),
            ("0_DEG_WIDER_FALLBACK", 2, [(0, ("lower_full", "full"))]),
            ("ROTATION_FALLBACK", 3, [
                (angle, ("left_overlay", "lower_full", "full")) for angle in (180, 90, 270)
            ]),
        ]

        for stage_name, priority, stage_sources in stages:
            stages_executed.append(stage_name)
            specs = [spec for angle, names in stage_sources for spec in stage_specs(angle, names)]
            for angle, region_name, candidate in specs:
                if progress is not None:
                    progress("started", angle, region_name, None)
                engine_started = time.monotonic()
                try:
                    texts, scores = flatten_ocr_result(self.engine(enhance_for_ocr(candidate)))
                finally:
                    if progress is not None:
                        progress("finished", angle, region_name, round((time.monotonic() - engine_started) * 1000))
                ocr_pass_count += 1
                text = "\n".join(texts)
                if not text.strip():
                    continue
                confidence = sum(scores) / len(scores) if scores else 0.0
                confidences.append(confidence)
                debug_parts.append(f"[{angle}deg/{region_name}]\n{text}")

                asset = self.matcher.match(text)
                if asset[1] > best_observed_asset[1]:
                    best_observed_asset = asset
                section_asset_candidates = self.matcher.valid_matches(text)
                if "machine" not in locked:
                    for value in section_asset_candidates:
                        add_candidate("machine", priority, value)
                machine_evidence.extend({
                    "value": value,
                    "accepted": True,
                    "reason": "EXACT_CATALOG_MATCH",
                    "rotation": angle,
                    "region": region_name,
                    "preprocessing": "ENHANCED",
                    "priority": priority,
                    "source_tier": stage_name,
                } for value in sorted(section_asset_candidates))
                dates, date_is_ambiguous = parse_date_candidates(text)
                if "date" not in locked and date_is_ambiguous:
                    ambiguous_date_priorities.add(priority)
                    date_evidence.append({
                        "accepted": False,
                        "reason": "AMBIGUOUS_NUMERIC_DATE",
                        "rotation": angle,
                        "region": region_name,
                        "preprocessing": "ENHANCED",
                        "priority": priority,
                        "source_tier": stage_name,
                    })
                for candidate_date in dates:
                    accepted = received_date is None or candidate_date <= received_date
                    date_evidence.append({
                        "value": candidate_date.isoformat(),
                        "accepted": accepted,
                        "reason": "PARSED" if accepted else "AFTER_RECEIVED_DATE",
                        "rotation": angle,
                        "region": region_name,
                        "preprocessing": "ENHANCED",
                        "priority": priority,
                        "source_tier": stage_name,
                    })
                    if accepted:
                        if "date" not in locked:
                            add_candidate("date", priority, candidate_date)
                    else:
                        discarded_date_candidates.add(candidate_date.isoformat())
                parsed_times, parsed_evidence = parse_time_evidence(
                    text,
                    allow_dash=region_name in {"primary_timemark", "time_date", "left_overlay"},
                    allow_trailing_artifact=region_name in {"primary_timemark", "time_date", "left_overlay"},
                    allow_dot=region_name in {"primary_timemark", "time_date", "left_overlay"},
                )
                normalized_text = text.upper()
                capture_context = (
                    region_name in {"primary_timemark", "time_date", "left_overlay"}
                    or bool(dates)
                    or "TIMEMARK" in normalized_text
                    or "TIME MARK" in normalized_text
                    or "TAN CA" in normalized_text
                )
                for evidence in parsed_evidence:
                    item = {
                        **evidence,
                        "rotation": angle,
                        "region": region_name,
                        "preprocessing": "ENHANCED",
                        "priority": priority,
                        "source_tier": stage_name,
                    }
                    if evidence.get("accepted") and not capture_context:
                        item["accepted"] = False
                        item["reason"] = "UNTRUSTED_CONTEXT"
                    time_evidence.append(item)
                if capture_context and "time" not in locked:
                    for parsed_time in parsed_times:
                        add_candidate("time", priority, parsed_time)
                operator_name = operator_name or parse_operator(text)
                phone = phone or parse_phone(text)
                work_location = work_location or parse_location(text)
            lock_resolved_fields()
            if stage_is_terminal():
                break

        selected_asset, machine_conflict, machine_candidates, machine_priority = resolve("machine")
        captured_date, date_conflict, date_candidates, date_priority = resolve("date")
        captured_time, time_conflict, time_candidates, time_priority = resolve("time")
        if selected_asset is not None:
            best_asset = (selected_asset, 1.0, selected_asset)
        elif machine_conflict:
            best_asset = (None, 0.0, "")
        else:
            best_asset = best_observed_asset
        average_ocr = sum(confidences) / len(confidences) if confidences else 0.0
        confidence = min(average_ocr, best_asset[1]) if best_asset[0] else average_ocr
        return TimeMarkResult(
            image_fingerprint=self.fingerprint(image),
            asset_code=best_asset[0],
            captured_date=captured_date.isoformat() if captured_date else None,
            captured_time=captured_time.isoformat() if captured_time else None,
            operator_name=operator_name,
            phone=phone,
            work_location=work_location,
            confidence=confidence,
            raw_text="\n\n".join(debug_parts),
            candidate_metadata={
                "machine_candidates": sorted(machine_candidates),
                "machine_evidence": machine_evidence,
                "date_candidates": sorted(candidate.isoformat() for candidate in date_candidates),
                "time_candidates": sorted(candidate.isoformat() for candidate in time_candidates),
                "date_evidence": date_evidence,
                "time_evidence": time_evidence,
                "discarded_date_candidates": sorted(discarded_date_candidates),
                "ambiguous_date": date_conflict and bool(ambiguous_date_priorities),
                "source_priority": self.SOURCE_TIERS,
                "selected_priorities": {
                    "machine": machine_priority,
                    "date": date_priority,
                    "time": time_priority,
                },
                "ocr_pass_count": ocr_pass_count,
                "stages_executed": stages_executed,
                "conflicts": [
                    name for name, conflict in (
                        ("machine", machine_conflict),
                        ("date", date_conflict),
                        ("time", time_conflict),
                    ) if conflict
                ],
            },
        )

    @staticmethod
    def fingerprint(image) -> str:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
        small = cv2.resize(gray, (9, 8), interpolation=cv2.INTER_AREA)
        bits = (small[:, 1:] > small[:, :-1]).flatten()
        return f"{int(''.join('1' if bit else '0' for bit in bits), 2):016x}"
