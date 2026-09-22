# Project State

- Updated: 2026-09-22
- Current Phase: 16.10.7 — Residual Daily Photo Exception Root-Cause & Deterministic Recovery.
- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS.
- Branch: `fix/phase-16-10-7-residual-daily-photo-recovery`.
- Verified production base: `d5aa4b3`.
- Current HEAD: final local checkpoint commit containing this document; resolve with `git rev-parse HEAD`.
- Push / PR / merge / deploy / production migration / production data action / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- Structured initial/retry/final scalar values now supplement missing persisted machine/date/time during residual analysis; valid persisted fields are never replaced.
- Diagnostic, dry-run and execute share actionable subtypes for true conflicts, retry values not merged, ready-to-materialize rows, stale state, mapping, actual missing OCR and protected rows.
- Complete VT-LU0196-shaped residual rows materialize canonical evidence despite stale automatic reasons; Job 4's `14:30`/`16:47` remains fail-closed.
- SGC-T-3C0715 trusted `06-57` plus historical sender mapping remains deterministic; untrusted dash text is rejected.
- Execute locks and processes bounded 200-row chunks and reports `eligibility_changed` when state changes after planning.

## Proven root causes

- Stored recovery ignored scalar values already returned in retry/initial/final extraction snapshots and only reparsed raw text.
- Residual candidate aggregation had one opaque label and could not separate true conflict, duplicate-equivalent values or legacy conflict markers.
- Complete `EXCEPTION` rows needed an explicit recovery state transition before canonical materialization, but diagnostic did not identify that execute action precisely.
- Execute did not explicitly detect eligibility changes after its pre-lock analysis.

Production subtype counts across the reported 488 residual exceptions remain NOT VERIFIED because no production command was run.

## Verified checks

| Check | Result |
|---|---|
| Phase 16.10.7 diagnostic/backlog targeted set | PASS: 29 tests, 227 assertions |
| Worker parser + TimeMark targeted set | PASS: 29 tests |
| Daily Photo/canonical/reconciliation targeted set | PASS: 122 tests, 815 assertions before final report-guard assertions; final full suite includes them |
| 1,000-row read-only guards | PASS: report/dry-run ≤15 queries; diagnostic ≤20 queries |
| 1,000-row residual execute guard | PASS: <30s, ≤70 queries, zero canonical writes for true conflicts |
| Full Laravel suite | PASS: 287 tests, 1,500 assertions, 65.77s |
| Pint `--test` | PASS: 7 changed PHP files |
| PHP syntax | PASS: 7 changed PHP files |
| `git diff --check` | PASS |

Tests use SQLite in-memory databases. Production MySQL distribution, live images and runtime behavior are NOT VERIFIED.

## Database / API / runtime impact

- Migration: NO.
- API: NO change in Phase 16.10.7.
- Worker: NO change in Phase 16.10.7; no worker update/restart required for this delta.
- Collector: NO change and no restart required.

## NEXT ACTION

1. Review the final local checkpoint commit on `fix/phase-16-10-7-residual-daily-photo-recovery` with `git show --stat --oneline HEAD`.
2. If review passes, explicitly authorize push/PR; do not push, merge or deploy automatically.
3. After a separately authorized Laravel deployment, run read-only `php artisan ocr:daily-exception-diagnose --limit=20`, review actionable subtype counts/samples, then run `php artisan ocr:daily-backlog-recover --dry-run` with the same approved scope.
4. Run `--execute` only after dry-run confirms deterministic `eligible_recover`/`eligible_retry`, true conflicts and protected rows remain excluded, no eligibility-changing worker activity is in flight, and the user separately authorizes the production write.
5. No migration, OCR worker update/restart or Collector restart is required for Phase 16.10.7.
