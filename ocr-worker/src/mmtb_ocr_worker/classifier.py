from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path

from rapidocr import RapidOCR

from .imaging import enhance_for_ocr, flatten_ocr_result, read_image, rotate, table_line_score
from .parser import GENERIC_ASSET_PATTERN, PHONE_PATTERN, normalize_text


@dataclass(frozen=True)
class Classification:
    document_type: str
    confidence: float
    raw_text: str


def classify_text(text: str, table_score: float = 0.0, minimum_confidence: float = 0.70) -> Classification:
    normalized = normalize_text(text)
    daily_score = 0.0
    journal_score = 0.0

    if "TIMEMARK" in normalized or "TIME MARK" in normalized:
        daily_score += 4
    if "HO TEN" in normalized:
        daily_score += 1
    if "CONG TY" in normalized:
        daily_score += 1
    if PHONE_PATTERN.search(text):
        daily_score += 1
    if GENERIC_ASSET_PATTERN.search(normalized):
        daily_score += 1
    if re.search(r"\b[0-2]?\d[:.]\d{2}\b", normalized):
        daily_score += 1

    for keyword in (
        "NHAT TRINH HOAT DONG THIET BI",
        "NOI DUNG CONG VIEC",
        "THOI GIAN LAM VIEC",
        "KHOI LUONG",
        "DON VI",
        "CHU KY",
    ):
        if keyword in normalized:
            journal_score += 2
    journal_score += table_score * 3

    top_score = max(daily_score, journal_score)
    margin = abs(daily_score - journal_score)
    confidence = min(0.99, 0.45 + top_score * 0.07 + margin * 0.04)
    if top_score < 2.5 or margin < 1 or confidence < minimum_confidence:
        return Classification("UNKNOWN", confidence, text)
    document_type = "DAILY_TIMEMARK" if daily_score > journal_score else "WEEKLY_JOURNAL"
    return Classification(document_type, confidence, text)


class DocumentClassifier:
    def __init__(self, minimum_confidence: float, engine: RapidOCR | None = None):
        self.minimum_confidence = minimum_confidence
        self.engine = engine or RapidOCR()

    def classify(self, path: Path) -> Classification:
        image = read_image(path)
        best_text = ""
        best_score = 0.0
        best_quality = (0, 0.0)
        for angle in (0, 180):
            candidate = rotate(image, angle)
            texts, scores = flatten_ocr_result(self.engine(enhance_for_ocr(candidate)))
            score = sum(scores) / len(scores) if scores else 0.0
            quality = (len(texts), score)
            if quality > best_quality:
                best_text = "\n".join(texts)
                best_score = score
                best_quality = quality
        result = classify_text(
            best_text,
            table_score=table_line_score(image),
            minimum_confidence=self.minimum_confidence,
        )
        confidence = result.confidence * 0.8 + best_score * 0.2 if best_text else result.confidence
        document_type = result.document_type
        if confidence < self.minimum_confidence:
            document_type = "UNKNOWN"
        return Classification(document_type, min(0.99, confidence), best_text)
