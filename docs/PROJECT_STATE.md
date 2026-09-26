# Project State

- Updated: 2026-09-26
- Current Phase: 16.10.10.1 — Versioned Manual Daily Photo Re-OCR Hotfix.
- Status: COMPLETED locally — implementation, targeted/related/full Laravel suites, static checks, continuity docs and local checkpoint commit complete.
- Branch: `hotfix/versioned-manual-daily-reocr`.
- Verified base: `cdbab092093db0cd78beb6e77eb9e60ce3079821` — Phase 16.10.10 checkpoint.
- Push / PR / merge / deploy / production command / production re-OCR / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- `ocr:daily-manual-retry` accepts optional `--retry-version=<numeric-dot-version>`.
- Without the option, legacy `manual_reocr.attempted_at` behavior is unchanged.
- With the option, a legacy-attempted unresolved job can be requeued once only when that exact version has no entry in `manual_reocr.version_attempts`.
- Version request/claim/worker/completion/result history is appended without overwriting the legacy attempted timestamp or snapshot.
- HUMAN/reviewed/protected, canonical, queued/processing/lease, missing-source and non-daily guards remain authoritative.
- Dry-run is read-only; execute only requeues the existing OcrJob for the laptop worker.
- No migration, Python worker file, Collector file or runtime configuration changed.

## Verified checks

| Check | Result |
|---|---|
| Baseline manual retry suite | PASS: 13 tests / 63 assertions |
| Versioned manual retry targeted suite | PASS: 18 tests / 101 assertions |
| Manual retry + OcrJob API + Daily Photo workflow | PASS: 59 tests / 333 assertions |
| Full Laravel suite | PASS: 319 tests / 1,707 assertions / 50.90s |
| PHP syntax / Pint `--test` / diff check | PASS: 3 application files / 4 PHP files / clean diff check |

## Production evidence motivating the hotfix

- Pre-hotfix dry-run: 354 manual considered, 0 eligible, 22 protected/reviewed-confirmed skipped, 332 legacy-attempted skipped.
- The 332 rows were blocked by the legacy global marker, not proof that pipeline 16.10.10 had run.
- Post-hotfix production totals remain NOT VERIFIED until an authorized read-only versioned dry-run is reviewed.

## NEXT ACTION

1. Review the local diff and checkpoint commit on `hotfix/versioned-manual-daily-reocr`.
2. If approved later, separately authorize push/PR/merge and Laravel hosting deployment; there is no migration.
3. Confirm the existing laptop worker already runs the approved Phase 16.10.10 OCR pipeline; do not update or restart it for this hotfix.
4. After deployment approval, run only `php artisan ocr:daily-manual-retry --dry-run --retry-version=16.10.10` and verify protected remains 22 before any mutation is authorized.
5. Only after separate approval, run the matching `--execute --retry-version=16.10.10` once and monitor the existing queue.

## Still prohibited

Do not push, create/merge a PR, deploy, run production commands, execute production re-OCR, choose another retry version, restart the OCR worker, or restart Collector without separate authorization.
