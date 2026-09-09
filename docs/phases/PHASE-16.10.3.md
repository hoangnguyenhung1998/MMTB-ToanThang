# Phase 16.10.3 — Downstream Integration / Exception-First Continuation

- Status: PLANNED
- Created: 2026-09-09
- Updated: 2026-09-09 21:07:25 +07:00
- Branch: `phase16-10-exception-first` (VERIFIED)
- Starting HEAD: `c8e3796040809afa81840ed1423a3c7149e7aaa5` (VERIFIED)

## Status

`PLANNED`. Phase 16.10.3 application implementation has not started. Only the continuity/bootstrap documentation audit is VERIFIED complete.

## Objective

Audit existing downstream daily-photo consumers against the canonical case/interval foundation, prove integration gaps and acceptance criteria, then implement the smallest exception-first integration that preserves existing reconciliation and human-review rules.

## Context

Phase 16.10.1 created canonical case identity/materialization. Phase 16.10.2 created deterministic canonical evidence pairing and ambiguity states. The current downstream reconciliation service still queries reviewed `OcrJob` evidence directly and contains its own conventional four-photo auto-pairing logic. That boundary is VERIFIED as the starting point for audit; it is not yet a verified defect or a finalized implementation plan.

## Dependencies

- Phase 16.10.1 — `docs/phases/PHASE-16.10.1.md` — VERIFIED at `af417bb`.
- Phase 16.10.2 — `docs/phases/PHASE-16.10.2.md` — VERIFIED at `c8e3796`.

Both are direct dependencies: this Phase must preserve canonical identity/provenance from 16.10.1 and interval/ambiguity/recompute semantics from 16.10.2.

## Related Phases

- Phase 16.9 — `docs/phase-16-9-daily-photos.md` — RELATED. It documents existing reconciliation, manual pairing, rounding, protected-row, and exception behavior that the audit must inspect and preserve where still applicable.
- Phase 16.9.1 and Phase 16.9.2 are not required to resume this Phase unless a direct dependency is discovered.

## Scope

- Read-only trace of canonical cases/intervals into reconciliation sync, manual allocation/review, exception presentation, and directly affected tests.
- Evidence-backed gap and acceptance-criteria documentation.
- After audit only: minimal exception-first integration for proven gaps.
- Targeted tests and full regression proportional to the final implementation.

## Out of Scope

- Redesigning UI without proven need.
- CTMS integration unless the audit proves it is a dependency.
- Rewriting OCR, Collector, reconciliation, or automation workers.
- Changing Zalo account/session handling.
- Production migration, production data repair, deploy, or runtime restart.
- Cleaning up, moving, renaming, or deleting legacy/historical documentation.
- Redoing Phase 16.10.1 or Phase 16.10.2.
- Refactoring or changing application logic during the continuity task.

## Verified Starting Point

- After a successful `git fetch origin`, branch `phase16-10-exception-first` tracks `origin/phase16-10-exception-first` at the same commit.
- HEAD `c8e3796`; its parent is `af417bb`; `af417bb` parent is production base `9f8fe11`. The production base is an ancestor of HEAD, with two commits ahead and zero commits behind according to local remote refs.
- The canonical foundation and pairing tests pass at current HEAD.
- Full regression: `223 passed`, `1002 assertions`, `0 failed`, `17.09s`.
- `DailyPhotoSyncService` currently queries `OcrJob` directly and independently auto-pairs only a conventional four-photo case.
- No Phase 16.10.3 implementation commit or application diff exists.

### Invariants to Preserve from Phase 16.10.1

- Image machine authority, deterministic historical sender fallback, retained observation/provenance, capture-date work date, unresolved evidence behavior, explicit assignment ambiguity, canonical identity, materialization idempotency, and human-correction provenance.

### Invariants to Preserve from Phase 16.10.2

- Capture-time/arrival-independent pairing, multiple shifts, retained odd evidence, duplicate/near-duplicate/multi-assignment ambiguity, recompute idempotency, unchanged interval identity stability, and correction/requeue recomputation.

See the dependency records for verified evidence and exact tests.

## Plan / Checklist

- [x] Continuity/bootstrap documentation audit.
- [ ] Trace all downstream consumers from canonical case/interval artifacts.
- [ ] Identify evidence-backed integration gaps and non-gaps.
- [ ] Define acceptance criteria and protected existing business rules.
- [ ] Implement minimal exception-first integration for proven gaps only.
- [ ] Add targeted integration tests.
- [ ] Run full regression after implementation.
- [ ] Review application and documentation diff before requesting commit/push approval.

## Changes

- Documentation only: created the Phase continuity record and dependency records; updated Project State and Phase Index.
- Application/business logic, tests, config, and migrations: no changes in this continuity task.

## Tests / Findings

| Test / Check | Status | Evidence |
|---|---|---|
| Git branch/HEAD/upstream | VERIFIED | `phase16-10-exception-first`, HEAD `c8e3796`, tracking ref at same commit |
| Commit ancestry | VERIFIED | `9f8fe11 → af417bb → c8e3796` |
| Phase 16.10.1 feature tests | VERIFIED | 10 tests passed as part of full suite |
| Phase 16.10.2 feature tests | VERIFIED | 13 tests passed as part of full suite |
| Full Laravel regression | VERIFIED | 223 passed, 1002 assertions, 0 failed, 17.09s |
| Production deployment/migrations for 16.10.x | NOT VERIFIED | No production action or evidence was inspected/requested |
| Required downstream behavior/change set | NOT VERIFIED | Must be established by the next read-only audit |

## Technical Decisions

- Treat Phase 16.10.3 as `PLANNED`, not `IN PROGRESS`, because no implementation artifact exists.
- Treat direct `OcrJob` consumption in downstream reconciliation as an audit boundary, not automatically as a bug.
- Preserve existing protected manual/reviewed rows and other Phase 16.9 business rules unless the audit and approved scope explicitly change them.
- Separate VERIFIED foundation invariants from PLANNED integration work.

## Remaining Issues

- Determine which downstream consumers should use canonical cases/intervals and how ambiguity/incomplete states should surface.
- Determine exact exception-first behavior and required UI/API impact from code/tests; do not infer it from the branch name alone.
- Determine targeted tests and migration/deployment impact after the audit. Current production state remains NOT VERIFIED.

## Git State

- Branch: `phase16-10-exception-first`.
- HEAD: `c8e3796040809afa81840ed1423a3c7149e7aaa5`.
- Phase 16.10.1: `af417bb1c7ffee88dd25266ae78e5e40aff953cc`.
- Phase 16.10.2: `c8e3796040809afa81840ed1423a3c7149e7aaa5`.
- Production base `origin/production` after fetch: `9f8fe11a8682229cfa9e5e9167857d2c57d14c03`.
- Continuity documentation and the user's `AGENTS.md` change are uncommitted/unpushed.
- Commit/push/PR/merge/deploy: not performed.

## Next Action

Follow the exact `NEXT ACTION` in `docs/PROJECT_STATE.md`: verify Git, then perform a read-only consumer trace from the canonical models/services into `DailyPhotoSyncService`, `DailyPhotoWorkflowService`, relevant controllers/views, and directly affected tests; record an evidence table and acceptance criteria before any implementation. Do not redo 16.10.1/16.10.2.
