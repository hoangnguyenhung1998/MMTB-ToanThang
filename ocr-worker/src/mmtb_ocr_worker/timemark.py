from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import time
from collections.abc import Callable

import cv2

from rapidocr import RapidOCR

from .imaging import enhance_for_ocr, flatten_ocr_result, read_image, region, rotate
from .parser import AssetMatcher, parse_date, parse_location, parse_operator, parse_phone, parse_time


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
        best_asset: tuple[str | None, float, str] = (None, 0.0, "")
        captured_date = None
        captured_time = None
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
                if asset[1] > best_asset[1]:
                    best_asset = asset
                captured_date = captured_date or parse_date(text)
                # A time candidate needs date context; don't mistake an isolated meter for time.
                if parse_date(text):
                    captured_time = captured_time or parse_time(text)
                operator_name = operator_name or parse_operator(text)
                phone = phone or parse_phone(text)
                work_location = work_location or parse_location(text)

                if self._requested_fields_found(requested, best_asset, captured_date, captured_time):
                    break
            if (focus and self._requested_fields_found(requested, best_asset, captured_date, captured_time)) or (
                not focus and captured_date and captured_time
            ):
                break

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
        )

    @staticmethod
    def _requested_fields_found(requested, best_asset, captured_date, captured_time) -> bool:
        return bool(
            ("machine" not in requested or (best_asset[0] and best_asset[1] >= 0.85))
            and ("date" not in requested or captured_date)
            and ("time" not in requested or captured_time)
        )

    @staticmethod
    def fingerprint(image) -> str:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
        small = cv2.resize(gray, (9, 8), interpolation=cv2.INTER_AREA)
        bits = (small[:, 1:] > small[:, :-1]).flatten()
        return f"{int(''.join('1' if bit else '0' for bit in bits), 2):016x}"
