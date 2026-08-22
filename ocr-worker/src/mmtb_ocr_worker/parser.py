from __future__ import annotations

import re
import unicodedata
from datetime import date, time


DATE_PATTERNS = [
    ("dmy", re.compile(r"(?<!\d)(\d{1,2})\s*[-/.]\s*(\d{1,2})\s*[-/.]\s*(20\d{2})(?!\d)")),
    ("ymd", re.compile(r"(?<!\d)(20\d{2})\s*[-/.]\s*(\d{1,2})\s*[-/.]\s*(\d{1,2})(?!\d)")),
    (
        "vi",
        re.compile(
            r"(?<!\d)(\d{1,2})\s*(?:THA[NM]G|THANG)\s*(\d{1,2})\s*[,\-/.]?\s*(20\d{2})(?!\d)",
            re.I,
        ),
    ),
]
TIME_PATTERN = re.compile(r"(?<!\d)([01]?\d|2[0-3])\s*[:.]\s*([0-5]\d)(?::([0-5]\d))?(?!\d)")
PHONE_PATTERN = re.compile(r"(?<!\d)(0(?:[ .-]?\d){8,10})(?!\d)")
GENERIC_ASSET_PATTERN = re.compile(
    r"[A-Z0-9]{1,4}\s*[-_ ]\s*[A-Z0-9]{1,4}\s*[-_ ]?\s*[A-Z0-9]{2,8}",
    re.I,
)


def normalize_text(value: object) -> str:
    text = str(value or "").strip().upper()
    text = unicodedata.normalize("NFD", text)
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return re.sub(r"\s+", " ", text)


def normalize_asset(value: object) -> str:
    text = normalize_text(value)
    text = text.replace("—", "-").replace("–", "-").replace("_", "-")
    text = re.sub(r"[^A-Z0-9-]", "", text)
    text = re.sub(r"-+", "-", text).strip("-")
    return re.sub(r"-([A-Z0-9]{1,4})-(\d{2,8})$", r"-\1\2", text)


def parse_date(text: str) -> date | None:
    clean = normalize_text(text).replace("O", "0")
    for kind, pattern in DATE_PATTERNS:
        for match in pattern.finditer(clean):
            try:
                if kind == "ymd":
                    year, month, day = map(int, match.groups())
                else:
                    day, month, year = map(int, match.groups())
                return date(year, month, day)
            except (TypeError, ValueError):
                continue
    return None


def parse_time(text: str) -> time | None:
    clean = text.replace("O", "0").replace("o", "0")
    for match in TIME_PATTERN.finditer(clean):
        try:
            return time(
                int(match.group(1)),
                int(match.group(2)),
                int(match.group(3) or 0),
            )
        except ValueError:
            continue
    return None


def parse_phone(text: str) -> str | None:
    match = PHONE_PATTERN.search(text)
    if not match:
        return None
    digits = re.sub(r"\D", "", match.group(1))
    return digits if 9 <= len(digits) <= 11 else None


def parse_operator(text: str) -> str | None:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    for index, line in enumerate(lines):
        normalized = normalize_text(line)
        if "HO TEN" not in normalized:
            continue
        value = line.split(":", 1)[1].strip() if ":" in line else ""
        if value:
            return value[:255]
        if index + 1 < len(lines):
            return lines[index + 1][:255]
    return None


def parse_location(text: str) -> str | None:
    location_words = (
        "THANH PHO",
        "TINH ",
        "HUYEN ",
        "QUAN ",
        "PHUONG ",
        "XA ",
        "P. ",
        "Q. ",
    )
    for line in (line.strip() for line in text.splitlines() if line.strip()):
        normalized = normalize_text(line)
        if any(word in normalized for word in location_words):
            return line[:2000]
    return None


class AssetMatcher:
    CONFUSION_PAIRS = {
        frozenset(("I", "1")),
        frozenset(("L", "1")),
        frozenset(("O", "0")),
        frozenset(("S", "5")),
        frozenset(("B", "8")),
        frozenset(("Z", "2")),
    }

    def __init__(self, asset_codes: list[str]):
        normalized = [normalize_asset(code) for code in asset_codes]
        self.asset_codes = sorted({code for code in normalized if code}, key=len, reverse=True)
        self.compact_codes = {self._compact(code): code for code in self.asset_codes}

    @staticmethod
    def _compact(value: object) -> str:
        return re.sub(r"[^A-Z0-9]", "", normalize_text(value))

    @classmethod
    def _confusion_count(cls, observed: str, canonical: str) -> int | None:
        if len(observed) != len(canonical):
            return None

        substitutions = 0
        for observed_char, canonical_char in zip(observed, canonical):
            if observed_char == canonical_char:
                continue
            if frozenset((observed_char, canonical_char)) not in cls.CONFUSION_PAIRS:
                return None
            substitutions += 1

        return substitutions

    @staticmethod
    def _candidates(text: str) -> list[str]:
        candidates = [match.group(0) for match in GENERIC_ASSET_PATTERN.finditer(normalize_text(text))]
        candidates.extend(text.splitlines())

        unique: list[str] = []
        seen: set[str] = set()
        for raw in candidates:
            normalized = normalize_asset(raw)
            compact = re.sub(r"[^A-Z0-9]", "", normalized)
            if (
                normalized not in seen
                and "-" in normalized
                and 6 <= len(compact) <= 16
                and any(char.isalpha() for char in compact)
                and any(char.isdigit() for char in compact)
            ):
                seen.add(normalized)
                unique.append(raw)

        return unique

    def match(self, text: str) -> tuple[str | None, float, str]:
        whole = self._compact(text)
        for compact, canonical in self.compact_codes.items():
            if compact and compact in whole:
                return canonical, 1.0, canonical

        candidates = self._candidates(text)
        observed_code = normalize_asset(candidates[0]) if candidates else None
        observed_raw = candidates[0].strip() if candidates else ""

        safe_matches: list[tuple[int, str, str]] = []
        for raw in candidates:
            observed = self._compact(raw)
            for compact, canonical in self.compact_codes.items():
                substitutions = self._confusion_count(observed, compact)
                if substitutions is not None:
                    safe_matches.append((substitutions, canonical, raw.strip()))

        if safe_matches:
            minimum = min(match[0] for match in safe_matches)
            best = [match for match in safe_matches if match[0] == minimum]
            canonical_codes = {match[1] for match in best}
            if len(canonical_codes) == 1:
                substitutions, canonical, raw = best[0]
                confidence = max(0.85, 1.0 - substitutions * 0.05)
                return canonical, confidence, raw

        # Preserve an OCR-observed code that is not in the Laravel catalog.
        # Laravel will store it without a machine_id and mark UNKNOWN_ASSET_CODE.
        if observed_code:
            return observed_code, 0.5, observed_raw

        return None, 0.0, ""

