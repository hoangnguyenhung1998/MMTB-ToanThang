# Project State

- Updated: 2026-09-22
- Current Phase: 16.10.8 — Daily Photo OCR Hardening.
- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS.
- Branch: `fix/phase-16-10-8-daily-photo-ocr-hardening`.
- Verified production base: `68bf7d8`.
- Current HEAD: final local checkpoint commit containing this document; resolve with `git rev-parse HEAD`.
- Push / PR / merge / deploy / production recovery / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- Source-aware time/date evidence now retains crop/rotation/context and excludes work intervals, durations, invalid tokens and dates after local receipt date.
- Trusted TimeMark repair supports bounded dash and one trailing `1`; controlled Vietnamese date corruption and compact exact machine forms are recoverable.
- Online classification can terminate confident hour-meter and known non-daily images before the expensive Daily TimeMark crop pass. This gate still uses the existing full-image classification OCR and is not fully pre-OCR.
- Stored backlog cleanup supports the same ignored terminal states, provenance, canonical detachment, protected-row guard, idempotency and expanded dry-run metrics/samples.
- No schema change. API additions are backward-compatible for old workers; deployment requires Laravel/API before worker update/restart.

## Proven root causes

- Previous aggregation discarded evidence source/context and treated all crop times as peers.
- Work interval values were not excluded before conflict detection.
- Controlled trailing-digit and compact/corrupted date forms were outside the parser grammar.
- Capture dates lacked a `received_at` upper bound.
- Known non-daily images had no terminal ignored state; hour meters had no multi-signal classifier.
- Stored Laravel machine recovery did not share the worker's compact whole-text exact catalog scan.

## Verified checks

| Check | Result |
|---|---|
| Worker targeted parser/classifier/TimeMark/API/lease set | PASS: 51 tests |
| Laravel API + backlog + diagnostic targeted set | PASS: 57 tests, 432 assertions |
| Full Python suite | PASS: 56 tests, 1.93s |
| Python compile | PASS with isolated pycache path; project pycache was not deleted |
| 1,000-row backlog guards | PASS: final full-suite timings 2.66s and 1.55s; query guards intact |
| Full Laravel suite | PASS: 291 tests, 1,537 assertions, 57.16s |
| Pint `--test` | PASS: 12 changed PHP files |
| PHP syntax | PASS: 12 changed PHP files |
| `git diff --check` | PASS |

Tests use synthetic/mock OCR outputs and SQLite in-memory databases. Live images, production MySQL distribution and production runtime behavior are NOT VERIFIED.

## NEXT ACTION

1. Review the final local checkpoint commit on `fix/phase-16-10-8-daily-photo-ocr-hardening` with `git show --stat --oneline HEAD`.
2. If review passes, explicitly authorize push/PR; do not push, merge or deploy automatically.
3. After separately authorized deployment, deploy Laravel/API first, then update/restart the OCR worker and verify health plus one controlled flow. No migration or Collector restart is required.
4. Run read-only `php artisan ocr:daily-backlog-recover --dry-run --limit=20` with approved filters; review ignored/recovered/conflict/retry/protected counts and samples.
5. Run `--execute` only after the same-scope dry-run is accepted and production write is separately authorized.
