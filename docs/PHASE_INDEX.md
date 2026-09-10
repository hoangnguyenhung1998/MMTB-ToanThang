# Phase Index

> Minimal recovery map for the current work. Read the current Phase and direct dependencies; do not backfill or read all historical phases by default.

| Phase | Purpose | Status | Dependencies | Related | Git reference | Documentation path |
|---|---|---|---|---|---|---|
| 16.10.1 | Canonical Daily Photo Foundation | VERIFIED | Production base `9f8fe11` | Phase 16.9 | `af417bb` | `docs/phases/PHASE-16.10.1.md` |
| 16.10.2 | Deterministic Daily Photo Pairing | VERIFIED | Phase 16.10.1 | Phase 16.9 | `c8e3796` | `docs/phases/PHASE-16.10.2.md` |
| 16.10.3 | Canonical Daily Photo Downstream Integration | VERIFIED in Git at task start | Phase 16.10.1; Phase 16.10.2 | Phase 16.9 | `d36cfc1` | `docs/phases/PHASE-16.10.3.md` |
| 16.10.4 | Automatic Daily Photo reconciliation and Zalo machine mapping | COMPLETED locally — 250 tests / 1187 assertions PASS | Phase 16.10.1; Phase 16.10.2; Phase 16.10.3 | Phase 16.9 | Local checkpoint; `git log -1 -- docs/phases/PHASE-16.10.4.md` | `docs/phases/PHASE-16.10.4.md` |

## Relationship

`16.10.1 Canonical Foundation → 16.10.2 Deterministic Pairing → 16.10.3 Canonical Downstream Integration → 16.10.4 Auto-first Reconciliation + Sender Mapping`

- 16.10.1 is a dependency because 16.10.3 must consume and preserve canonical case identity and resolution provenance.
- 16.10.2 is a dependency because 16.10.3 must consume and preserve canonical evidence/interval/ambiguity semantics.
- Phase 16.9 is related because its existing daily-photo reconciliation and manual-review behavior is the downstream boundary preserved by 16.10.3; it is not a substitute for either canonical dependency.

## Status Vocabulary

- `VERIFIED`: supported by current Git/code/test evidence.
- `NOT VERIFIED`: context exists but evidence is insufficient.
- `PLANNED`: scope is identified but implementation has not started.
- `IN PROGRESS`: implementation has started and repository artifacts prove it.
- `COMPLETED locally`: requested local scope and mandatory checks pass; this does not imply remote publication or deployment.
- `BLOCKED`: a specific blocker prevents progress.

Do not infer `COMPLETED`, `MERGED`, `DEPLOYED`, or `PRODUCTION VERIFIED` from this index.

## Phases Not Required to Resume Current Work

- Phase 16.9.1 and Phase 16.9.2 are not direct prerequisites for reviewing or resuming the Phase 16.10.3 downstream integration.
- Do not read older Phase history, OCR/Collector/automation histories, or legacy documentation unless a concrete dependency appears during the audit.
- Legacy Phase documentation remains at its current path and must not be moved solely for continuity cleanup.
