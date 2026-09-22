# Phase 16.10.6 — Daily Photo OCR Extraction & Recovery Hardening

- Status: COMPLETED locally — implementation, required tests and final checks PASS; local commit contains this document
- Date: 2026-09-22
- Branch: `fix/phase-16-10-6-daily-photo-ocr-extraction`
- Verified base: `3527725ac9e6fc3edcbedab5ad5e91e6e84bc1f4`
- Dependency: Phase 16.10.5
- Related: Phase 16.10.1–16.10.4 canonical evidence/pairing/reconciliation; OCR worker

## Goal

Prevent deterministic machine/date/time already visible in TimeMark OCR crops or stored raw OCR from being lost by parsing, candidate aggregation, persistence, retry state or canonical materialization. Provide read-only loss-stage diagnostics and a safe explicit, idempotent backlog recovery path.

## Scope / non-goals

In scope: Daily TimeMark worker parser, cross-crop candidate aggregation, optional candidate diagnostics payload, Laravel completion/retry state, stored-payload reparse, read-only diagnostics, dry-run/explicit recovery, canonical materialization and regression/performance tests.

Out of scope: Weekly Journal OCR semantics, UI redesign, Collector, fuzzy machine/date/time guessing, production execution, migration backfill, external OCR of the whole backlog, timeout increases and unrelated refactors.

## Actual pipeline traced

`ZaloAttachment → OcrJobService::claim → OcrWorker::_recognize_timemark → TimeMarkRecognizer::recognize → parser.py candidate parsing → TimeMarkResult::api_payload → POST OcrJobController::complete → CompleteOcrJobRequest → OcrJobService::complete → ocr_jobs scalar/provenance fields → DailyPhotoMachineResolutionService → DailyPhotoCaseService::materialize → DailyPhotoCaseEvidence → DailyPhotoPairingService → DailyPhotoInterval → DailyPhotoSyncService → Mốc ảnh ngày`

Key persisted fields: `ocr_jobs.observed_asset_code`, `asset_code`, `extracted_date`, `extracted_time`, `raw_text`, `exceptions`, `daily_metadata`, `ocr_initial_extraction`, `ocr_retry_reason`, `ocr_retry_attempts`, `ocr_final_source`, `machine_resolution_*`, `daily_photo_case_id`; canonical `daily_photo_case_evidence.capture_datetime`; `daily_photo_cases.work_date/status`; `daily_photo_intervals.start/end_evidence_id`.

## Checkpoint A — diagnosis before production logic

### Proven

- English month dates were unsupported.
- Numeric dates were always D/M/Y, rejecting unambiguous US dates and guessing ambiguous dates.
- A time candidate was ignored unless its own crop also contained a date.
- Date/time candidates were first-value wins rather than conflict-aware aggregation.
- Stored initial/retry raw OCR was not reparsed by backlog recovery.
- Valid scalar fields were preserved by targeted retry merge, but candidates dropped in the worker could not reach Laravel.

### Suspected, not yet proven on production rows

- The proportions of `PARSER_DROPPED_DATE`, `PARSER_DROPPED_TIME`, aggregation, retry-result and canonical loss across the reported 742 exceptions.
- How many production rows are recoverable from stored raw versus require one external targeted retry.

Local diagnostic returned zero rows; no production DB was accessed.

## Business invariants

- A Daily Photo requires only deterministic machine, capture date and capture time.
- Valid unique OCR machine wins; invalid/not-found may use receipt-effective sender mapping; ambiguity never falls back.
- Ambiguous numeric date, conflicting valid candidates and untrusted dash-separated numbers fail closed.
- Retry supplements missing fields and never erases a valid existing field.
- Low confidence remains telemetry only.
- Protected/reviewed/HUMAN data is not overwritten.
- Weekly Journal behavior is unchanged.

## Implementation

- Added `ocr:daily-exception-diagnose` as a read-only whole-set diagnostic with bounded detailed samples.
- Added deterministic Python date/time candidate APIs and conflict-aware aggregation over the complete bounded crop/rotation set; a later conflicting candidate cannot be hidden by an early exit.
- Added optional `candidate_metadata` to the TimeMark completion payload; old workers remain request-compatible.
- Added Laravel conflict handling and explicit ambiguous date/time reasons.
- Added stored raw extractor for persisted/initial/retry OCR payloads.
- Added `ocr:daily-backlog-recover --dry-run`; actual writes require explicit `--execute`.
- Recovery fills only missing deterministic values, clears stale exception reasons after full resolution, preloads assignments, materializes canonical evidence and remains idempotent.

## Five production patterns

Automated coverage now includes:

1. `T-XL0303 / 21 Sep,2026 / 06:22`.
2. `T-3C0172 / 09/21/2026 / 11:02`.
3. `T-3C0140`, date/time split across crops with Vietnamese month text.
4. Invalid OCR machine + `SGC-T-3C0715` sender mapping + trusted `06-57`.
5. `SGC-T-3C0556 / 17:33 / 21 Tháng 9,20265C`.

All five patterns PASS through stored-payload recovery and canonical evidence materialization. Pattern 4 uses the receipt-effective sender mapping because the OCR machine is invalid; the trusted `time_date` crop alone may normalize `06-57`.

## Diagnostic and recovery commands

Read-only diagnostic:

```bash
php artisan ocr:daily-exception-diagnose --limit=20
php artisan ocr:daily-exception-diagnose --sender=<sender-id> --from=2026-09-01 --to=2026-09-30 --limit=20
```

Mandatory dry-run before any production write:

```bash
php artisan ocr:daily-backlog-recover --dry-run
php artisan ocr:daily-backlog-recover --dry-run --sender=<sender-id> --from=2026-09-01 --to=2026-09-30
```

An actual recovery is deliberately separate and requires `--execute`; it must not be run on production without separate approval:

```bash
php artisan ocr:daily-backlog-recover --execute --sender=<reviewed-sender-id> --from=2026-09-01 --to=2026-09-30
```

## Verified tests

| Check | Result |
|---|---|
| Worker parser + candidate aggregation | PASS: 29 tests |
| Mapping/materialization/API/diagnostic/recovery targeted set | PASS: 92 tests, 657 assertions |
| Five production-pattern stored recovery | PASS inside `AutoRecoveryBacklogTest` |
| 1,000-exception report guard | PASS; total 1,000 and at most 15 queries asserted |
| 1,001-evidence reconciliation regression | PASS; under 30 seconds and bounded-query assertions retained |
| Existing Daily Photo suite | PASS: 98 tests, 622 assertions |
| Full Laravel suite | PASS: 280 tests, 1,448 assertions, 55.19s |
| Full OCR worker suite | PASS: 49 tests |
| Pint `--test` on changed PHP | PASS: 11 files |
| PHP syntax on changed PHP | PASS |
| Python `compileall` | PASS |
| `git diff --check` | PASS |

Tests use SQLite in-memory. Production MySQL data distribution, 742-row diagnostic counts, live OCR images and production runtime remain NOT VERIFIED.

## Database / migration

Migration: NO. Existing `raw_text`, JSON provenance/daily metadata and canonical schema are sufficient. No data backfill occurs in a migration.

## Deployment / rollback

No production action is authorized. If deployment is later approved:

1. Pause the OCR worker on the laptop runtime using its existing process manager.
2. Deploy the approved merged Laravel commit on hosting, then run `php artisan optimize:clear`. There is no migration.
3. On the laptop OCR runtime, pull the same approved merged commit and restart only the OCR worker.
4. Verify one Daily TimeMark completion contains `candidate_metadata`, reaches `COMPLETED`, creates canonical evidence and does not log repeated errors.
5. Run the read-only diagnostic and backlog dry-run commands above; review counts before separately approving any scoped `--execute`.

The repository does not document the hosting checkout path, PHP binary or laptop service/process name, so exact host-specific `cd`, stop and restart commands are NOT VERIFIED and must not be invented. Collector pull/restart is not needed.

Rollback order is worker first, then Laravel: stop/revert the new worker before reverting Laravel because the previous API validator does not accept `candidate_metadata`. No schema rollback is needed. Recovered canonical records are not deleted automatically.

## Remaining checklist

- [x] Trace actual pipeline and fields.
- [x] Add read-only loss-stage diagnostic.
- [x] Record proven versus suspected causes.
- [x] Harden deterministic parser and aggregation.
- [x] Preserve partial valid data and fail closed on conflict.
- [x] Add stored-payload dry-run/explicit recovery.
- [x] Complete ordered targeted/large-volume/full regression checks.
- [x] Pint, PHP lint, Python compile, diff-check and final diff review.
- [x] Final continuity docs.
- [x] Local commit (this file is included in the final checkpoint commit; resolve with `git rev-parse HEAD`).

## Prohibited actions

Do not push, create PR, merge, deploy, run production migration/recovery, modify production data/config, or restart Collector/OCR worker during this Phase task.
