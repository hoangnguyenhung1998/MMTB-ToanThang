# Phase 17.2 — Retroactive BCH Transfer, Relink & Safe Merge Stabilization

- Status: CODE COMPLETE / COMPLETED locally — mandatory local checks PASS; production NOT VERIFIED; STOP.
- Date: 2026-10-07. Base `db3f5c1`; branch `phase16-11-1-ai-rescue-foundation`; initial clean tree/cached upstream 0/0.
- Dependencies: Phase 11/11.1; Phase 17.1; 16.9; 16.10.1–16.10.4 canonical identity/pairing/downstream; 16.11 protection. Instructions: AGENTS, laravel-mmtb skill, State/Index/Phase Management.
- User-reported production baseline period #8: 6774 inspected / 5885 correct / 889 unresolved; 138 NO_EFFECTIVE_ASSIGNMENT, 562 CANONICAL_RELATIONSHIP, 3 SEGMENT_AMBIGUITY, 186 TARGET_DUPLICATE. Production deployment/data NOT independently VERIFIED.

## Audit / component map

Goal: prevent stale/duplicate reconciliation identities on valid lifecycle changes and recover legacy deterministic relationships without deleting/recomputing business content. Scope: machine handover (single/batch), transfer, return, historical transfer boundary edit, reconciliation append/Daily Photo row creation, Repair Links, relationship metadata/audit/diagnostics and regression tests. Non-goals: migration, provider/model/prompt/schema AI, RapidOCR pipeline, workers/Collector, broad architecture rewrite, production access/actions.

Routes/auth → MachineOpsController transfer/return/handover → MachineService → assignment/event/ActivityObserver; reconciliation generator/append → assignment/day row identity; DailyPhotoCaseService → assignment/date scope_key; DailyPhotoSyncService ensureRows → case/assignment/day rows; Repair Links/validator → shared AssignmentInterval + indexed raw snapshots + explicit audit. No assignment-edit endpoint exists at baseline. Machine/assignment deletion routes are explicit existing destructive operations; no new deletion route. Migrations show no incoming FK to reconciliation row identity; audits reference subject_id polymorphically and must retain merge history. Canonical membership/interval/OCR case FKs are separately protected.

| Component | Decision |
|---|---|
| AssignmentInterval, period/row protection, full payload safety, batched transactional writes | REUSE AS-IS / focused writer extraction |
| MachineService, append/generator, DailyPhoto ensureRows, repair and diagnostic UI | REUSE WITH EXTENSION |
| DailyPhotoCaseService rematerialization to repair assignment | DO NOT REUSE: moving membership can delete/recompute intervals |
| Targeted historical propagation, boundary edit, canonical relationship-only relink, deterministic empty/identical merge | MISSING at baseline; added within existing services/schema |

## Confirmed source causes versus hypotheses

Confirmed: transfer closes source but lacks full service boundary/overlap validation and downstream propagation. Assignment is part of generator/Daily Photo/canonical identity, so append can create a second identity when historical rows remain stale. Phase 17.1 deliberately blocks canonical identity and conflicting target rows; production diagnostics corroborate this unresolved class.

Rejected blanket hypothesis: transfer never closes old assignment (it does). Exact cause of each production overlap/no-effective row is NOT VERIFIED; no automated timeline fabrication or latest-BCH inference.

## Implementation and safe policy (verified locally)

Positive intervals and strict overlap reuse AssignmentInterval. Future transfer validates source/target intervals, closes source, creates target and propagates affected machine/dates atomically. Exact repeat is a no-op. Historical edit requires a unique matching TRANSFER event and predecessor; preserves target's next transfer/return end, rejects handover/lifecycle ambiguity/overlap, propagates all intersecting periods. Locked periods/rows remain review.

Rich source + empty unlocked target: keep rich row ID, remove empty target, relink. Empty source + populated target: keep target. Equivalent full payload: preserve a survivor and audit both snapshots. Complementary/conflicting payload: no inferred field merge. Reviewed/confirmed/protected duplicates stay untouched. Two populated canonical selections cannot be combined automatically; one populated source case can move to unique target scope when target absent or genuinely empty, preserving case/membership/interval IDs and pairing. References to absent/foreign intervals and out-of-target capture times remain diagnostic. Shared canonical under any locked row/period cannot move.

| Source / target | Automatic action |
|---|---|
| Content-bearing source / empty unlocked unmanual draft target | Keep source ID and its original segment/content; remove empty target; relink source |
| Empty unmanual source / content-bearing target | Keep target ID/content; remove empty source |
| Equivalent complete business payload (including manual state, time, GPS, notes, canonical references) | Keep target; remove redundant source; OCR/journal ID set ordering is normalized for comparison |
| Complementary or conflicting payload, different hours despite same evidence IDs | No field union/overwrite; DUPLICATE_PAYLOAD_CONFLICT |
| Target reviewed/confirmed/locked | PROTECTED_DUPLICATE; no merge |
| Both source and target canonical scopes contain data | CANONICAL_CONFLICT; no combining populated cases |
| Populated source case / absent or truly empty target case | Keep source case/membership/interval IDs; remove proven empty target case only; update assignment/scope and existing OCR case-materialization metadata |
| Missing/foreign interval/job dependency, capture outside target, shared protected relationship | Explicit canonical diagnostic; no mutation |

Technical decisions: retain the schema's assignment-scoped unique row key while preserving existing content-bearing row identity during relink. Indexed machine/date/assignment occupancy and dependency waves release occupied identities safely; cycles/conflicts fail closed. Raw batched preload and CASE writes reuse Phase 11.1's bounded hot path; no assignment query per row, row hydration or per-row period scan. All source/target snapshots of merge are audited inside the same transaction. Business fields, photo storage paths/SHA256, OCR extracted payload, pairing state/policy/IDs, GPS/location, work times and manual values remain unchanged. Relationship fields and updated_at alone change for surviving rows; empty draft segment narrowing retains the existing strictly safe rule. Existing canonical pairing/materialization routines are never invoked by repair.

Historical edit requires a single TRANSFER event matching the target and a matching preceding source, plus no intervening RETURN/HANDOVER. It validates positive source/target intervals and overlap before writing, retains target time_out (next transfer/return), moves only event time/date metadata and affected relationship records. Both date and time boundaries are honored. A repeat with unchanged bounds is a no-op. Single/batch handover and return also validate and propagate under transaction/locks; missing history and true gaps remain diagnostics. Canonical content cannot be used to select among truly overlapping assignments; adjacent same-day segments are allowed when capture/pairing fits exactly one segment.

Append first repairs safe existing relationships and then avoids overlapping existing identities; Daily Photo ensureRows uses the same occupancy rule and reloads assignment/case relationships after the period lock. It preserves disjoint valid same-day segments. Full rebuild is not used by propagation/recovery; the existing explicit generation workflow is unchanged. Validator continues using shared AssignmentInterval semantics.

## Verification checkpoint

Dependent suite `artisan test --compact --filter='RetroactiveBchTransferTest|ReconciliationRepair|ReconciliationAppendAndRepairTest|DailyPhotoLargeVolumeReconciliationTest|CanonicalDailyPhoto'`: 118 PASS / 1047 assertions. Includes July–October revision, next transfer/return and same-day upper-bound isolation, other machine isolation, handover/return/batch handover, shared locked weekly canonical, equivalent/empty/conflicting merge, canonical capture rejection, missing interval dependency, append/materialization occupancy, repeat no-op, audit and mid-write rollback. Existing dependency chains/cycles, missing catalogs/assignments, true overlap and segment ambiguity retained with explicit conflict reason taxonomy.

Canonical performance fixture: 2400 rows, 1200 source cases, 2400 real database photo/OCR memberships, 1200 pairs; 640.01 ms / 146 queries / 0 row/case/OCR models hydrated during repair. Plain fixtures: content 1200 rows 199.30 ms / 41 queries / 0 row models; catalog 1200 rows 227.59 ms / 44 queries; cleanup 2400 rows 359.79 ms / 22 queries / 0 row models. Timings are local SQLite measurements, not production/MySQL estimates. Budgets assert <200 queries canonical, <100 plain, preservation and second-run no mutations/audit.

NO_EFFECTIVE diagnostics expose history missing / before first handover / timeline gap / after last assignment / after explicit RETURN with last lifecycle timestamp. Missing versus physically deleted history cannot be proved from current absent records alone; root cause of individual production legacy rows remains NOT VERIFIED. No assignment/catalog is fabricated and no current/latest BCH fallback exists.

Final full isolated SQLite suite `artisan test --compact`: **420 PASS / 2742 assertions / 134.86 s**. This includes assignment lifecycle, append, validator/export, Daily Photo/canonical/pairing, RapidOCR, AI Rescue and manual review regressions without live provider/network calls. Syntax checks of all 15 changed/new PHP files PASS. `vendor/bin/pint --test` on 9 new/repair/test files PASS. `git diff --check` PASS after fixing new blank-line whitespace. Legacy controllers/generator/sync retain surrounding formatting to keep the diff scoped.

Final full-run benchmark: content 1200 rows **163.21 ms / 41 queries / 0 row models**; canonical relink + empty-target merge 2400 rows / 1200 cases / 2400 photos **633.50 ms / 146 queries / 0 row/case/OCR models**; catalog 1200 rows **201.17 ms / 44 queries**; cleanup 2400 rows **413.90 ms / 22 queries / 0 row models**. No max_execution_time increase, dependency or new index.

## Changed files

- app/Http/Controllers/MachineBatchController.php
- app/Http/Controllers/MachineOpsController.php
- app/Services/MachineService.php
- app/Services/MachineAssignmentTimelineService.php (new)
- app/Services/Reconciliation/AssignmentRelationshipPropagation.php (new)
- app/Services/Reconciliation/CanonicalAssignmentRelinker.php (new)
- app/Services/Reconciliation/ReconciliationIdentityGuard.php (new)
- app/Services/Reconciliation/RelationshipBatchWriter.php (new)
- app/Services/Reconciliation/ReconciliationLinkRepairService.php
- app/Services/Reconciliation/ReconciliationGenerator.php
- app/Services/Reconciliation/DailyPhotoSyncService.php
- resources/views/machines/show.blade.php
- resources/views/reconciliation/periods/show.blade.php
- routes/web.php
- tests/Feature/Reconciliation/RetroactiveBchTransferTest.php (new)
- tests/Feature/Reconciliation/ReconciliationRepairPerformanceTest.php
- tests/Feature/Reconciliation/ReconciliationRepairStabilizationTest.php
- docs/PROJECT_STATE.md
- docs/PHASE_INDEX.md
- docs/phases/PHASE-17.2.md (new)

## Deployment / rollback / known risks

No schema/data migration, new index, frontend build or worker restart is required by this change. After separately authorized deployment, clear Laravel route/view/config caches using the existing deployment runbook (`php artisan optimize:clear` where applicable). Legacy recovery uses the existing period UI action "Kiểm tra / sửa liên kết BCH"; historical boundary edits use machine history "Sửa mốc điều chuyển hồi tố" only after checking authoritative lifecycle evidence. There is no new production batch/repair command. No production action has been performed.

Rollback code follows the existing controlled release process. Relationship/merge writes are audited and transactional, but reverting code does not automatically undo a successful data repair. Capture a database backup and row/case snapshots before an approved production repair; restoring data requires a separately approved incident/rollback plan, not a generic reverse merge.

Production/MySQL runtime/query plans and real concurrent submissions are NOT VERIFIED by isolated SQLite tests. Machine/period/assignment/case locks, transactions and retry guards plus double-submission/idempotency tests provide local safety evidence; they do not prove hosting contention performance. A single lifecycle transaction can touch several open monthly/weekly periods, so actual machine history volume should be measured during approved smoke. Legacy ambiguous TRANSFER event lineage is intentionally rejected. Locked periods/rows, conflicting canonical scopes, cross-boundary content, missing/deleted history/catalog and genuine timeline gaps may remain unresolved; no promise that all 889 baseline unresolved become zero. Historical linking preserves original segments and manual payload; if an actual historical segment spans incompatible assignments, manual review remains necessary.

## Production verification checklist — future authorized action only

1. Verify release/branch and take backup/snapshots; record period #8 baseline 6774 inspected, 5885 correct, 889 unresolved with 138/562/3/186 reason groups (user-reported, not independently verified).
2. Select T-XL0034; prove Cơ Hữu → TĐXD 02.1 effective source/target timestamps, any later B→C/return/handover and period lock status. Do not infer history from current BCH.
3. Snapshot row IDs/segments/content, hours, GPS/location, manual notes/state, case/membership/pair IDs, OCR metadata/content and photo references/checksums before mutation.
4. Run the existing Repair Links action once for the approved period. If timeline itself needs historical correction, separately authorize/use the historical boundary form with proven timestamps and affected-period snapshots.
5. Verify rich source/empty target leaves one survivor under the approved rule, correct assignment/project/BCH by effective time; valid distinct same-day segments stay separate. Confirm outside interval/other machines/later lifecycle/locked records unchanged.
6. Verify images/membership/pair IDs/pairing/raw times, OCR content, GPS/location, reconciliation hours and manual/HUMAN content match snapshots; inspect link/merge/canonical audits and actor/survivor snapshots.
7. Run validator/export readiness; record unresolved breakdown and inspect representative NO_EFFECTIVE/true overlap/conflicting/protected cases. Do not force counts to zero.
8. Only after the first run is proven safe, run Repair Links again; expect zero meaningful relink/merge and no duplicate link/merge audit. Record timings and any hosting exceptions/lock contention.
9. In an approved test case, verify future transfer and historical July–October revision with next-transfer/return/handover bounds, then append/Daily Photo sync without introducing a second identity.

## Completion checklist

- [x] Continuity/Git restore; audit findings and reuse map before code.
- [x] Future/historical scoped propagation and canonical relationship-only recovery.
- [x] Safe empty/equivalent merge and protected/conflict/manual diagnostics.
- [x] Content preservation, same-day/multi-period boundaries, repeat no-op and rollback tests.
- [x] Dependent/full regressions, >=1000-row benchmarks, syntax/Pint/diff review.
- [x] State/index/Phase checkpoint and deployment/production limitations documented.
- [ ] User review and separately authorized publication/deployment/production verification.

## NEXT ACTION

User review of 20 scoped local files. All mandatory local checks PASS; HEAD remains db3f5c1 on phase16-11-1-ai-rescue-foundation, cached upstream 0/0; 13 modified + 7 new files, uncommitted. STOP. No commit/push/PR/merge/deploy/production repair/migration/restart authorized.
