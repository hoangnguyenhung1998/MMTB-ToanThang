# Project State

- Updated: 2026-09-09 22:04:36 +07:00
- Current Phase: Phase 16.10.3 — Canonical Daily Photo Downstream Integration
- Current Branch: `phase16-10-exception-first` (VERIFIED)
- Current HEAD: `d358b87776ca220efd43c13bc06f522ff5466b83` — `docs: add project continuity checkpoint for phase 16.10` (VERIFIED)
- Current Status: `IN PROGRESS` — implementation and tests are VERIFIED locally; user review and any later commit/push remain pending.
- Working Tree: MODIFIED intentionally with Phase 16.10.3 application, test, and continuity-documentation changes. Nothing is staged, committed, or pushed for this Phase.

## Objective

Make canonical `DailyPhotoCase` / `DailyPhotoInterval` the only automatic downstream pairing source while preserving exception-first behavior: `READY` cases may populate reconciliation deterministically; `COLLECTING` waits without inventing hours; `PAIRING_AMBIGUOUS` fails closed and remains on the exception path.

## Dependencies

- Phase 16.10.1 — Canonical Daily Photo Foundation (`af417bb1c7ffee88dd25266ae78e5e40aff953cc`) — VERIFIED.
- Phase 16.10.2 — Deterministic Daily Photo Pairing (`c8e3796040809afa81840ed1423a3c7149e7aaa5`) — VERIFIED.
- Continuity checkpoint (`d358b87776ca220efd43c13bc06f522ff5466b83`) — VERIFIED current base.

## Related

- Phase 16.9 — Daily Photos (`docs/phase-16-9-daily-photos.md`) — RELATED. Existing rounding, explicit manual allocation, protected-row, archive, and exception behavior was preserved where compatible.
- Reconciliation evidence sync is a downstream consumer, not a replacement for the canonical pairing source.

## Completed / Verified Steps

- [x] Traced `DailyPhotoCase` → `DailyPhotoInterval` → `DailyPhotoSyncService` → `DailyPhotoWorkflowService` → Daily Image Exception Center / Archive → reconciliation.
- [x] Proved that automatic reconciliation, Exception Center, and Archive independently re-paired reviewed `OcrJob` rows instead of consuming canonical intervals.
- [x] Replaced automatic legacy pairing with canonical case/interval consumption when daily-photo mode is enabled; legacy behavior remains behind the disabled feature path.
- [x] Implemented fail-closed handling for `COLLECTING`, `PAIRING_AMBIGUOUS`, and canonical intervals that cannot be safely allocated.
- [x] Preserved explicit manual source selection and protected manual/reviewed reconciliation rows.
- [x] Added correction/requeue refresh coverage and fixed filtered downstream sync to compare date columns with `whereDate`.
- [x] Added downstream integration coverage for one, two, and three shifts; collecting; ambiguity; idempotency; correction; requeue; protected rows; Exception Center; and Archive.
- [x] Ran Phase 16.10, DailyPhotoWorkflow, DailyImageExceptionCenter, reconciliation, and full Laravel regression suites successfully.

## Current Step

Implementation and automated verification are complete locally. The uncommitted diff is being handed off for user review; Phase 16.10.3 has not been committed, pushed, merged, or deployed.

## Remaining Steps

- [ ] User reviews the focused application/test/documentation diff.
- [ ] If review passes and the user explicitly authorizes it, create a Phase 16.10.3 checkpoint commit and push only to `origin/phase16-10-exception-first`.
- [ ] Treat merge, production migration, deployment, and production verification as separate future actions requiring explicit authorization.

## Latest Verified Tests

- Phase 16.10 + Workflow + Exception Center:
  - Command: `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\CanonicalDailyPhotoFoundationTest.php tests\Feature\CanonicalDailyPhotoPairingTest.php tests\Feature\CanonicalDailyPhotoDownstreamIntegrationTest.php tests\Feature\DailyPhotoWorkflowTest.php tests\Feature\DailyImageExceptionCenterTest.php`
  - Result: `50 passed`, `251 assertions`, `0 failed`, `2.97s`.
- Reconciliation suite:
  - Command: `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\Reconciliation tests\Unit\ReconciliationTimeAllocatorTest.php`
  - Result: `32 passed`, `116 assertions`, `0 failed`, `1.42s`.
- Full regression:
  - Command: `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`
  - Result: `231 passed`, `1052 assertions`, `0 failed`, `9.27s`.
- Previous checkpoint baseline: `223 passed`, `1002 assertions`, `0 failed`.

## Latest Verified Findings

### Source of truth and downstream contract

- `DailyPhotoCase` is the canonical assignment/work-date scope and state source.
- `DailyPhotoInterval` is the canonical deterministic pairing result; downstream no longer creates a second automatic pairing order when `daily_photos.enabled` is true.
- `READY` supplies ordered canonical intervals to `DailyTimeAllocator` and records `canonical_interval_id` in reconciliation provenance.
- `COLLECTING` supplies evidence for review but no intervals/allocation and therefore invents no hours.
- `PAIRING_AMBIGUOUS` supplies diagnostics to the exception path but no intervals/allocation and therefore fails closed.
- A `READY` interval that cannot satisfy existing allocator/assignment rules also fails closed instead of guessing.

### Correction, requeue, and idempotency

- Human correction recomputes canonical membership/intervals and refreshes automatic downstream values.
- Requeue detaches the evidence, recomputes the old case, and clears stale automatic hours through targeted reconciliation sync.
- The targeted `workDate` sync bug was caused by `whereBetween` date-boundary comparison returning no reconciliation rows; `whereDate` comparisons now select the intended current/previous work date.
- Canonical state and interval semantics are included in the evidence signature, so repeated unchanged sync is idempotent while correction/requeue changes are detected.

### Manual/reviewed safety

- Rows with `manually_edited_at` or status `REVIEWED`, `CONFIRMED`, or `REJECTED` are not overwritten; changed evidence only sets `has_evidence_changes`.
- `DailyPhotoWorkflowService::allocate()` retains explicit reviewed `OcrJob` candidates for human selection. Canonical intervals govern automatic pairing only.

### Preserved Phase 16.10.1 / 16.10.2 invariants

- Capture-date identity, authoritative/historical machine resolution, explicit assignment ambiguity, materialization idempotency, and correction provenance remain covered.
- Capture-time ordering, arrival-order independence, odd evidence retention, multiple shifts, duplicate/near-duplicate ambiguity, interval identity stability, and correction/requeue recomputation remain covered.

## Blockers

- None for review.

## Do Not Do Yet

- Do not redo Phase 16.10.1 or Phase 16.10.2.
- Do not add more Phase 16.10.3 implementation before reviewing the current diff unless a verified defect is found.
- Do not commit, push, create/modify a PR, merge, deploy, migrate production, change production configuration, or restart production/runtime workers without a new explicit instruction.
- Do not add CTMS integration, redesign OCR/Collector/UI, rewrite reconciliation, change Zalo sessions, or clean/move historical documentation.

## Phases Not Required to Resume Current Work

- Phase 16.9.1 lease-safe OCR and Phase 16.9.2 Collector reliability are not prerequisites for reviewing this diff.
- Older OCR, Collector, automation, intake, and unrelated phase histories do not need to be read unless review discovers a direct dependency.

## NEXT ACTION

Review the local Phase 16.10.3 diff without starting new implementation:

1. Read `AGENTS.md`, this file, `docs/PHASE_INDEX.md`, and `docs/phases/PHASE-16.10.3.md`.
2. Verify branch `phase16-10-exception-first`, base HEAD `d358b87776ca220efd43c13bc06f522ff5466b83`, `git status`, `git diff --stat`, and the complete unstaged diff. Preserve all listed local changes.
3. Confirm the diff only connects canonical cases/intervals to reconciliation, Exception Center, Archive, the minimal status filter/view text, tests, and continuity docs; verify no migration/config/production artifact exists.
4. Use the recorded targeted and full-suite results as the latest VERIFIED baseline. Re-run tests only if the diff changes during review or fresh verification is required.
5. If review passes, request or follow an explicit user instruction for the separate commit/push checkpoint. Do not commit, push, merge, or deploy merely from this NEXT ACTION.
