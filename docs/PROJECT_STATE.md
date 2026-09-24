# Project State

- Updated: 2026-09-24
- Current Phase: 16.10.9.1 — Safe Manual Daily Photo Re-OCR.
- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS.
- Branch: `hotfix/phase-16-10-9-1-manual-reocr`.
- Verified production base: `854b1757d143d4cf2e4b01f21cd93257b4098d21` (merge of Phase 16.10.9).
- Push / PR / merge / deploy / production recovery / production requeue / worker restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- UI “Cần manual” is confirmed as a computed backlog category, not an OcrJob enum: `DAILY_TIMEMARK + EXCEPTION + auto_recoverable=false`.
- Because that category includes protected rows, eligibility layers existing human/review/canonical/lease/source guards over the exact UI-manual population.
- `ocr:daily-manual-retry --dry-run` is read-only; `--execute` requeues only rows that pass a locked recheck.
- Existing OcrJobs are reused. `daily_metadata.manual_reocr` provides durable provenance, one-use claim eligibility and the anti-loop marker without a migration.
- Old raw OCR, candidate metadata and retry provenance are snapshotted. No Python OCR algorithm/runtime or Collector file changed.

## Verified checks so far

| Check | Result |
|---|---|
| New command/service tests including 1,000 rows | PASS: 13 tests, 63 assertions |
| Related API/backlog/canonical/workflow tests | PASS: 114 tests, 738 assertions |
| Full Laravel suite | PASS: 306 tests, 1,624 assertions, 41.58s |
| Full Python worker suite | PASS: 58 tests, 1.509s |
| 1,000-row manual scan guard | PASS: 0.77s, at most 55 queries |
| PHP syntax / Pint / diff check | PASS |

## Deployment impact

- Migration/backfill: NO.
- Laravel update: YES.
- API contract change: NO.
- OCR worker update: NO; runtime must already be Phase 16.10.9+.
- Collector update: NO.
- Production command execution: dry-run only after deployment review; execute remains separately gated.

## NEXT ACTION

1. Review the final local commit on `hotfix/phase-16-10-9-1-manual-reocr` with `git show --stat --oneline HEAD`.
2. If review passes, separately authorize push and PR; do not merge or deploy automatically.
3. After an approved Laravel deployment, confirm the laptop worker is already Phase 16.10.9+ and run only `php artisan ocr:daily-manual-retry --dry-run --sample-limit=20`.
4. Verify protection, attempted, source and eligibility counts. Authorize `--execute` separately only after accepting that dry-run.
5. Do not update/restart the worker or Collector for this hotfix, and do not run mass OCR/recovery.
