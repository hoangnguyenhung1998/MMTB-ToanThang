from __future__ import annotations

from dataclasses import dataclass
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
    parse_time_candidates,
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
    def __init__(self, asset_codes: list[str], engine: RapidOCR | None = None):
        self.matcher = AssetMatcher(asset_codes)
        self.engine = engine or RapidOCR()

    def recognize(
        self,
        path: Path,
        progress: Callable[[str, int, str, int | None], None] | None = None,
        focus: list[str] | None = None,
    ) -> TimeMarkResult:
        image = read_image(path)
        best_observed_asset: tuple[str | None, float, str] = (None, 0.0, "")
        asset_candidates: set[str] = set()
        date_candidates = set()
        time_candidates = set()
        ambiguous_date = False
        operator_name = None
        phone = None
        work_location = None
        confidences: list[float] = []
        debug_parts: list[str] = []
        requested = set(focus or ["machine", "date", "time"])

        for angle in (0, 180, 90, 270):
            rotated = rotate(image, angle)
            candidates = [
                ("asset", region(rotated, 0.00, 0.28, 0.50, 0.58)),
                ("time_date", region(rotated, 0.00, 0.48, 0.62, 0.73)),
                ("left_overlay", region(rotated, 0.00, 0.25, 0.75, 0.92)),
                ("lower_full", region(rotated, 0.00, 0.38, 1.00, 1.00)),
                ("full", rotated),
            ]
            if focus:
                allowed = {"left_overlay", "lower_full", "full"}
                if "machine" in requested:
                    allowed.add("asset")
                if requested.intersection({"date", "time"}):
                    allowed.add("time_date")
                candidates = [candidate for candidate in candidates if candidate[0] in allowed]
            for region_name, candidate in candidates:
                if progress is not None:
                    progress("started", angle, region_name, None)
                engine_started = time.monotonic()
                try:
                    texts, scores = flatten_ocr_result(self.engine(enhance_for_ocr(candidate)))
                finally:
                    if progress is not None:
                        progress("finished", angle, region_name, round((time.monotonic() - engine_started) * 1000))
                text = "\n".join(texts)
                if not text.strip():
                    continue
                confidence = sum(scores) / len(scores) if scores else 0.0
                confidences.append(confidence)
                debug_parts.append(f"[{angle}deg/{region_name}]\n{text}")

                asset = self.matcher.match(text)
                if asset[1] > best_observed_asset[1]:
                    best_observed_asset = asset
                asset_candidates.update(self.matcher.valid_matches(text))
                dates, date_is_ambiguous = parse_date_candidates(text)
                date_candidates.update(dates)
                ambiguous_date = ambiguous_date or date_is_ambiguous
                time_candidates.update(parse_time_candidates(text, allow_dash=region_name == "time_date"))
                operator_name = operator_name or parse_operator(text)
                phone = phone or parse_phone(text)
                work_location = work_location or parse_location(text)

        captured_date = next(iter(date_candidates)) if len(date_candidates) == 1 and not ambiguous_date else None
        captured_time = next(iter(time_candidates)) if len(time_candidates) == 1 else None
        if len(asset_candidates) == 1:
            selected_asset = next(iter(asset_candidates))
            best_asset = (selected_asset, 1.0, selected_asset)
        elif len(asset_candidates) > 1:
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
                "machine_candidates": sorted(asset_candidates),
                "date_candidates": sorted(candidate.isoformat() for candidate in date_candidates),
                "time_candidates": sorted(candidate.isoformat() for candidate in time_candidates),
                "conflicts": [
                    name for name, conflict in (
                        ("machine", len(asset_candidates) > 1),
                        ("date", len(date_candidates) > 1 or ambiguous_date),
                        ("time", len(time_candidates) > 1),
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
