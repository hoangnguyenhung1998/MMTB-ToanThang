from __future__ import annotations

import re
import unicodedata
from datetime import datetime
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from .models import JournalExtraction, JournalRow

DICTIONARY_VERSION = "SOP_OCR_Anh_NhatTrinh_MMTB_V1.1"

SHIFT_WORDS = re.compile(r"^(?:ca\s*)?(?:sang|chieu|toi|dem)\b[\s:.-]*", re.IGNORECASE)

STATUS_ALIASES: dict[str, tuple[str, ...]] = {
    "Máy nghỉ": ("máy nghỉ", "xe nghỉ", "nghỉ off"),
    "Mưa nghỉ / Không thi công do mưa": (
        "mưa nghỉ", "nghỉ mưa", "trời mưa nghỉ", "bch báo nghỉ",
        "kỹ thuật báo nghỉ", "đất ướt không lu được", "đất ẩm không lu được",
        "chờ việc do mưa", "không thi công do mưa",
    ),
    "Máy hỏng / Sửa chữa": (
        "máy hỏng", "xe hỏng", "sửa máy", "sửa chữa máy",
        "máy hỏng chờ sửa chữa", "nghỉ sửa máy",
    ),
    "Chờ việc": ("chờ việc",),
    "Chờ dầu": ("chờ dầu",),
    "Chờ bàn giao / Trả máy": ("chờ bàn giao", "trả máy"),
    "Trực sản xuất": ("trực sản xuất",),
    "Nghỉ bảo dưỡng": ("nghỉ bảo dưỡng", "bảo dưỡng máy"),
}

EXPLANATION_STATUSES = {
    "Mưa nghỉ / Không thi công do mưa",
    "Máy nghỉ",
    "Máy hỏng / Sửa chữa",
    "Chờ việc",
    "Chờ dầu",
    "Chờ bàn giao / Trả máy",
    "Nghỉ bảo dưỡng",
}

# Catalog V1.1. Matching is deliberately conservative: an unknown phrase is retained
# verbatim and marked NEW_JOB instead of being forced into the nearest known job.
JOB_ALIASES: dict[str, tuple[str, ...]] = {
    "Dọn vệ sinh": ("dọn vệ sinh", "dọn vệ sinh bãi đúc", "dọn vệ sinh đường"),
    "Dọn rác": ("dọn rác", "xử lý dọn rác", "dọn trạc", "xúc dọn trạc rác"),
    "Gắp rác": ("gắp rác",),
    "Chở rác": ("chở rác", "vc rác", "vận chuyển rác", "chạy rác", "rác chạc"),
    "Xúc rác": ("xúc rác",),
    "Xúc đất": ("xúc đất", "xúc đất lên xe", "xúc đất thừa"),
    "Vận chuyển đất": ("vận chuyển đất", "vc đất", "chở đất", "chạy đất"),
    "Đào đất": ("đào đất", "đào móng", "đào rãnh", "đào hố"),
    "Lấp đất": ("lấp đất", "lấp rãnh", "lấp hố", "hoàn trả đất"),
    "San gạt mặt bằng": ("san gạt mặt bằng", "san mặt bằng", "gạt mặt bằng", "san nền"),
    "Lu lèn": ("lu lèn", "lu nền", "lu đường", "lu đất"),
    "Cẩu vật tư": ("cẩu vật tư", "cẩu vt", "cẩu hàng", "cẩu đồ"),
    "Cẩu cấu kiện": ("cẩu cấu kiện", "cẩu bê tông", "cẩu ống", "cẩu tấm"),
    "Cẩu cây": ("cẩu cây", "cẩu chuyển cây", "cẩu hạ cây"),
    "Cẩu quả ga": ("cẩu quả ga", "cẩu ga"),
    "Hạ quả ga": ("hạ quả ga", "hạ ga"),
    "Đảo hố ga": ("đảo hố ga", "đào hố ga"),
    "Lắp đặt hố ga": ("lắp đặt hố ga", "lắp hố ga"),
    "Đào gen điện": ("đào gen điện", "đào gen"),
    "Lắp gen điện": ("lắp gen điện", "lắp gen"),
    "Lấp gen điện": ("lấp gen điện", "lấp gen"),
    "Đào và lấp gen": ("đào và lấp gen", "đào + lấp gen", "đào lấp gen"),
    "Chuyển vật tư": ("chuyển vật tư", "vc vật tư", "vận chuyển vật tư"),
    "Chuyển máy": ("chuyển máy", "di chuyển máy", "điều chuyển máy"),
    "Phá bê tông": ("phá bê tông", "đục bê tông"),
    "Đổ bê tông": ("đổ bê tông",),
    "Trộn bê tông": ("trộn bê tông",),
    "Tưới nước": ("tưới nước", "tưới đường", "tưới chống bụi"),
    "Bơm nước": ("bơm nước", "hút nước"),
    "Gạt bùn": ("gạt bùn", "xúc bùn", "dọn bùn"),
    "Sàng đất": ("sàng đất",),
    "Xới đất": ("xới đất", "xới nền"),
    "Nâng hạ vật tư": ("nâng hạ vật tư", "nâng vật tư", "hạ vật tư"),
    "Làm đường công vụ": ("làm đường công vụ", "sửa đường công vụ"),
    "Dọn mặt bằng": ("dọn mặt bằng", "giải phóng mặt bằng"),
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
