# Phase 16.10.10 — Daily Photo OCR Consolidation, Field Lock and OCR Case Library

- Status: COMPLETED locally — implementation, required tests, full suites, final static checks and local checkpoint commit complete
- Date: 2026-09-26
- Branch: `phase16-10-10-daily-ocr-consolidation`
- Verified base: `ff46acf` — production merge of Phase 16.10.9.1
- Dependencies: Phase 16.10.8, Phase 16.10.9, Phase 16.10.9.1
- Related: OCR worker TimeMark pipeline; stored OCR recovery; Daily Photo review/canonical materialization

## Goal and scope

Make Daily Photo Date/Time extraction independently deterministic, stop OCR as soon as both temporal fields are locked, resolve Machine separately, consolidate stored parsing semantics, strengthen the hour-meter gate, provide a read-only production parser audit, and establish a verified OCR regression case library. Weekly Journal and Collector behavior remain outside scope.

## Root causes confirmed from code

1. The worker already resolved each field from the highest-priority source tier, but `stage_is_terminal()` required all requested fields. The default request includes Machine, Date and Time, so a missing Machine alone forced the full fallback/rotation path even after Date+Time were deterministic.
2. Laravel targeted retry selected `machine` before temporal gaps. That caused a second OCR attempt where historical mapping should have been the deterministic fallback.
3. Dot time was accepted by the Python base time regex and Laravel stored parser without an explicit trusted-TimeMark switch. It worked for `10.32`, but the context boundary was broader than the required safety rule.
4. The production-shaped Vietnamese dates are already covered by both parsers. A primary date can nevertheless remain absent in an existing final row because older aggregation/final state did not retain/apply the deterministic field. Stored reparse now proves and recovers the primary value through source priority; the audit reports `PRIMARY_HAS_DATE_BUT_FINAL_MISSING` instead of adding an image-specific regex.
5. The review form value `Mã máy xác nhận` comes directly from `ocr_jobs.machine_id` through the `machine` relation. It does not query the current sender mapping. `machine_resolution_method`, `machine_resolution_metadata` and review fields determine whether that persisted value came from image evidence, historical mapping or protected human confirmation. The local database contains no matching VT-XL0362/SGC-T-3C0057 production row, so the exact live provenance remains NOT VERIFIED until the read-only production audit is run.
6. Hour-meter classification required at least two textual marker labels. A real display with `HOURS`, a decimal counter and `1/10` had multiple semantic signals but only one marker label, so it could miss the gate. The gate now counts independent counter/tenths signals and never depends on brand text.

## Implemented behavior

### Worker field lock and early stop

- Source hierarchy remains `PRIMARY_TIMEMARK_0 > OTHER_0_DEG_TIMEMARK > 0_DEG_WIDER_FALLBACK > ROTATION_FALLBACK`.
- Machine, Date and Time are resolved independently inside a tier.
- A deterministic value or same-tier ambiguity locks that field before the next tier.
- Later tiers supplement only still-missing fields and cannot override or create a conflict against a locked higher-tier field.
- Date+Time terminal state stops the Daily Photo recognizer even when Machine is missing/invalid.
- A Machine-only targeted request performs the primary pass only; Machine no longer causes rotation.
- Machine is resolved after OCR by exact unique image match, then receipt-effective historical mapping. Ambiguous image evidence remains fail-closed. Missing mapping retains the existing `SENDER_MAPPING_MISSING` / `MAPPING_MISSING` vocabulary, documented as `MAPPING_REQUIRED` semantics.

### Canonical parser semantics

- Standard `HH:MM` and `HHhMM` remain supported.
- Trusted TimeMark regions additionally allow `HH-MM`, `HH.MM` and the bounded trailing-`1` repair.
- Dot time is not accepted in untrusted/global text, preventing decimal counters from becoming capture times.
- Work intervals/durations and invalid values remain excluded.
- Vietnamese `Tháng`, `Thang`, `Thanig`, `Tharig` plus compact spacing and English month variants remain supported.
- Capture date remains bounded by local message receipt date; no date is invented.

### Hour-meter gate

- Requires an hour label/context, a counter token and at least one additional independent semantic signal such as a decimal counter, `1/10`, `HOUR METER` or `ENGINE HOURS`.
- The live worker additionally retains the image structure threshold.
- Brand names such as CURTIS/QUARTZ are not classification rules.
- A single HOURS-like token does not classify the image.

### Read-only parser audit

```bash
php artisan ocr:daily-parser-audit --limit=0 --sample-limit=20
```

The command is chunked/read-only and reports primary/final field loss, raw/parser gaps, lower-tier disagreement, mapping recoverability/requirement, possible hour meters, complete-but-exception state and true same-tier conflicts. Samples include bounded raw text, selected source priority, normalized/final values, exception reason, mapping state and human/protected resolution provenance.

### OCR Case Library

- Additive table: `ocr_regression_cases`.
- Stores source job/attachment references, immutable input OCR snapshot, source/crop/rotation metadata, current and expected Machine/Date/Time, expected disposition/status, category, notes and verification provenance.
- Categories: `TIME_PARSE`, `DATE_PARSE`, `MACHINE_PARSE`, `SOURCE_PRIORITY`, `MAPPING_FALLBACK`, `HOUR_METER`, `NON_DAILY_PHOTO`, `DOWNSTREAM`, `OTHER`.
- Statuses support `DRAFT` and `VERIFIED`; the explicit UI correction action creates/updates a VERIFIED case.
- The source image binary is not duplicated.
- Source job + category is unique. Repeated submit preserves the first immutable input snapshot and does not rewrite already-confirmed identical protected source state.

UI action: `Lưu + thêm vào bộ case OCR`. The operator chooses category and expected disposition. An explicit hour-meter/non-daily choice detaches automatic canonical membership and records the ignored disposition.

```bash
php artisan ocr:case-regression --sample-limit=20
```

Only VERIFIED cases run. Evaluation reuses stored OCR source priority, exact machine catalog matching, captured mapping snapshot and the hour-meter gate. The command reports totals/category ratios and field-level mismatches. It never mutates OcrJob, canonical evidence or reconciliation data.

## Stored OCR and downstream cleanup

- Stored raw OCR and candidate metadata continue through the same field-priority extractor.
- Deterministic primary Date/Time survive lower-tier noise.
- Backlog recovery remains the explicit dry-run/execute path for existing records; no migration backfill or production mutation is performed.
- Existing automatic recovery clears stale exceptions only when current evidence is complete and unambiguous, then materializes canonical evidence idempotently.
- Reviewed, corrected, approved, rejected and HUMAN rows remain protected.

## Migration and deployment

- Migration: `2026_09_26_000001_create_ocr_regression_cases_table.php`.
- Additive only; no backfill; rollback drops only the new case-library table.
- Laravel/API should deploy and migrate first.
- OCR worker code changed and requires a later controlled laptop update/restart of `MMTB-RapidOCRWorker`.
- Collector code did not change and must not be restarted.
- Rollback is code rollback plus optional rollback of the unused new table only if no cases must be retained. Never discard captured cases blindly.

## Verification checkpoint

- Targeted worker parser/TimeMark/classifier: PASS — 51 tests.
- Targeted Laravel OCR consolidation/backlog/canonical/review: PASS — 65 tests / 426 assertions before the final focused additions.
- New consolidation suite including 1,000 audit jobs and 1,000 VERIFIED cases: PASS — 8 tests / 48 assertions.
- Full Python worker suite: PASS — 65 tests in 1.570s.
- Full Laravel suite: PASS — 314 tests / 1,669 assertions in 47.41s.
- 1,000-row audit and 1,000-case regression query/time bounds: PASS in 2.45s combined test runtime.
- Pint `--test`: PASS — 18 changed PHP files.
- PHP syntax: PASS — 19 changed/new PHP files.
- Python compile: PASS with isolated temporary pycache.
- `git diff --check`: PASS.
- Final file review: no Collector file and no Weekly Journal-specific worker/view/model file changed.

## Prohibited operations

No push, PR, merge, deploy, production migration, production parser recovery, mass re-OCR, production data mutation, worker restart or Collector restart has been performed or authorized.

The final local checkpoint is the current branch `HEAD`; resolve its immutable SHA with `git rev-parse HEAD`.
