# Phase Index

> Minimal recovery map for the current work. Read the current Phase and direct dependencies; do not backfill or read all historical phases by default.

| Phase | Purpose | Status | Dependencies | Related | Git reference | Documentation path |
|---|---|---|---|---|---|---|
| 16.10.1 | Canonical Daily Photo Foundation | VERIFIED | Production base `9f8fe11` | Phase 16.9 | `af417bb` | `docs/phases/PHASE-16.10.1.md` |
| 16.10.2 | Deterministic Daily Photo Pairing | VERIFIED | Phase 16.10.1 | Phase 16.9 | `c8e3796` | `docs/phases/PHASE-16.10.2.md` |
| 16.10.3 | Canonical Daily Photo Downstream Integration | VERIFIED in Git at task start | Phase 16.10.1; Phase 16.10.2 | Phase 16.9 | `d36cfc1` | `docs/phases/PHASE-16.10.3.md` |
| 16.10.4 | Automatic Daily Photo reconciliation and Zalo machine mapping | VERIFIED in production history; large-volume allocate-times follow-up COMPLETED locally — 272 tests / 1,362 assertions PASS | Phase 16.10.1; Phase 16.10.2; Phase 16.10.3 | Phase 16.9; Phase 16.10.5 | Production merges `eecee42`, `8db9f8c`; local large-volume hotfix from base `5839be6` | `docs/phases/PHASE-16.10.4.md` |
| 16.10.5 | Auto Recovery OCR & Exception Backlog | VERIFIED in production history through PR #46 | Phase 16.10.4 | OCR worker; Exception Center; reconciliation sync | Production merge `5839be6`; checkpoints `9dc4bbb`, `233c5ed` | `docs/phases/PHASE-16.10.5.md` |
| 16.10.6 | Daily Photo OCR Extraction & Recovery Hardening | COMPLETED locally — 280 Laravel / 49 worker tests PASS | Phase 16.10.5 | OCR worker parser; canonical materialization; backlog recovery | Base `3527725`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.6.md` |
| 16.10.7 | Residual Daily Photo Exception Root-Cause & Deterministic Recovery | COMPLETED locally — 287 Laravel tests / 1,500 assertions PASS | Phase 16.10.6 | Canonical materialization; retry merge; actionable diagnostic | Base `d5aa4b3`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.7.md` |
| 16.10.8 | Daily Photo OCR Hardening — time/date recovery and non-daily image gate | COMPLETED locally — 291 Laravel / 56 worker tests PASS | Phase 16.10.7 | OCR worker classification/parser; stored recovery; canonical exclusion | Base `68bf7d8`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.8.md` |
| 16.10.9 | Daily Photo TimeMark-First OCR + Source-Aware Fallback | COMPLETED locally — 293 Laravel / 58 worker tests PASS | Phase 16.10.8 | TimeMark recognizer; stored OCR recovery; protected backlog | Base `c53c6c8`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.9.md` |
| 16.10.9.1 | Safe Manual Daily Photo Re-OCR | COMPLETED locally — 306 Laravel / 58 worker tests PASS | Phase 16.10.9 | OcrJob claim/lease; manual backlog protection | Production base `854b175`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.9.1.md` |
| 16.10.10 | Daily Photo OCR Consolidation + Field Lock + OCR Case Library | COMPLETED locally — 314 Laravel / 65 worker tests PASS | Phase 16.10.8; Phase 16.10.9; Phase 16.10.9.1 | TimeMark field lock; stored OCR; review UI; regression cases | Production base `ff46acf`; branch `phase16-10-10-daily-ocr-consolidation`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.10.md` |
| 16.10.10.1 | Versioned Manual Daily Photo Re-OCR Hotfix | COMPLETED locally — 319 Laravel tests / 1,707 assertions PASS | Phase 16.10.9.1; Phase 16.10.10 | OcrJob claim/lease; legacy manual re-OCR backlog | Base `cdbab09`; branch `hotfix/versioned-manual-daily-reocr`; final local checkpoint at current branch HEAD | `docs/phases/PHASE-16.10.10.1.md` |

## Relationship

`16.10.1 Canonical Foundation → 16.10.2 Deterministic Pairing → 16.10.3 Canonical Downstream Integration → 16.10.4 Auto-first Reconciliation + Sender Mapping → 16.10.5 Auto Recovery + Backlog → 16.10.6 Extraction + Recovery Hardening → 16.10.7 Residual Deterministic Recovery → 16.10.8 Context-aware OCR + Image Gate → 16.10.9 TimeMark-first + Source-aware Fallback → 16.10.9.1 Safe Manual Re-OCR → 16.10.10 Field Lock + OCR Case Library → 16.10.10.1 Versioned Manual Re-OCR Hotfix`

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
