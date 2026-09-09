# Project State

- Updated: 2026-09-09 21:07:25 +07:00
- Current Phase: Phase 16.10.3 — Downstream Integration / Exception-First Continuation
- Current Branch: `phase16-10-exception-first` (VERIFIED)
- Current HEAD: `c8e3796040809afa81840ed1423a3c7149e7aaa5` — `feat: add deterministic daily photo pairing` (VERIFIED)
- Current Status: `PLANNED`
- Working Tree: MODIFIED intentionally; continuity documentation and the user's `AGENTS.md` change are uncommitted and not pushed.

## Objective

Audit how the existing daily-photo downstream flow consumes OCR evidence, compare it with the canonical `DailyPhotoCase` / `DailyPhotoInterval` foundation, identify proven integration gaps, and only then define and implement the smallest exception-first integration that preserves existing business rules.

Phase 16.10.3 implementation has not started. This checkpoint only establishes repository-based continuity.

## Dependencies

- Phase 16.10.1 — Canonical Daily Photo Foundation (`af417bb1c7ffee88dd25266ae78e5e40aff953cc`) — VERIFIED. Provides canonical machine/case identity, capture-date work date, assignment resolution, provenance, and idempotent materialization.
- Phase 16.10.2 — Deterministic Daily Photo Pairing (`c8e3796040809afa81840ed1423a3c7149e7aaa5`) — VERIFIED. Provides canonical evidence memberships, deterministic intervals, ambiguity states, and recomputation.

## Related

- Phase 16.9 — Daily Photos (`docs/phase-16-9-daily-photos.md`) — RELATED, not a direct prerequisite for resuming Phase 16.10.3. It documents the existing reconciliation/manual-review behavior that the downstream audit must preserve.
- Production base `9f8fe11a8682229cfa9e5e9167857d2c57d14c03` — VERIFIED as an ancestor of HEAD and the direct parent of Phase 16.10.1. It is not a Phase 16.10.3 implementation target in this checkpoint.

## Completed / Verified Steps

- [x] Audited the initial working tree without reverting, stashing, or overwriting user changes.
- [x] Verified branch, HEAD, upstream tracking after `git fetch origin`, commit ancestry, Phase 16.10.1 commit, Phase 16.10.2 commit, and production-base relationship.
- [x] Inspected the canonical models, migrations, services, and the two canonical feature-test files.
- [x] Confirmed the current downstream `DailyPhotoSyncService` still queries `OcrJob` directly and contains its own conventional four-photo pairing path. This is an audit starting point, not yet a proven implementation defect.
- [x] Ran the full Laravel regression suite successfully.
- [x] Created the repository continuity checkpoint and Phase records. This is documentation only; Phase 16.10.3 business implementation remains unstarted.

## Current Step

Continuity/bootstrap documentation audit is complete and ready for review. Phase 16.10.3 remains `PLANNED`; no application implementation or downstream integration audit has been performed beyond locating the current consumer boundary.

## Remaining Steps

- [ ] Audit the complete downstream path from canonical `DailyPhotoCase` / `DailyPhotoInterval` through reconciliation sync, manual allocation/review, exception presentation, and affected tests.
- [ ] Identify and document only evidence-backed integration gaps and acceptance criteria.
- [ ] Implement the minimal exception-first integration after the audit establishes scope.
- [ ] Add targeted integration/regression tests for the proven gaps.
- [ ] Run the full regression suite after implementation.
- [ ] Review the final implementation diff before any commit/push request.

## Latest Verified Tests

- Command: `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`
- Result: `223 passed`, `1002 assertions`, `0 failed`
- Duration: `17.09s`
- Verified at: 2026-09-09 during this continuity audit.
- Note: plain `php artisan test` was initially unavailable because `php` is not in `PATH`; the Laragon PHP 8.3.30 executable above completed the suite successfully.

## Latest Verified Findings

### Phase 16.10.1 invariants

- An OCR image-resolved machine is authoritative; sender/driver history is a deterministic fallback, and the observed asset code is retained.
- Sender/driver resolution is evaluated at the OCR capture wall-clock date/time and refuses ambiguous machine history.
- Work date comes from captured date, not Zalo send/arrival date.
- Evidence without a resolved machine/date/time does not create a canonical case.
- Case identity is assignment + work date when exactly one assignment matches; unresolved/ambiguous assignment uses an explicit machine + date unresolved-assignment scope rather than selecting randomly.
- Repeated materialization is idempotent, and separate assignments on the same machine/day can produce separate cases.
- Human correction records provenance and can materialize a previously unresolved case.

### Phase 16.10.2 invariants

- Pairing is ordered by capture datetime, is independent of arrival/job order, supports multiple shifts without a fixed interval count, and retains odd evidence as unmatched.
- Duplicate capture timestamps, active near-duplicate candidates, and ambiguous assignments block automatic pairing and produce explicit ambiguity diagnostics.
- Recomputing unchanged evidence is idempotent and preserves existing interval identities.
- Human time/machine/date correction, membership movement, and requeue trigger recomputation of affected cases; requeue detaches canonical membership.
- Next-day Zalo transmission does not change capture-date grouping/pairing.

### Downstream boundary for the next audit

- Canonical artifacts are currently referenced by materialization/pairing services and their tests.
- `app/Services/Reconciliation/DailyPhotoSyncService.php` still sources reviewed `OcrJob` rows directly and independently auto-pairs only its conventional four-photo case.
- Whether and how reconciliation, manual review, UI, or exception handling must consume canonical intervals is `NOT VERIFIED` until the Phase 16.10.3 audit is completed.

## Blockers

- None for the next read-only audit.
- Implementation scope is intentionally not yet proven; do not treat the located consumer boundary as authorization to change behavior.

## Do Not Do Yet

- Do not redo Phase 16.10.1 or Phase 16.10.2.
- Do not implement Phase 16.10.3 before completing and documenting the downstream audit and acceptance criteria.
- Do not redesign UI unless the audit proves it is required.
- Do not add CTMS integration unless it is proven to be a dependency.
- Do not rewrite OCR, Collector, reconciliation, Zalo account/session handling, or automation workers.
- Do not clean up, move, rename, or delete legacy documentation.
- Do not migrate or modify production data/configuration, deploy, restart workers/Collector, commit, push, create/modify a PR, merge, reset, stash, or rewrite history without explicit authorization.

## Phases Not Required to Resume Current Work

- Phase 16.9.1 lease-safe TimeMark OCR and Phase 16.9.2 Zalo Collector reliability are present in Git history but are not direct dependencies of the Phase 16.10.3 downstream audit.
- Older OCR, Collector, automation, intake, and unrelated reconciliation phase histories do not need to be read unless the audit discovers a direct dependency.

## NEXT ACTION

Start a fresh Phase 16.10.3 **read-only downstream integration audit**:

1. Read `AGENTS.md`, this file, `docs/PHASE_INDEX.md`, `docs/phases/PHASE-16.10.3.md`, and the dependency records `docs/phases/PHASE-16.10.1.md` and `docs/phases/PHASE-16.10.2.md`.
2. Verify Git before any edits: branch must be `phase16-10-exception-first`; HEAD must still be `c8e3796040809afa81840ed1423a3c7149e7aaa5` unless repository history shows an intentional newer commit; inspect `git status`, staged/unstaged diff, and preserve all local continuity changes.
3. Trace, without modifying code, from `app/Models/DailyPhotoCase.php`, `app/Models/DailyPhotoInterval.php`, `app/Services/DailyPhotoCaseService.php`, and `app/Services/DailyPhotoPairingService.php` into `app/Services/Reconciliation/DailyPhotoSyncService.php`, `app/Services/DailyPhotoWorkflowService.php`, relevant controllers/views, and `tests/Feature/DailyPhotoWorkflowTest.php` plus directly affected reconciliation/exception tests.
4. Produce an evidence table of current consumer, canonical source available, mismatch/gap (or no gap), preserved business rule, and required test. Distinguish VERIFIED findings from hypotheses.
5. Update this checkpoint and `docs/phases/PHASE-16.10.3.md` with the proven scope and acceptance criteria. Do **not** implement until that audit is complete; do not redo 16.10.1/16.10.2.
