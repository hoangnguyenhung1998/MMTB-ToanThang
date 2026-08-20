from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

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
        }


class TimeMarkRecognizer:
    def __init__(self, asset_codes: list[str], engine: RapidOCR | None = None):
        self.matcher = AssetMatcher(asset_codes)
        self.engine = engine or RapidOCR()

    def recognize(self, path: Path) -> TimeMarkResult:
        image = read_image(path)
        best_asset: tuple[str | None, float, str] = (None, 0.0, "")
        captured_date = None
        captured_time = None
        operator_name = None
        phone = None
        work_location = None
        confidences: list[float] = []
        debug_parts: list[str] = []

        for angle in (0, 180):
            rotated = rotate(image, angle)
            candidates = [
                ("asset", region(rotated, 0.00, 0.28, 0.50, 0.58)),
                ("time_date", region(rotated, 0.00, 0.48, 0.62, 0.73)),
                ("left_overlay", region(rotated, 0.00, 0.25, 0.75, 0.92)),
                ("lower_full", region(rotated, 0.00, 0.38, 1.00, 1.00)),
                ("full", rotated),
            ]
            for region_name, candidate in candidates:
                texts, scores = flatten_ocr_result(self.engine(enhance_for_ocr(candidate)))
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
                captured_time = captured_time or parse_time(text)
                operator_name = operator_name or parse_operator(text)
                phone = phone or parse_phone(text)
                work_location = work_location or parse_location(text)

                if (
                    best_asset[0]
                    and captured_date
                    and captured_time
                    and operator_name
                    and phone
                    and work_location
                ):
                    break
            if best_asset[0] and captured_date and captured_time and operator_name and phone and work_location:
                break

        average_ocr = sum(confidences) / len(confidences) if confidences else 0.0
        confidence = min(1.0, average_ocr * 0.6 + best_asset[1] * 0.4)
        return TimeMarkResult(
            asset_code=best_asset[0],
            captured_date=captured_date.isoformat() if captured_date else None,
            captured_time=captured_time.isoformat() if captured_time else None,
            operator_name=operator_name,
            phone=phone,
            work_location=work_location,
            confidence=confidence,
            raw_text="\n\n".join(debug_parts),
        )
