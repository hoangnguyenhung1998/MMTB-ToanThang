# Project State

- Updated: 2026-09-11
- Current Phase: 16.10.5 — Auto Recovery OCR & Exception Backlog
- Status: COMPLETED locally — first-mapping backlog hotfix and regression tests verified.
- Branch: `hotfix/phase-16-10-5-first-mapping-backlog`
- Verified parent/base: `3aa10f3` — Phase 16.10.5 plus direct idempotency regression.
- Initial working tree: clean.
- Hotfix checkpoint: local commit containing this document, message `fix: allow first sender mapping to recover old OCR backlog`. Resolve its hash with `git log -1 -- docs/phases/PHASE-16.10.5.md`; the final task report records the resulting hash.
- Push / PR / merge / production deploy / production migration / backlog recovery / runtime restart: NOT PERFORMED and not authorized.

## Completed milestone

- Added a unique canonical asset-code resolver: case-insensitive, Unicode-safe ASCII conversion, and removal of whitespace plus `- . _ / : ;`; normalized collisions fail closed.
- Invalid/missing OCR candidates now fall back to the direct sender mapping effective at message receipt; a valid OCR machine remains authoritative.
- Added one bounded targeted OCR retry with `retry_focus`, initial/final extraction provenance and `OCR_RETRY_FAILED` terminal classification.
- Updated the RapidOCR worker to scan only relevant regions for targeted retries and to keep normalized catalog collisions non-authoritative.
- Added normalized exception reason labels to Exception Center.
- Added sender dashboard counts and an explicit `XỬ LÝ ẢNH ĐANG CHỜ` action; saving mapping does not run recovery.
- Added read-only `ocr:daily-backlog-report` and shared chunked/idempotent backlog analysis/recovery service.
- Added additive provenance migration; no data backfill exists in the migration.
- Hotfix: backlog report/explicit recovery may use the unique first mapping for messages received before that mapping began; later mappings remain strictly receipt-effective and overlap remains fail-closed.
- Hotfix: an explicit mapping can recover invalid-machine rows whose aggregate confidence is low while preserving existing date/time and the one-retry limit for missing fields.

## Latest verified checks

PHP executable: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

| Command / check | Result |
|---|---|
| Backlog targeted `AutoRecoveryBacklogTest` | PASS: 14 tests, 86 assertions |
| Existing receipt-history targeted regression | PASS: 1 test, 12 assertions |
| Full Laravel `artisan test --compact` | PASS: 269 tests, 1,310 assertions |
| OCR worker `python -m unittest discover -s ocr-worker/tests -p test_*.py` | PASS: 38 tests |
| 1,000-evidence backlog report query-count guard | PASS within targeted suite |
| Pint `--test` on changed non-Blade PHP files | PASS: 18 files |
| PHP `-l` on changed PHP files | PASS: 18 files |
| Python `compileall` | PASS |
| `npm run build` | PASS: 60 modules transformed; existing Browserslist data-age warning only |
| `git diff --check` | PASS |

Tests use SQLite in-memory databases. No migration was applied to the application's normal database. No production report/recovery was run.

## Key decisions / safety

- Normalization is exact canonical-key matching only; no fuzzy match.
- A normalized collision is always ambiguous and does not use sender fallback.
- First manual sender onboarding can cover its existing waiting queue from the earliest recorded message; later mapping changes begin now and preserve receipt-time history.
- One automatic retry maximum; unresolved results remain manual exceptions and never invent time/date.
- Reviewed/corrected/rejected/HUMAN OCR and manual/reviewed/confirmed reconciliation rows remain protected.
- Recovery chunks rows, preloads mappings/catalog, recomputes cases once and is idempotent.

## Database / rollout

- New migration: `2026_09_11_000001_add_ocr_recovery_provenance_to_ocr_jobs.php`.
- Adds only nullable/defaulted OCR retry provenance columns and indexes; no mass update.
- Later authorized rollout requires Laravel migration plus OCR worker update/restart. Exact order and rollback considerations are in `docs/phases/PHASE-16.10.5.md`.

## Blockers / not verified

- No implementation blocker identified.
- Live browser layout, production SQL engine, production data distribution and production-scale recovery: NOT VERIFIED.

## NEXT ACTION

1. Inspect the latest local first-mapping backlog hotfix (`git status --short --branch`, `git log -1`, and `docs/phases/PHASE-16.10.5.md`). Do not repeat implementation.
2. Before any authorized production recovery, run the read-only sender report and confirm the expected mapped/auto/manual counts; recovery remains an explicit operator action.
3. Wait for explicit user authorization before push/PR/merge/deploy or production/runtime actions. If deployment is authorized later, follow the maintenance/migration/worker ordering and rollback requirements in the Phase document.
4. Do not start a new Phase automatically.
