# Phase 17.3 — Unassigned Gap Reconciliation Recovery & Residual Validation Stabilization

- Date: 2026-10-07.
- Status: CODE COMPLETE / COMPLETED locally under the requested rich-data fail-safe policy — mandatory local checks PASS; production NOT VERIFIED; STOP for review.
- Verified baseline: clean tree, branch `phase17-3-unassigned-gap-reconciliation`, HEAD `6bb75f267015c94cadfd93abffb3624b10072d45` (Phase 17.2 merge); no configured upstream. No fetch/pull/commit/push/PR/merge performed.
- Discrepancy: prior PROJECT_STATE/PHASE_INDEX/17.2 document described uncommitted work at db3f5c1. Current Git proves the merge; earlier Phase session records remain historical. User-reported production baseline: 6774 inspected, 5157 correctly linked, 728 relinked, 728 stale drafts cleaned, approximately 161 unresolved / 162 blocking / 6 warnings. These counts and current production runtime are NOT independently VERIFIED.

## Objective / scope / dependencies

Prove legitimate gaps using effective assignment history across periods; remove only proven empty stale drafts; preserve business/canonical data with explicit review diagnostics; stop ACTIVE-period false blockers and source-overlap false positives. No transfer redesign, schema/dependency/provider/model/prompt/work-hour changes, workers/Collector/OpenClaw, automatic conflicting-content merge or production action.

Dependencies read: AGENTS, PROJECT_STATE, PHASE_INDEX, Phase 17.1/17.2, 11/11.1, canonical 16.10.1/16.10.2/16.10.3/16.10.4, 16.9, PHASE-MANAGEMENT runbook, laravel-mmtb skill. Automation-workers skill was inspected for routing; its actual worker scope does not apply to this domain-only implementation. No additional skills needed.

## Audit / proven root cause

1. Repair's joined assignment query loaded referenced sources and period-intersecting assignments, omitting the future assignment necessary to prove a cross-month gap. Its later NO_EFFECTIVE context was date-only, after action decisions, and collapsed several lifecycle states into TIMELINE_GAP.
2. ACTIVE validation blocked every machine with no in-period assignment without consulting surrounding history. ACTIVE is not continuous BCH membership.
3. Source-overlap validation called AssignmentInterval::segment on an old source even when that source did not exist on the row date. segment's clipping fallback returns an apparent all-day range; the validator then mislabelled a stale materialized row as real source overlap. T-XL0345 source ends Sep 10 and next source starts Sep 11: no actual source overlap.
4. Same-date empty drafts wholly after time_out could enter exact-source narrowing and become INVALID_SEGMENT, although their entire segment was a legitimate gap or after-return interval.

Rejected blanket hypotheses: a gap is corrupt/missing timeline by definition; ACTIVE must always belong to a BCH; transfer never closes source; source assignments overlap merely because reconciliation segments overlap. MachineService and MachineAssignmentTimelineService already retain separate OUT/IN timestamps, positive intervals, strict overlap validation, propagation, idempotency and later-boundary protection. No evidence justified redesigning them.

## Component map / decisions

| Component | Audit / change |
|---|---|
| MachineAssignmentTimelineService / MachineService / ops controller | Separate OUT/IN, interval assertions, historical lineage, next transfer/return bounds, atomic propagation: retained unchanged |
| AssignmentInterval | Positive source ranges, onDate, strict overlap and containment: reused unchanged |
| AssignmentTimelineState (new) | Read-only full-history gap union/index and lifecycle classification; shared by Repair, Validator and canonical propagation |
| ReconciliationLinkRepairService | One complete joined assignment snapshot per 100-machine batch, indexed gap decisions before narrowing/relink, safe cleanup/audit and preserved-data diagnostics |
| AssignmentRelationshipPropagation | Uses shared gap/lifecycle context for canonical-only residual review; historical affected-period/window protections retained |
| ReconciliationExportValidator | Batched full history shared by gap/source validation; ACTIVE gap exception; source date check before overlap; reconciliation-range warning names its actual layer |
| ReconciliationGenerator | Reuses positive AssignmentInterval validation; skips zero-length midnight boundary segments; existing assignment-derived enumeration/append repair retained |
| DailyPhotoSyncService | Already uses AssignmentInterval::contains before ensureRows materialization; stale canonical gap cannot create a reconciliation row. Unchanged, integration-tested |
| CanonicalAssignmentRelinker | Raw locked case/member/interval/job snapshots and protected shared scopes, relationship-only relink: unchanged. No relink/rebuild in gaps |
| ReconciliationIdentityGuard / RelationshipBatchWriter | Occupancy, dependency-wave release, quoted bound batch writes: unchanged |
| DailyPhotoCaseService / pairing / canonical membership | Assignment/work-date identity; rematerializing a membership can delete/recompute intervals. Not used for gap recovery |
| Period controller / policy / UI | Existing authenticated scoped Repair action and export validation retained; only 3 diagnostic labels added |

## Business semantics / taxonomy

Assignment A.time_out < B.time_in creates [A.time_out, B.time_in), regardless of reconciliation month or repair order. No assignment/evidence is pulled across OUT/IN. Gaps are computed from the union of valid assignment coverage: an enclosing/overlapping third assignment prevents false gap classification. Touching assignments create no gap and strict overlap does not treat equal boundaries as overlap. An ACTIVE machine whose entire period is contained in a proven legitimate gap has no generic missing-BCH blocker.

The read-only index sorts history once, constructs coverage gaps once and binary-searches gap/lifecycle ranges. A range intersecting assignment coverage returns null so the existing Phase 17.2 effective-candidate/overlap rules decide. The classifier does not select a BCH or change history. Invalid source history is conservatively INVALID_TIMELINE; a gap containing/crossing an unmatched HANDOVER/TRANSFER/RETURN boundary is LIFECYCLE_AMBIGUITY. The last lifecycle event includes TRANSFER so a subsequent transfer is not hidden by an older RETURN.

| Timeline / repair reason | Behavior |
|---|---|
| LEGITIMATE_UNASSIGNED_GAP | Valid unassigned span; no automatic old/new BCH |
| UNASSIGNED_GAP_REQUIRES_REVIEW | Data-bearing/manual/canonical gap row retained byte-for-byte; explicit review and export blocker |
| AFTER_RETURN / AFTER_RETURN_REQUIRES_REVIEW | Empty proven stale draft may be removed; business content remains untouched/review |
| PROTECTED_RELATIONSHIP + gap/return context | Reviewed/confirmed/rejected/timestamp locks preserved |
| BEFORE_FIRST_HANDOVER | No invented assignment; unresolved NO_EFFECTIVE with explicit context |
| AFTER_LAST_ASSIGNMENT | Not proven to be an interior gap; existing expired-empty policy retained; rich data unresolved |
| ASSIGNMENT_HISTORY_MISSING | No inference about physically deleted history; no fabricated assignment |
| LIFECYCLE_AMBIGUITY / INVALID_TIMELINE | Fail closed, no cleanup or relink |
| TRUE_ASSIGNMENT_OVERLAP | Existing actual effective-source ambiguity remains manual; no source/ID tie-break |
| SEGMENT_AMBIGUITY | Range crosses assignment/gap or incompatible effective segments; no truncation of business data |

Existing target-duplicate, canonical conflict, missing catalog and protection reasons are retained. New source-overlap checking requires both source assignments to exist on the date; materialized row-range overlap reports reconciliation data/relationship conflict, not source history corruption. True source overlaps still warn. Row data outside effective BCH remains blocking; this change does not force validator counts to zero.

## Cleanup / evidence preservation / schema limitation

Automatic new cleanup requires: the entire positive segment is a proven gap/after-return; exact source belongs to this machine; row is DRAFT with no reviewed/confirmed timestamps; no manual timestamp, business payload, OCR/AI/journal IDs, GPS/location, work-hour/time values, evidence signature/status/summary, notes/content, canonical membership content or canonical interval reference. Reuse current hasData and CanonicalAssignmentRelinker checks. All fields beyond established structural/generated change metadata default to preservation. Missing segment is treated as a full day only for already proven empty rows; business rows with missing/invalid segments remain invalid/manual.

Cleanup uses existing transaction/period/row/source/canonical locks and batched deletes; full before-row snapshot and timeline context are inserted in activity_logs. Audit failure rolls back deletion. No new incoming reconciliation-row FK exists in current migrations; canonical/OCR/evidence records are independent and are not deleted by row cleanup. Empty standalone canonical cases are not rebuilt or moved. Rerun yields no meaningful writes/deletes/new repair audit.

Important limitation: row assignment/project/BCH columns are nullable, and canonical has an unresolved scope, but simply clearing those columns is not a safe complete representation. DailyPhotoSyncService::caseForRow and image lookup select exact machine/assignment/date, IdentityGuard treats orphan identity conservatively, and canonical rematerialization can delete/recompute paired intervals. Automatically detaching row/case/OCR identity would require coordinated downstream orphan/evidence behavior beyond this focused repair. Use the explicitly authorized fail-safe option: preserve rich row and all original identity/payload with UNASSIGNED_GAP_REQUIRES_REVIEW. Its stale persisted BCH is not endorsed as effective; validator flags it and prevents export. No assignment is fabricated or reassigned. Proposed smallest follow-up, after separate review: design a relationship-only unresolved-scope move with conflict/protection handling and exact evidence lookup/provenance tests; no migration is proven necessary. This follow-up is NOT implemented or started.

Raw before/after snapshots cover cases, memberships, intervals/pair IDs, OCR content/metadata, attachments/storage paths/checksums and Zalo messages. Separate payload cases cover OCR, photo IDs, GPS, hours, manual/HUMAN notes, journal, AI references and reviewed rows. Repair does not invoke OCR, AI Rescue, pairing, materialization or resync. New canonical-only propagation+Daily Photo sync test proves no forced BCH/row creation or evidence mutation in a gap.

## Test coverage / verification

All tests use phpunit.xml APP_ENV=testing, SQLite :memory:. PHP runtime: `D:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`. No development or production database used.

| Requirement | Verification |
|---|---|
| A cross-day / E empty draft / N idempotency | Sep 1 OUT → Sep 10 IN; 9 stale drafts removed, full audit, unchanged source timeline, second run no-op; append creates only valid later days |
| B cross-month / C T-XX0694 | Aug 8 14:24 OUT → Oct 13 14:24 IN; all Sep cleaned before Aug/Oct repair; same-day boundary gaps and ACTIVE machine created before period tested |
| D evidence | Independent payload cases and real paired canonical/photo/OCR records remain exact across two repairs; reviewed relationship remains protected |
| F same-day / G equal boundary | 15:00 OUT with 15:01/equal IN; generator, Repair and Validator retain two positive disjoint segments without false overlap; separate minute-gap stale segment cleanup |
| H long gap / O performance | 1200 rows / 40 machines / Jun–Jan gap, bounded queries, zero row hydration, source timeline not filled; retained 17.2 canonical benchmark |
| I retroactive | Historical OUT moved to July 1, IN to Sep 10; July/August affected rows cleaned atomically without October/current-period dependency; repeat unchanged |
| J A→GAP→B→GAP→C | Both transfer events and independent gaps/assignment boundaries tested |
| K return/handover | Same-day after-return empty draft cleanup; rich return row preserved; lifecycle-crossing draft and invalid history fail closed; existing handover/return tests retained |
| L true overlap | Actual overlapping assignments stay manual with original payload and source warning |
| M T-XL0345 | Exact source timestamps; real 11:18–13:24 and 17:45–18:01 canonical pairs, stale full-day row, new 15:00 draft and later evidence; no false source-overlap diagnostic or forced merge |
| Protection / rollback / canonical-only | Locked period rejection; injected gap audit failure restores row; standalone canonical gap stays review and sync cannot create stale row |

- New targeted suite: 14 PASS / 124 assertions.
- Final dependent filter `UnassignedGapRecoveryTest|ReconciliationRepair|ReconciliationExportValidator|RetroactiveBchTransfer|ReconciliationAppendAndRepair`: 104 PASS / 946 assertions, 5.74 s.
- Final full suite `artisan test --compact`: 434 PASS / 2866 assertions, 32.35 s. Includes the final real next-day photo/canonical snapshots and TRANSFER lifecycle diagnostics.
- Final scoped Pint `--test`: 7 files PASS. PHP syntax `-l`: 7/7 PASS. `git diff --check`: PASS; new-file whitespace/final-newline checks PASS. Final 11-file source/test/documentation scope reviewed; no secret/runtime/config/provider/dependency/migration/worker files. Minimal Pint style normalization in touched validator/generator files; no unrelated files formatted.

## Performance / concurrency

No per-row full-period scan, assignment/canonical SQL N+1, Eloquent row/case/OCR hydration or timeout increase. Full source history is read once per machine batch for cross-period proof; effective candidate lists are separately filtered to row-date bounds once, keeping old per-date candidate behavior. Gap/event lookup is logarithmic after sorting/union construction. Existing duplicate payload hash, indexed occupancy, dependency waves and batch writes retained. More historical assignments/events are held/locked; 100-machine batching is not a hard history-memory bound for a pathological machine.

Same-machine before-change benchmark (4 PASS / 40 assertions): canonical 2400 rows / 1200 cases / 2400 photos 252.04 ms / 146 queries / 0 row-case-OCR models; content 66.04 ms / 41 queries; catalog 85.78 ms / 44 queries; empty cleanup 164.01 ms / 22 queries.

Final full-suite after-change benchmark: canonical 257.16 ms / 147 queries / 0 models; content 103.85 ms / 42 queries / 0 row models; catalog 88.12 ms / 46 queries; empty cleanup 143.10 ms / 23 queries / 0 row models. New long gap 181.64 ms / 23 queries / 0 row models. Final independent dependent run: canonical 262.36 ms; content 64.69 ms; catalog 78.73 ms; empty cleanup 143.61 ms; gap 83.09 ms; query/hydration counts identical. Local time varies; algorithm/query/hydration budgets all PASS. One lifecycle read added per machine batch; query budgets retained (<200 canonical, <100 plain, <40 new gap). Recorded Phase 17.2 reference 633.50 ms / 146 queries ran on a different local session; compare same-machine before/after rather than claiming a timing speedup from that reference.

Atomicity, period/source/canonical/lifecycle read locks and existing protected scopes retained. SQLite verifies rollback/idempotency/identity, not real MySQL concurrency. Added full-history/event locks can change contention/deadlock scope; production-equivalent MySQL tests and live latency/query plans remain NOT VERIFIED.

## Residual audit / NOT VERIFIED

T-XL0345 supplied source timestamps prove a 24h gap; local regression proves the false source-overlap mechanism and corrected message. Its evidence spans before/after new IN, so safe auto-merge/relink is not established and review remains.

SGC-T-3C0466 (2026-09-08) and VT-3C0664 (2026-09-03): no authoritative assignment timelines were supplied or found in repository fixtures/docs. Actual production root cause is NOT VERIFIED, not presumed identical to T-XL0345. Diagnostics now distinguish true source versus reconciliation-layer overlap; inspect their actual timelines in separately authorized production verification. True overlaps remain manual. No production DB/API/read/repair performed.

## Production verification plan — future separately authorized actions only

1. Review local diff/test report; separately authorize publication/deployment. Verify exact release commit/runtime and baseline period #8 lock status. Take recoverable DB backup and raw snapshots before any approved repair.
2. Snapshot machine 23 and assignments 110/398, all period #8 row IDs/segments/payload/status, canonical case/member/interval/pair IDs, OCR/AI metadata, image attachment paths/checksums, GPS/hours/manual data and audit counts. Prove OUT Aug 8 14:24 / IN Oct 13 14:24 from authoritative history and check RETURN/HANDOVER/TRANSFER events. No timeline edits to fill the gap.
3. Record validator blocking/warnings and full Repair diagnostics before/after. Run exactly one separately approved existing Repair Links UI action for September; do not repair August first and do not use a global batch/new command. Record HTTP status/elapsed/inspected/correct/repaired/removed/unresolved plus reason breakdown. Verify totals reconcile.
4. For each removed gap/return row, verify DRAFT/unprotected/proven empty, full audit snapshot and absent canonical/business dependencies. Verify rich/protected gap rows remain exact and have explicit manual reason; no old/new BCH forced, no lost photos/OCR/GPS/hours/manual/pairing. Compare all immutable evidence snapshots.
5. Validate again. T-XX0694: September empty stale rows gone and generic ACTIVE/no-assignment blocker absent; rich residuals remain blocking/manual. August/October boundary history unchanged. Do not require all ~162 blockers to disappear.
6. T-XL0345: verify assignments 256/420 do not overlap; source-overlap warning absent; reconciliation/evidence conflict and stale-row blocking remain until an independently reviewed deterministic resolution. Preserve row 85905/canonical intervals and row 85906 evidence; do not auto-merge 98926 into rich conflicting data.
7. Independently snapshot source timelines/materialized ranges for SGC-T-3C0466 Sep 8 and VT-3C0664 Sep 3. If actual assignments overlap, retain true-source warning/manual review; if only materialized ranges overlap, label that layer. Do not infer one shared root cause.
8. After validating safety of the first approved run, approve/run a second Repair action: zero meaningful relationship changes, duplicate/gap cleanup, evidence/timestamp changes and new repair audits. Reconcile row/evidence/canonical counts. Check runtime below unchanged host timeout.
9. Verify historical propagation and same-day/equal boundaries on a production-equivalent MySQL copy, including concurrent repair/transfer/append/sync and locked periods. Record actual results before asserting concurrency or production PASS. No migration, worker restart or provider change required.

## Expected production result / risks

Proven empty stale rows in legitimate gaps move from generic unresolved/blocking to audited removals; valid ACTIVE gap periods stop generating generic missing-BCH blockers. Content-bearing gap/return rows move to UNASSIGNED_GAP_REQUIRES_REVIEW / AFTER_RETURN_REQUIRES_REVIEW or PROTECTED_RELATIONSHIP, not deletion or guessed relink. True overlaps, conflicting cross-boundary segments, missing/invalid history/catalog, locked/protected data remain review. Exact counts cannot be predicted from user summaries alone. No claim that all 161 residuals are repairable or all warnings disappear.

Code rollback does not restore previously cleaned rows; use before snapshots/backup/audit under separate approved recovery. This Phase contains no new migration, repair command, dependency, frontend build, worker/provider/secret change.

## Exact files changed

- app/Services/Reconciliation/AssignmentTimelineState.php (new)
- app/Services/Reconciliation/AssignmentRelationshipPropagation.php
- app/Services/Reconciliation/ReconciliationExportValidator.php
- app/Services/Reconciliation/ReconciliationGenerator.php
- app/Services/Reconciliation/ReconciliationLinkRepairService.php
- resources/views/reconciliation/periods/show.blade.php
- tests/Feature/Reconciliation/UnassignedGapRecoveryTest.php (new)
- tests/Feature/Reconciliation/RetroactiveBchTransferTest.php
- docs/PROJECT_STATE.md
- docs/PHASE_INDEX.md
- docs/phases/PHASE-17.3.md (new)

## Checklist / result / NEXT ACTION

- [x] Restore context and verify current Git baseline; audit actual source/contract/schema.
- [x] Implement shared cross-period gap classification and protected empty-draft cleanup.
- [x] Preserve evidence and separate source/materialized diagnostics; no transfer redesign.
- [x] Mandatory A–O targeted/dependent coverage and performance/idempotency/rollback checks.
- [x] Final full suite/style/syntax/whitespace/scope review recorded.
- [ ] User review; separately authorized publication/production verification.

Result: local requested implementation and A–O regressions PASS under the explicit fail-safe option for rich gap data; this does not claim production data is repaired or every residual relationship resolved. No production action performed.

NEXT ACTION: user reviews the 11-file local diff and this Phase, especially AssignmentTimelineState, safe cleanup audits, validator diagnostics and the preserved-rich-row limitation. STOP. Do not implement a detachment follow-up or another Phase automatically. No commit/push/PR/merge/deploy/production repair/migration/runtime restart/next Phase authorized.
