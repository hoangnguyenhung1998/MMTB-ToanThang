# Project State

- Updated: 2026-09-10
- Current Phase: 16.10.4 — Automatic Daily Photo reconciliation and Zalo machine mapping
- Status: COMPLETED locally — implementation, tests and continuity documentation verified.
- Branch: `phase16-10-exception-first`
- Verified parent/base: `d36cfc1` — Phase 16.10.3, fetched and fast-forwarded from GitHub before edits. The initial working tree was clean.
- Phase checkpoint: local commit containing this document, message `feat: automate daily photo reconciliation and zalo machine mapping`. Resolve its hash with `git log -1 -- docs/phases/PHASE-16.10.4.md`; the final task report records the resulting hash.
- Remote publication / PR / merge / production deployment / production backfill / runtime restart: NOT PERFORMED and not authorized.

## Verified result

- OCR completion automatically materializes canonical evidence and creates/updates reconciliation rows in open periods, without reviewed/confirmed/four-photo gates.
- COLLECTING uses certain intervals and retains unmatched marks; unknown times remain NULL. Ambiguity remains fail-closed.
- Existing `DailyTimeAllocator` owns rounding, the 420-minute daily HC budget, OT, assignment boundaries and overlap validation.
- Scoped “Cập nhật ảnh hằng ngày” resync operates on the current period and selected BCH, preserves resolved historical machines and protects manual/reviewed/confirmed rows.
- Evidence display reads current canonical sources independently of protected row provenance.
- Zalo mapping UI requires sender and machine only. Confident OCR learns missing defaults, image code wins for its evidence, and receipt-effective history handles fallback and delayed OCR. Existing legacy mappings and HUMAN corrections remain auditable.
- Archive exports available originals including partial/ambiguous evidence and more than four photos, with exact count-only missing notes. Missing physical originals do not block other images.
- Default Daily Photo review controls/navigation have been removed; explicit correction remains available.

## Latest verified checks

PHP executable: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

| Command / check | Result |
|---|---|
| `artisan test --compact --filter='AutomaticDailyPhoto|CanonicalDailyPhoto|DailyPhotoWorkflow|DailyImageArchive|DailyImageException|DailyTimeAllocator'` | PASS: 75 tests, 418 assertions, 10.93s |
| `artisan test --compact tests/Feature/Reconciliation tests/Unit/ReconciliationTimeAllocatorTest.php` | PASS: 32 tests, 116 assertions, 3.39s |
| `artisan test --compact` | PASS: 250 tests, 1187 assertions, 24.58s |
| Pint `--test` on changed non-Blade PHP files | PASS: 16 files |
| PHP `-l` on those files | PASS |
| `npm run build` | PASS: 60 modules |
| OCR list JavaScript with removed bulk controls | PASS in Node VM |
| `git diff --check` | PASS |

Tests used SQLite in-memory databases. No migration was applied to the application's normal database. Build emitted an existing Browserslist data-age warning; dependencies were not upgraded.

## Database / rollout

- New migration: `2026_09_10_000001_create_zalo_sender_machine_mappings.php` adds sender-machine history and allows NULL capture datetime for date-only evidence.
- Existing mappings/timestamps are retained; no mass data rewrite.
- Later deployment requires migration before code activation. No Collector/OCR worker contract change or Phase-specific restart is needed.
- Older 16.10.3 pairing assumes non-null capture timestamps: after date-only data exists, do not blindly roll code back. Preserve the nullable-aware reader or prepare a separate safe rollback. Details are in the Phase document.

## Limitations / blockers

- No outstanding failing automated check or implementation blocker.
- Needs an existing GENERATED/REVIEWING reconciliation period. Missing/ambiguous assignment remains an exception rather than an invented BCH.
- Existing allocator limits on disjoint same-kind intervals remain explicit exceptions; canonical evidence has no four-photo cap.
- Live browser layout, production SQL engine and production-scale performance: NOT VERIFIED.

## NEXT ACTION

1. Inspect the latest local Phase 16.10.4 checkpoint (`git status --short --branch`, `git log -1`, and `docs/phases/PHASE-16.10.4.md`). Do not repeat implementation.
2. Optionally verify the local UI with representative data: one/three/four marks, selected-BCH resync, changed evidence on a manual row, partial ZIP notes, and sender mapping change with delayed OCR.
3. Wait for explicit user authorization before push/PR/merge/deploy or production/runtime actions. If deployment is authorized later, follow the migration/rollback requirements above and the deployment runbook.
4. Do not start a new Phase automatically.
