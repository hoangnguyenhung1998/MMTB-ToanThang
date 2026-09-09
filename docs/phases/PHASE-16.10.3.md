# Phase 16.10.3 — Canonical Daily Photo Downstream Integration

- Status: IN PROGRESS — implementation/tests VERIFIED locally; awaiting user review
- Created: 2026-09-09
- Updated: 2026-09-09 22:04:36 +07:00
- Branch: `phase16-10-exception-first` (VERIFIED)
- Starting HEAD: `d358b87776ca220efd43c13bc06f522ff5466b83` (VERIFIED)

## Status

The downstream integration and automated verification are complete in the local unstaged diff. The Phase is not marked completed because review, commit, push, merge, deployment, and production verification have not occurred.

## Objective

Connect deterministic canonical daily-photo cases/intervals to automatic downstream reconciliation and image exception/archive consumers, while failing closed whenever the canonical layer cannot decide safely and preserving all human-reviewed/manual values.

## Context

Phase 16.10.1 established canonical case identity and evidence materialization. Phase 16.10.2 established deterministic capture-time interval pairing and explicit ambiguity states. The audit confirmed that three downstream consumers still sorted and paired reviewed `OcrJob` rows independently, allowing canonical and legacy pairing decisions to compete.

## Dependencies

- Phase 16.10.1 — `docs/phases/PHASE-16.10.1.md` — VERIFIED at `af417bb`.
- Phase 16.10.2 — `docs/phases/PHASE-16.10.2.md` — VERIFIED at `c8e3796`.

Both are direct dependencies: this Phase consumes their identity, provenance, interval, ambiguity, and recomputation contracts without changing them.

## Related Phases

- Phase 16.9 — `docs/phase-16-9-daily-photos.md` — RELATED. Its rounding, manual allocation, protected-row, archive, and exception behavior remains relevant.
- Phase 16.9.1 and Phase 16.9.2 are not required to review or resume this Phase.

## Scope

- Trace canonical case/interval flow through reconciliation sync/workflow, Exception Center, Archive, and relevant tests.
- Make canonical intervals the automatic downstream pairing source when daily-photo mode is enabled.
- Fail closed for collecting/ambiguous/unallocatable cases.
- Refresh automatic downstream values idempotently after correction/requeue.
- Preserve explicit manual allocation and reviewed/manual reconciliation rows.
- Add targeted integration tests and run full regression.

## Out of Scope

- CTMS integration.
- Collector or OCR redesign.
- UI polish or broad redesign; only the minimal canonical status/filter wording is included.
- Broad reconciliation/workflow refactor.
- Zalo account/session changes.
- Migration, production data repair, deploy, or runtime restart.
- Cleanup, move, or rename of legacy/historical documentation.
- Redoing Phase 16.10.1 or Phase 16.10.2.

## Verified Starting Point

- Branch `phase16-10-exception-first` and HEAD `d358b87` were clean and matched `origin/phase16-10-exception-first` before implementation.
- `DailyPhotoCaseService` materialized evidence into an assignment/work-date case and `DailyPhotoPairingService` maintained deterministic intervals/status.
- `DailyPhotoSyncService::preview()` independently queried approved `OcrJob` rows and only auto-paired a fixed conventional four-photo pattern.
- `DailyImageExceptionService` independently sorted raw jobs and inferred completion/exception status from counts and duplicate times.
- `DailyImageArchiveService` independently sorted raw jobs and paired them with `chunk(2)`.
- `DailyPhotoWorkflowService::allocate()` intentionally used reviewed raw candidates for explicit human allocation; this was not an automatic-pairing defect.
- Starting full-suite baseline from the continuity checkpoint was `223 passed`, `1002 assertions`, `0 failed`.

## Plan / Checklist

- [x] Trace downstream consumers from canonical case/interval artifacts.
- [x] Prove automatic legacy-pairing gaps and identify the intentional manual-candidate non-gap.
- [x] Define the `READY` / `COLLECTING` / `PAIRING_AMBIGUOUS` contract and protected-row rules.
- [x] Implement minimal canonical reconciliation integration.
- [x] Integrate canonical states/intervals into Exception Center and Archive.
- [x] Add targeted downstream integration tests.
- [x] Verify one, two, and three shifts where existing allocator kinds can represent them.
- [x] Verify repeated sync, correction refresh, requeue stale-result removal, and protected rows.
- [x] Run Phase 16.10, Workflow, Exception Center, reconciliation, and full regression tests.
- [x] Update continuity documentation with VERIFIED findings and precise NEXT ACTION.
- [ ] User review of the uncommitted diff.
- [ ] Separate commit/push checkpoint only after explicit authorization.

## Changes

### Application

- `app/Services/Reconciliation/DailyPhotoSyncService.php`
  - Selects the exact assignment/work-date canonical case.
  - Allocates only canonical `READY` intervals; collecting/ambiguous/unallocatable states produce no automatic hours.
  - Includes canonical status/diagnostics/interval identity in evidence signatures.
  - Fixes targeted current/previous-day row selection with `whereDate` so correction/requeue refresh actually runs.
  - Retains reviewed raw candidates solely for explicit human allocation.
- `app/Services/DailyImageExceptionService.php`
  - Uses canonical case states and intervals under daily-photo mode; keeps the legacy path when disabled.
- `app/Services/DailyImageArchiveService.php`
  - Uses canonical intervals for complete exports and exposes unmatched/ambiguous evidence without inventing pairs; keeps the legacy path when disabled.
- `app/Http/Requests/IndexDailyImageArchiveRequest.php`
  - Accepts the canonical `PAIRING_AMBIGUOUS` exception filter.
- `resources/views/daily-images/exceptions.blade.php`
  - Adds the minimal ambiguity filter label.
- `resources/views/daily-images/index.blade.php`
  - Describes canonical pairing and displays canonical archive status labels.

### Tests

- `tests/Feature/CanonicalDailyPhotoDownstreamIntegrationTest.php` — new end-to-end downstream regression coverage.
- `tests/Feature/DailyPhotoWorkflowTest.php` — its direct fixture now materializes canonical cases so daily-mode workflow tests exercise the real source of truth.

### Migration / Config / Production

- Migration: NO.
- Config change: NO.
- Production change/deploy: NO.

## Tests / Findings

| Test / Check | Status | Evidence |
|---|---|---|
| New downstream integration test | VERIFIED | 8 passed, 50 assertions |
| Phase 16.10 + Workflow + Exception Center | VERIFIED | 50 passed, 251 assertions, 0 failed, 2.97s |
| Reconciliation feature/unit group | VERIFIED | 32 passed, 116 assertions, 0 failed, 1.42s |
| Full Laravel regression | VERIFIED | 231 passed, 1052 assertions, 0 failed, 9.27s |
| Commit/push/PR/merge | NOT VERIFIED | Not performed; prohibited in this task |
| Production migration/deploy/verification | NOT VERIFIED | Not performed and out of scope |

## Technical Decisions

- Canonical `DailyPhotoCase.status` and `DailyPhotoInterval` are the only automatic pairing decision when daily-photo mode is enabled.
- `READY` does not bypass existing `DailyTimeAllocator` or assignment constraints; any allocation conflict fails closed.
- Existing time-of-day shift buckets remain the downstream work-hour classification contract; this Phase changes pair ownership, not rounding/payroll rules.
- `COLLECTING` and `PAIRING_AMBIGUOUS` retain evidence for review but never generate heuristic hours.
- Explicit manual allocation keeps raw approved source candidates because the user, not an automatic algorithm, selects those endpoints.
- Protected rows are never overwritten; evidence changes are flagged.
- Legacy behavior is retained only when `daily_photos.enabled` is false for compatibility with existing non-daily-mode tests/operation.

## Remaining Issues

- No known implementation blocker or failing automated test.
- The current diff still requires user review and a separately authorized commit/push checkpoint.
- Production behavior remains NOT VERIFIED because no deployment was requested or performed.

## Git State

- Branch: `phase16-10-exception-first`.
- Base HEAD: `d358b87776ca220efd43c13bc06f522ff5466b83`.
- Dependency commits remain `af417bb` and `c8e3796` in history.
- Phase 16.10.3 changes are local, unstaged, uncommitted, and unpushed.
- PR/merge/deploy: not performed.

## Next Action

Read `docs/PROJECT_STATE.md` and review the complete local diff against this contract. Confirm only the listed application, tests, and continuity docs changed; confirm no migration/config/production artifact. If the review passes, wait for explicit user authorization before staging, committing, or pushing the Phase 16.10.3 checkpoint. Do not start additional implementation, merge, or deploy.
