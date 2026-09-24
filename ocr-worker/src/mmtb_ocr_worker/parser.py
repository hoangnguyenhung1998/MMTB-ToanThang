from __future__ import annotations

import re
import unicodedata
from datetime import date, time


YMD_DATE_PATTERN = re.compile(r"(?<!\d)(20\d{2})\s*[-/.]\s*(\d{1,2})\s*[-/.]\s*(\d{1,2})(?!\d)")
NUMERIC_DATE_PATTERN = re.compile(r"(?<!\d)(\d{1,2})\s*[-/.]\s*(\d{1,2})\s*[-/.]\s*(20\d{2})(?!\d)")
ENGLISH_DATE_PATTERN = re.compile(
    r"(?<!\d)(\d{1,2})\s*(JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|JUL(?:Y)?|AUG(?:UST)?|SEP(?:T(?:EMBER)?)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\s*,?\s*(20\d{2})",
    re.I,
)
VIETNAMESE_DATE_PATTERN = re.compile(
    r"(?<!\d)(\d{1,2})\s*THA(?:NG|NIG|RIG|MG)\s*(\d{1,2})(?:\s+NAM)?\s*,?\s*(20\d{2})",
    re.I,
)
TIME_PATTERN = re.compile(r"(?<![\d.])([01]?\d|2[0-3])\s*[:.hH]\s*([0-5]\d)(?::([0-5]\d))?(?![\d.])(?:\s*([AP])\s*\.?\s*M\.?)?", re.I)
DASH_TIME_PATTERN = re.compile(r"(?<![\d.])([01]?\d|2[0-3])\s*-\s*([0-5]\d)(?![\d.])", re.I)
TRAILING_ARTIFACT_TIME_PATTERN = re.compile(r"(?<![\d.])([01]?\d|2[0-3])\s*:\s*([0-5]\d)1(?!\d)", re.I)
WORK_INTERVAL_PATTERN = re.compile(
    r"(?<![\d.])(?:[01]?\d|2[0-3])\s*[:.hH]\s*[0-5]\d\s*(?:-|–|—|TO|DEN|ĐẾN)\s*"
    r"(?:[01]?\d|2[0-3])\s*[:.hH]\s*[0-5]\d(?![\d.])",
    re.I,
)
DURATION_PATTERN = re.compile(r"(?<!\d)\d{1,3}\s*(?:GIO|GIỜ|HOURS?)\s*\d{1,2}\s*(?:PHUT|PHÚT|MIN(?:UTE)?S?)", re.I)
PHONE_PATTERN = re.compile(r"(?<!\d)(0(?:[ .-]?\d){8,10})(?!\d)")
GENERIC_ASSET_PATTERN = re.compile(
    r"[A-Z0-9]{1,4}\s*[-_ ]\s*[A-Z0-9]{1,4}\s*[-_ ]?\s*[A-Z0-9]{2,8}",
    re.I,
)

MONTHS = {
    "JAN": 1, "JANUARY": 1, "FEB": 2, "FEBRUARY": 2,
    "MAR": 3, "MARCH": 3, "APR": 4, "APRIL": 4, "MAY": 5,
    "JUN": 6, "JUNE": 6, "JUL": 7, "JULY": 7, "AUG": 8,
    "AUGUST": 8, "SEP": 9, "SEPT": 9, "SEPTEMBER": 9,
    "OCT": 10, "OCTOBER": 10, "NOV": 11, "NOVEMBER": 11,
    "DEC": 12, "DECEMBER": 12,
}


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


def parse_date_candidates(text: str) -> tuple[set[date], bool]:
    clean = normalize_text(text)
    candidates: set[date] = set()
    ambiguous = False

    def add(year: int, month: int, day: int) -> None:
        try:
            candidates.add(date(year, month, day))
        except ValueError:
            pass

    for match in YMD_DATE_PATTERN.finditer(clean):
        add(*map(int, match.groups()))

    for match in NUMERIC_DATE_PATTERN.finditer(clean):
        first, second, year = map(int, match.groups())
        if first > 12 >= second:
            add(year, second, first)
        elif second > 12 >= first:
            add(year, first, second)
        elif first == second and 1 <= first <= 12:
            add(year, second, first)
        elif 1 <= first <= 12 and 1 <= second <= 12:
            ambiguous = True

    for match in ENGLISH_DATE_PATTERN.finditer(clean):
        day, month_name, year = match.groups()
        add(int(year), MONTHS[month_name.upper()], int(day))

    for match in VIETNAMESE_DATE_PATTERN.finditer(clean):
        day, month, year = map(int, match.groups())
        add(year, month, day)

    return candidates, ambiguous


def parse_date(text: str) -> date | None:
    candidates, ambiguous = parse_date_candidates(text)
    return next(iter(candidates)) if len(candidates) == 1 and not ambiguous else None


def parse_time_evidence(
    text: str,
    allow_dash: bool = False,
    allow_trailing_artifact: bool = False,
) -> tuple[set[time], list[dict]]:
    clean = re.sub(r"(?<=\d)[Oo](?=\d)", "0", text)
    for pattern in (YMD_DATE_PATTERN, NUMERIC_DATE_PATTERN, ENGLISH_DATE_PATTERN, VIETNAMESE_DATE_PATTERN):
        clean = pattern.sub(' ', clean)
    candidates: set[time] = set()
    evidence: list[dict] = []
    excluded_spans = [match.span() for match in WORK_INTERVAL_PATTERN.finditer(clean)]
    excluded_spans.extend(match.span() for match in DURATION_PATTERN.finditer(normalize_text(clean)))

    def excluded(start: int, end: int) -> bool:
        return any(start >= left and end <= right for left, right in excluded_spans)

    patterns: list[tuple[re.Pattern, str]] = [(TIME_PATTERN, "STANDARD")]
    if allow_dash:
        patterns.append((DASH_TIME_PATTERN, "TRUSTED_DASH"))
    if allow_trailing_artifact:
        patterns.append((TRAILING_ARTIFACT_TIME_PATTERN, "TRAILING_TIMEMARK_ARTIFACT"))
    for pattern, normalization in patterns:
        for match in pattern.finditer(clean):
            if excluded(*match.span()):
                evidence.append({"raw": match.group(0), "accepted": False, "reason": "WORK_INTERVAL"})
                continue
            try:
                hour = int(match.group(1))
                meridiem = match.group(4) if pattern is TIME_PATTERN else None
                if meridiem:
                    if not 1 <= hour <= 12:
                        continue
                    hour = hour % 12 + (12 if meridiem.upper() == "P" else 0)
                value = time(
                    hour,
                    int(match.group(2)),
                    int(match.group(3) or 0) if pattern is TIME_PATTERN else 0,
                )
                candidates.add(value)
                evidence.append({
                    "raw": match.group(0),
                    "value": value.isoformat(),
                    "accepted": True,
                    "reason": normalization,
                })
            except ValueError:
                continue
    for match in re.finditer(r"(?<!\d)\d{1,2}\s*[:.]\s*\d{2,3}(?!\d)", clean):
        if not any(match.span() == accepted.span() for pattern, _ in patterns for accepted in pattern.finditer(clean)):
            evidence.append({"raw": match.group(0), "accepted": False, "reason": "INVALID_TIME"})
    for match in WORK_INTERVAL_PATTERN.finditer(clean):
        if not any(item["reason"] == "WORK_INTERVAL" and item["raw"] in match.group(0) for item in evidence):
            evidence.append({"raw": match.group(0), "accepted": False, "reason": "WORK_INTERVAL"})
    for match in DURATION_PATTERN.finditer(normalize_text(clean)):
        evidence.append({"raw": match.group(0), "accepted": False, "reason": "DURATION"})

    return candidates, evidence


def parse_time_candidates(
    text: str,
    allow_dash: bool = False,
    allow_trailing_artifact: bool = False,
) -> set[time]:
    candidates, _ = parse_time_evidence(text, allow_dash, allow_trailing_artifact)
    return candidates


def parse_time(text: str, allow_dash: bool = False, allow_trailing_artifact: bool = False) -> time | None:
    candidates = parse_time_candidates(text, allow_dash, allow_trailing_artifact)
    return next(iter(candidates)) if len(candidates) == 1 else None


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
    def __init__(self, asset_codes: list[str]):
        normalized = [normalize_asset(code) for code in asset_codes]
        self.asset_codes = sorted({code for code in normalized if code}, key=len, reverse=True)
        compact_codes: dict[str, list[str]] = {}
        for code in self.asset_codes:
            compact_codes.setdefault(self._compact(code), []).append(code)
        self.compact_codes = compact_codes

    @staticmethod
    def _compact(value: object) -> str:
        return re.sub(r"[^A-Z0-9]", "", normalize_text(value))

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
        for compact, canonical_codes in self.compact_codes.items():
            if compact and compact in whole and len(canonical_codes) == 1:
                return canonical_codes[0], 1.0, canonical_codes[0]

        candidates = self._candidates(text)
        observed_code = normalize_asset(candidates[0]) if candidates else None
        observed_raw = candidates[0].strip() if candidates else ""

        # Preserve an OCR-observed code that is not in the Laravel catalog.
        # Laravel will store it without a machine_id and mark UNKNOWN_ASSET_CODE.
        if observed_code:
            return observed_code, 0.5, observed_raw

        return None, 0.0, ""

    def valid_matches(self, text: str) -> set[str]:
        whole = self._compact(text)
        return {
            canonical
            for compact, canonical_codes in self.compact_codes.items()
            if compact and compact in whole
            for canonical in canonical_codes
        }
