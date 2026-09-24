# Phase 16.10.9 — Daily Photo TimeMark-First OCR + Source-Aware Fallback

- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS
- Date: 2026-09-24
- Branch: `fix/phase-16-10-9-timemark-first-ocr`
- Verified base: `c53c6c8` — production merge of Phase 16.10.8
- Dependency: Phase 16.10.8
- Related: Phase 16.10.6–16.10.7 stored OCR recovery and canonical materialization

## Goal and scope

Reduce residual Daily Photo OCR exceptions and OCR cost by making the recognizer TimeMark-first, original-orientation-first and source-aware. Machine, capture date and capture time are the only fields required for Daily Photo canonicalization. This Phase does not replace RapidOCR, change Weekly Journal behavior, make fuzzy machine guesses, execute production recovery, write production data or add a migration.

## Root cause confirmed from code

`TimeMarkRecognizer` previously executed five crops (`asset`, `time_date`, `left_overlay`, `lower_full`, `full`) for every orientation (`0`, `180`, `90`, `270`) and flattened every accepted value into a field-wide set. A normal Daily Photo therefore always used 20 recognition passes. Crop, orientation and semantic context were retained only as diagnostic evidence and did not affect winner selection. A conflicting `180°/full` time consequently had the same authority as repeated clean 0° TimeMark values.

`DailyPhotoStoredOcrExtractor` repeated the same flattening when reparsing persisted raw text and candidate metadata, so backlog recovery could classify lower-trust rotation noise as `TRUE_TIME_CONFLICT` even when 0° evidence was deterministic.

The parser also required whitespace between an English day and month, so compact values such as `21Sep,2026` were not recognized. Worker-side `AssetMatcher` still contained bounded character-substitution matching, which was broader than the exact normalization rule used by Laravel.

EXIF orientation was already handled correctly by `ImageOps.exif_transpose()` in `read_image()`. RapidOCR replacement or a new orientation detector was not justified.

## Implemented pipeline

1. `PRIMARY_TIMEMARK_0`: original orientation, bottom-left relative ROI `x=0.00..0.82`, `y=0.50..1.00`, using the existing grayscale/upscale/CLAHE preprocessing.
2. If every requested field is deterministic, accept and stop. No non-zero rotation is constructed or OCRed.
3. `OTHER_0_DEG_TIMEMARK`: `time_date` and `left_overlay` at 0°.
4. `0_DEG_WIDER_FALLBACK`: `lower_full` and `full` at 0°.
5. `ROTATION_FALLBACK`: only if unresolved fields remain, use `left_overlay`, `lower_full` and `full` for `180°`, `90°`, `270°`.

Each OCR call records orientation, region, preprocessing variant, numeric priority, source tier, field type and normalized value. The completion payload also records `ocr_pass_count`, `stages_executed` and selected priority per field.

## Aggregation rules

- Priority order is `PRIMARY_TIMEMARK_0 > OTHER_0_DEG_TIMEMARK > 0_DEG_WIDER_FALLBACK > ROTATION_FALLBACK`.
- Each field resolves independently from the highest-priority tier containing accepted evidence.
- Lower tiers supplement only unresolved fields and cannot contradict an already resolved higher-tier field.
- Equivalent normalized values collapse.
- Multiple distinct values or an ambiguous numeric date in the same winning tier fail closed.
- Future dates beyond the local message receipt date are discarded; no replacement date is invented.
- Exact catalog normalization remains case/separator/space insensitive. OCR character substitution/fuzzy matching was removed; catalog collisions remain ambiguous.
- Dash time and the bounded trailing-`1` repair remain restricted to trusted TimeMark regions. `06-5724 Thang9,92026` remains unresolved rather than guessed.

## Production-shaped regressions

- VT-LL0008: repeated 0° `11:01` plus lower-tier `180°/full = 10:11` resolves to `VT-LL0008 / 2026-09-21 / 11:01` with no conflict and stored recovery materializes canonical evidence.
- T-XL0303: `T-XL0303 / 13:52 / 21Sep,2026` resolves completely.
- A clean primary ROI performs one recognizer pass and never calls non-zero rotation.
- Primary machine/date remain unchanged when a 0° fallback supplies only time.
- Same-tier `11:01` versus `11:31` remains `TRUE_TIME_CONFLICT`.
- `11:01` and trusted `11-01` normalize to one value.
- Work interval `15:04-19:02` is excluded while standalone `Tan ca 19:02` is accepted.
- Invalid `54:62`, ambiguous numeric dates and future dates remain fail-closed/discarded.

## Stored OCR and backlog behavior

Stored raw sections and candidate evidence are reparsed through the same source hierarchy. New source-aware metadata can recover a deterministic record without another OCR call. Legacy metadata without priorities falls back to the documented region/orientation hierarchy. Structured retry values continue to supplement only missing persisted fields.

Recovery remains explicit, chunked, fingerprint-checked and idempotent. Dry-run and tests were executed locally only. No production recovery or mass re-OCR was run. Reviewed, corrected, approved, rejected and HUMAN-resolved records remain protected before ignore, retry or recovery writes. Sender mapping fallback rules are unchanged.

## Performance

- Old normal recognizer path: fixed 20 OCR passes (`5 crops × 4 orientations`).
- New clean portrait path: 1 OCR pass, then early stop.
- New worst-case full fallback: at most 14 OCR passes (`1 + 2 + 2 + 9`), below the old fixed cost.
- The existing UNKNOWN classifier still performs four full-image orientation passes to preserve Weekly Journal and safe hour-meter/non-daily gating. It is a separate classification job and was intentionally not weakened.
- Existing 1,000-row read-only and true-conflict query/time guards PASS.

## Verification

| Check | Result |
|---|---|
| Worker targeted parser/TimeMark/classifier/worker set | PASS: 46 tests |
| Laravel API/backlog/diagnostic/asset targeted set | PASS: 61 tests, 479 assertions |
| Full Python worker suite | PASS: 58 tests, 1.481s |
| Full Laravel suite | PASS: 293 tests, 1,561 assertions, 92.29s |
| 1,000-row backlog guards | PASS inside full Laravel suite; report/recovery query assertions retained |
| Python compile | PASS with isolated temporary pycache |
| PHP syntax | PASS: 5 changed PHP files |
| Pint `--test` | PASS: 5 changed PHP files |
| `git diff --check` | PASS |

Tests use mocked OCR output and SQLite in-memory databases. Live-image accuracy, production MySQL distribution, the reported 504-row recovery distribution and runtime CPU measurements are NOT VERIFIED locally.

## Database, API and runtime impact

- Migration: NO.
- Database backfill: NO.
- API compatibility: additive candidate provenance fields; existing top-level completion contract is unchanged.
- OCR worker update/restart after an approved deployment: YES.
- Laravel/API update: YES; deploy before the worker update.
- Collector update/restart: NO.
- Weekly Journal impact: none intended; full regression suite PASS.

## Checklist and result

- [x] Confirm current pipeline and pass count from code.
- [x] Implement original-orientation bottom-left TimeMark primary ROI.
- [x] Implement early stop and lazy rotation fallback.
- [x] Implement deterministic source-aware aggregation online and for stored OCR.
- [x] Add compact English month support and bounded artifact regressions.
- [x] Preserve exact-only machine, received-date upper bound, sender mapping and protected/HUMAN rules.
- [x] Verify stored reparse, idempotency, gate safety and large-volume guards.
- [x] Run targeted, full, lint, compile and diff checks.
- [x] Update continuity documentation.
- [ ] Production dry-run/live-image validation — NOT AUTHORIZED and NOT PERFORMED.
- [ ] Push/PR/merge/deploy/runtime restart — NOT AUTHORIZED and NOT PERFORMED.

Local implementation is ready for review. The final local checkpoint commit contains this document; resolve it with `git rev-parse HEAD`.

## Remaining risks and limitations

- ROI proportions are validated by production-shaped OCR text mocks, not the original production image files.
- Unusual layouts that do not place TimeMark content in the bottom-left will use broader 0° crops and then rotation fallback.
- The four-pass UNKNOWN classifier remains unchanged for classification safety, so the one-pass figure applies to the Daily TimeMark recognition stage after classification.
- Production exception recovery counts remain unknown until a separately authorized deployment and dry-run.

## Prohibited actions

Do not push, create PR, merge, deploy, execute production recovery, mass re-OCR, modify production data/config or restart runtime processes without separate authorization.
