from __future__ import annotations

import re
import unicodedata
from datetime import datetime
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from .models import JournalExtraction, JournalRow

DICTIONARY_VERSION = "SOP_OCR_Anh_NhatTrinh_MMTB_V1.1"

SHIFT_WORDS = re.compile(r"^(?:ca\s*)?(?:sáng|sang|chiều|chieu|tối|toi|đêm|dem)\b[\s:.-]*", re.IGNORECASE)

EXPLANATION_STATUSES = {
    "Mưa nghỉ / Không thi công do mưa",
    "Máy nghỉ",
    "Máy hỏng / Sửa chữa",
    "Chờ việc",
    "Chờ dầu",
    "Chờ bàn giao / Trả máy",
    "Nghỉ bảo dưỡng",
}

DATE_PATTERN = re.compile(r"^\s*(\d{1,2})[./-](\d{1,2})(?:[./-](\d{2,4}))?\s*$")


def _key(value: str) -> str:
    value = unicodedata.normalize("NFD", value.casefold())
    value = "".join(char for char in value if unicodedata.category(char) != "Mn")
    value = re.sub(r"[^a-z0-9]+", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def normalize_date(value: str | None, reference_year: int | None) -> tuple[str | None, bool]:
    if value is None or not str(value).strip():
        return None, False
    text = str(value).strip()
    try:
        return datetime.strptime(text, "%Y-%m-%d").strftime("%Y-%m-%d"), False
    except ValueError:
        pass
    match = DATE_PATTERN.match(text)
    if not match:
        return None, True
    day, month, year_text = match.groups()
    inferred = year_text is None
    if year_text is None:
        year = reference_year
    else:
        year = int(year_text)
        if year < 100:
            year += 2000
    if year is None:
        return None, True
    try:
        return datetime(year, int(month), int(day)).strftime("%Y-%m-%d"), inferred
    except ValueError:
        return None, True


def _status_matches(text: str) -> list[str]:
    key = _key(text)
    found: list[str] = []
    for canonical, aliases in STATUS_ALIASES.items():
        if any(_key(alias) in key for alias in aliases):
            found.append(canonical)
    # Specific rain status supersedes the generic waiting status.
    if "Mưa nghỉ / Không thi công do mưa" in found and "Chờ việc" in found:
        found.remove("Chờ việc")
    return found


def _split_work(text: str) -> list[str]:
    cleaned = SHIFT_WORDS.sub("", text.strip())
    return [part.strip(" -.;") for part in re.split(r"\s*(?:\+|;|\n|\s+và\s+)\s*", cleaned) if part.strip(" -.;")]


def _canonical_job(part: str) -> str | None:
    key = _key(part)
    if not key:
        return None
    for canonical, aliases in JOB_ALIASES.items():
        keys = {_key(canonical), *(_key(alias) for alias in aliases)}
        if key in keys:
            return canonical
        if any(len(alias) >= 5 and (alias in key or key in alias) for alias in keys):
            return canonical
    return None


def normalize_extraction(extraction: "JournalExtraction", reference_year: int | None) -> "JournalExtraction":
    current_date: str | None = None
    seen_jobs: dict[str, set[str]] = {}

    for row in extraction.rows:
        raw_content = (row.work_content or "").strip()
        explicit_date, inferred_year = normalize_date(row.work_date, reference_year)
        flags: list[str] = []

        if explicit_date:
            current_date = explicit_date
            row.work_date = explicit_date
            if inferred_year:
                flags.append("INFERRED_YEAR")
        elif current_date:
            row.work_date = current_date
            flags.append("INHERITED_DATE")
        else:
            row.work_date = None
            flags.append("MISSING_DATE")

        statuses = _status_matches(raw_content)
        jobs: list[str] = []
        mappings: list[dict[str, str]] = []
        for part in _split_work(raw_content):
            if any(_key(alias) in _key(part) for aliases in STATUS_ALIASES.values() for alias in aliases):
                continue
            canonical = _canonical_job(part)
            if canonical:
                mappings.append({"raw": part, "canonical": canonical})
                jobs.append(canonical)
            elif part:
                mappings.append({"raw": part, "canonical": part})
                jobs.append(part)
                flags.append("NEW_JOB")

        deduped: list[str] = []
        date_key = row.work_date or "__missing__"
        known = seen_jobs.setdefault(date_key, set())
        for job in jobs:
            job_key = _key(job)
            if job_key not in known:
                deduped.append(job)
                known.add(job_key)
            else:
                flags.append("DUPLICATE_JOB_SAME_DAY")

        normalized_content = deduped + [status for status in statuses if status not in deduped]
        # Never erase OCR evidence merely because a duplicate was detected.
        if normalized_content:
            row.work_content = "; ".join(normalized_content)
        elif raw_content:
            row.work_content = raw_content

        explanations = [status for status in statuses if status in EXPLANATION_STATUSES]
        row.error_explanation = "; ".join(explanations) or None
        row.raw_data = {
            **(row.raw_data or {}),
            "raw_content": raw_content,
            "jobs": deduped,
            "statuses": statuses,
            "mapping": mappings,
            "normalization_flags": sorted(set(flags)),
            "dictionary_version": DICTIONARY_VERSION,
        }
        if any(flag in flags for flag in ("MISSING_DATE", "NEW_JOB")):
            row.confidence = min(row.confidence, 0.79)

    return extraction
