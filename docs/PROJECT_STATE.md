# Project State

- Updated: 2026-09-22
- Current Phase: 16.10.6 — Daily Photo OCR Extraction & Recovery Hardening.
- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS.
- Branch: `fix/phase-16-10-6-daily-photo-ocr-extraction`.
- Verified production base: `3527725ac9e6fc3edcbedab5ad5e91e6e84bc1f4`.
- Current HEAD: final local checkpoint commit containing this document; resolve with `git rev-parse HEAD`.
- Push / PR / merge / deploy / production migration / production data action / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Result

- Deterministic Daily TimeMark parsing now supports unambiguous numeric D/M/Y and M/D/Y, ISO Y-M-D, English month names and Vietnamese Tháng/Năm variants with bounded OCR noise.
- Ambiguous numeric dates and conflicting machine/date/time candidates fail closed. Dash-separated time is accepted only from the trusted `time_date` crop.
- RapidOCR aggregates the complete bounded crop/rotation set, so fields may come from separate crops and a later conflict cannot be hidden by early exit.
- The optional worker `candidate_metadata` payload preserves candidate/conflict evidence. Laravel remains compatible with old workers and blocks ambiguity without sender fallback or unnecessary retry.
- Valid partial fields survive targeted retry; retry does not replace a valid scalar with null.
- `ocr:daily-exception-diagnose` is read-only and reports one primary loss stage per filtered exception, plus bounded detailed samples.
- `ocr:daily-backlog-recover --dry-run` reports stored/mapping/retry/manual/ambiguous/protected counts without writes. Actual recovery requires explicit `--execute`.
- Recovery reparses persisted initial/retry raw OCR before dispatching another OCR attempt, fills only missing deterministic values, clears stale exception reasons after full resolution, materializes canonical evidence and is idempotent.
- Protected/reviewed/HUMAN rows remain unchanged; assignment candidates are preloaded per chunk; no migration or external bulk OCR was added.

## Proven root causes

- English month dates were unsupported.
- Numeric dates were always treated as D/M/Y, rejecting `09/21/2026` and guessing genuinely ambiguous dates.
- Time was discarded unless the same crop also contained a date.
- Date/time selection was first-value wins rather than conflict-aware aggregation.
- Backlog recovery did not reparse stored initial/retry `raw_text`.
- Existing targeted retry merge preserved scalar partial data, but it could not recover candidates dropped before the API payload.

Production distribution across the reported 742 exceptions remains NOT VERIFIED because the local application DB contains zero matching exceptions and no production command was run.

## Verified checks

| Check | Result |
|---|---|
| Worker parser + aggregation | PASS: 29 tests |
| Mapping/materialization/API/diagnostic/recovery targeted set | PASS: 92 tests, 657 assertions |
| Five production-pattern recovery | PASS |
| 1,000-exception report guard | PASS: ≤15 queries asserted |
| 1,001-evidence reconciliation regression | PASS: <30s/bounded-query assertions retained |
| Existing Daily Photo suite | PASS: 98 tests, 622 assertions |
| Full Laravel suite | PASS: 280 tests, 1,448 assertions, 55.19s |
| Full OCR worker suite | PASS: 49 tests |
| Pint `--test` | PASS: 11 changed PHP files |
| PHP syntax | PASS |
| Python `compileall` | PASS |
| `git diff --check` | PASS |

Tests use SQLite in-memory databases. Production MySQL distribution, live images and runtime behavior are NOT VERIFIED.

## Database / API / runtime impact

- Migration: NO. Existing scalar, raw/provenance JSON and canonical fields are sufficient.
- API: optional additive `candidate_metadata` on Daily TimeMark completion; old workers remain compatible.
- Worker: YES, OCR worker parser/recognizer changed and later deployment needs a worker update/restart.
- Collector: NO change and no restart needed.
- Weekly Journal: unchanged; full worker regression including classifier/journal safeguards passes.

## NEXT ACTION

1. Review the final local commit on `fix/phase-16-10-6-daily-photo-ocr-extraction` with `git show --stat --oneline HEAD` and inspect the Phase 16.10.6 application/worker/test/docs files.
2. If review passes, explicitly authorize push/PR; do not push, merge or deploy automatically.
3. If deployment is separately approved, deploy Laravel first, clear Laravel caches, then update/restart only the OCR worker. No migration is required.
4. Before any recovery write, run `php artisan ocr:daily-exception-diagnose --limit=20` and `php artisan ocr:daily-backlog-recover --dry-run`; review/scoped-filter the report before separately approving `--execute`.
5. Host-specific checkout/PHP/service commands are NOT VERIFIED because the runbook does not document those names. Do not invent them or run production recovery/restart without approval.
