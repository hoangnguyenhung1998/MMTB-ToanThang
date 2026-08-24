from __future__ import annotations

import re
from dataclasses import dataclass
from difflib import SequenceMatcher
from pathlib import Path

from rapidocr import RapidOCR

from .imaging import enhance_for_ocr, flatten_ocr_result, read_image, rotate, table_line_score
from .parser import GENERIC_ASSET_PATTERN, PHONE_PATTERN, normalize_text


@dataclass(frozen=True)
class Classification:
    document_type: str
    confidence: float
    raw_text: str


def _fuzzy_phrase(normalized: str, phrase: str, threshold: float = 0.72) -> bool:
    """Match short form labels while tolerating common OCR character confusion."""
    source_words = re.sub(r"[^A-Z0-9 ]+", " ", normalized).split()
    target_words = phrase.split()
    if not source_words or not target_words:
        return False

    target = " ".join(target_words)
    minimum = max(1, len(target_words) - 1)
    maximum = len(target_words) + 1
    for size in range(minimum, maximum + 1):
        for start in range(0, len(source_words) - size + 1):
            candidate = " ".join(source_words[start:start + size])
            if SequenceMatcher(None, candidate, target).ratio() >= threshold:
                return True
    return False


def _journal_evidence(normalized: str, table_score: float) -> tuple[float, int, int]:
    form_phrases = (
        "NHAT TRINH HOAT DONG THIET BI",
        "NOI DUNG CONG VIEC",
        "THOI GIAN LAM VIEC",
        "KHOI LUONG",
    )
    structural_phrases = (
        "BAT DAU",
        "KET THUC",
        "TONG THOI GIAN",
        "DIA DIEM LAM VIEC",
        "NV VAN HANH",
        "CHU KY",
        "CBKT",
    )

    form_hits = sum(_fuzzy_phrase(normalized, phrase) for phrase in form_phrases)
    structural_hits = sum(_fuzzy_phrase(normalized, phrase, 0.76) for phrase in structural_phrases)
    if re.search(r"\bNGAY\b", normalized):
        structural_hits += 1

    score = form_hits * 2.0 + structural_hits * 0.75 + table_score * 3.0
    return score, form_hits, structural_hits


def classify_text(text: str, table_score: float = 0.0, minimum_confidence: float = 0.70) -> Classification:
    normalized = normalize_text(text)
    daily_score = 0.0

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

    journal_score, form_hits, structural_hits = _journal_evidence(normalized, table_score)

    # A ruled equipment journal remains a journal even when photographed in TimeMark.
    # Form evidence is evaluated before watermark/person overlay evidence.
    strong_journal = (
        form_hits >= 2
        or (form_hits >= 1 and structural_hits >= 2 and table_score >= 0.20)
        or (structural_hits >= 4 and table_score >= 0.35)
    )
    if strong_journal:
        confidence = min(0.99, 0.72 + form_hits * 0.06 + structural_hits * 0.02 + table_score * 0.08)
        return Classification("WEEKLY_JOURNAL", confidence, text)

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
        candidates: list[tuple[Classification, float, int]] = []

        for angle in (0, 90, 180, 270):
            candidate = rotate(image, angle)
            texts, scores = flatten_ocr_result(self.engine(enhance_for_ocr(candidate)))
            text = "\n".join(texts)
            ocr_score = sum(scores) / len(scores) if scores else 0.0
            result = classify_text(
                text,
                table_score=table_line_score(candidate),
                minimum_confidence=self.minimum_confidence,
            )
            candidates.append((result, ocr_score, len(texts)))

        # A strong journal detected at any orientation beats a TimeMark watermark.
        # Within the same type, prefer confidence and OCR quality.
        type_priority = {"WEEKLY_JOURNAL": 2, "DAILY_TIMEMARK": 1, "UNKNOWN": 0}
        result, best_score, _ = max(
            candidates,
            key=lambda item: (
                type_priority[item[0].document_type],
                item[0].confidence,
                item[1],
                item[2],
            ),
        )

        confidence = result.confidence * 0.8 + best_score * 0.2 if result.raw_text else result.confidence
        document_type = result.document_type
        if confidence < self.minimum_confidence:
            document_type = "UNKNOWN"
        return Classification(document_type, min(0.99, confidence), result.raw_text)
