# Phase 17.3 — Final Reconciliation Ambiguity Resolution & Production Residual Audit

- Updated: 2026-10-08; continuation of 17.3, no new Phase.
- Current status: LOCAL DIAGNOSTICS VERIFIED; mandatory local checks PASS; REPORT → STOP. Production case diagnosis and 126-row verification PENDING EVIDENCE, NOT COMPLETED.
- Verified baseline: clean `phase17-3-final-fix`, HEAD `1587f5a1b0ade3ad949dd6f94775688d5a630b96`, upstream `origin/production`, cached ahead/behind 0/0. No fetch/pull/Git writes. This supersedes the previous uncommitted f690ecf checkpoint below.
- User reports PR #61 deployed and September results: 6046 inspected, 5915 correct, 126 normalized (9 LEGITIMATE_UNASSIGNED_GAP / 117 AFTER_RETURN), 5 SEGMENT_AMBIGUITY, 5 validator blockers, 3 warnings, zero invalid timelines. The commit exists locally; live deployment and counts are NOT independently VERIFIED.

## Current AUDIT → ROOT CAUSE → DESIGN outcome

The baseline already has evidence-proven segment narrowing, nullable row/canonical representation, protection/identity conflict guards, idempotency, generator occupancy, automatic Daily Photo NULL-payload preservation, capture-time membership filtering and NULL-safe UI/export. Repair cannot safely narrow when business/canonical intervals span the boundary, timestamps are missing, references disagree, allocated endpoints are outside the candidate or journal timing is not proven. These are code paths verified with real DB fixtures, not proof of which path each production row follows.

The source timestamps supplied for machine 25 prove a real [Sep10 15:00, Sep11 15:00) gap and do not prove source overlap. An overlap warning names a materialized relationship layer unless full source histories demonstrate source overlap. For machines 55/135/221, even the complete neighboring assignment history is missing. The exact row segments, source of work hours, OCR/canonical evidence and locks for all five rows are absent. No new auto-fix or split rule is justified yet. Existing Validator blockers are retained.

### Five-case audit table (production facts versus missing evidence)

| Row ID | Machine | Date | Existing BCH | Candidate BCH | Evidence interval | Boundary | Root cause | Safe auto-fix? | Expected outcome |
|---|---|---|---|---|---|---|---|---|---|
| 85904 | 25 / T-XL0345 | 2026-09-10 | NOT VERIFIED | #256 catalog needs collection | NOT PROVIDED | #256 OUT 15:00; #420 IN Sep11 15:00 | NOT VERIFIED; source gap is supplied | NOT VERIFIED | Keep review until canonical/business times uniquely fit source or gap |
| 85905 | 25 / T-XL0345 | 2026-09-11 | NOT VERIFIED; warning mentions ME HLX / ME 10 | #420 catalog needs collection | NOT PROVIDED | #420 IN 15:00; old OUT previous day | NOT VERIFIED; warning alone is not source overlap | NOT VERIFIED | Unique after-IN evidence may narrow; before/after mixed evidence stays blocked |
| 86442 | 55 / SGC-T-3C0466 | 2026-09-08 | NOT VERIFIED; warning mentions HTTQ / TĐXD 10.4 | #422 plus missing neighbors | NOT PROVIDED | #422 IN 15:25 | NOT VERIFIED; full source history absent | NOT VERIFIED | Distinguish source/materialized overlap; never fabricate predecessor OUT |
| 87577 | 135 / VT-3C0664 | 2026-09-03 | NOT VERIFIED; warning mentions HTTQ / TĐXD 10.4 | #423 plus missing neighbors | NOT PROVIDED | #423 IN 15:26 | NOT VERIFIED; full source history absent | NOT VERIFIED | Same proof/protection checks; conflicting evidence remains manual |
| 89197 | 221 / VT-3C0696 | 2026-09-03 | NOT VERIFIED | #296 plus missing neighbors | NOT PROVIDED | #296 OUT 16:45 | NOT VERIFIED; later assignment/lifecycle absent | NOT VERIFIED | Before-OUT proof may narrow; after-OUT needs actual uncovered/lifecycle proof |

### Implementation justified by the evidence

1. `CanonicalAssignmentRelinker::narrowingReason` explains the exact existing proof failure (case/interval missing, journal timing unproven, canonical time/reference/protection conflict, business endpoint outside candidate, missing allocation endpoints). `canNarrow` delegates to that same proof; acceptance conditions are unchanged.
2. Constructor defaults retain all original Repair locks. Explicit read-only snapshots omit locks and reject `plan`/`flush` with LogicException. The audit never calls Repair, generation, sync or pairing.
3. `ReconciliationResidualAuditService` reads period rows, full source/lifecycle histories, canonical case/member/pair IDs, OCR times/references, referenced journal times, attachment checksums, same-day siblings and shared period protection. It lists both source and materialized overlaps, per-candidate reasons, nullable relationship consistency, unassigned contexts and prior normalization audits, then independently invokes the existing read-only Validator. SELECT queries are batched, with no query per row.
4. `reconciliation:residual-audit <period> [--row=...]` emits JSON for the five default focus IDs and every row with any NULL relationship in that period. Missing focus IDs are explicit, not silently substituted. No repair simulation or mutation option exists. Sensitive opaque metadata/raw OCR/notes are not emitted; selected payload snapshots are hashed.
5. `docs/runbooks/PHASE-17.3-RESIDUAL-AUDIT.md` provides 12 SELECT statements inside a MySQL READ ONLY transaction usable on the existing deployment, and the new command after separately approved deployment. SQL SELECT fields are regression-checked against the existing local schema. MySQL transaction syntax/execution on production was not run by this session.

### 126 normalized rows

The supplied 9/117 figures describe successfully normalized contexts, not 126 remaining errors. Current read-only audit separately reports actual NULL assignment counts, all-three-NULL consistency, timeline proof, canonical conflict reasons, audit history and Validator results. AFTER_RETURN derives from full coverage/lifecycle proof; a contradictory active assignment after RETURN remains blocking. Existing tests cover payload preservation, canonical identity, double Repair/no new audit, Generator disjoint append/full-rebuild protection, capture-time materialization, automatic Daily Photo preservation and NULL UI/workbook export. No exact production row-level result is claimed without the collected report.

Current snapshots prove current stored state only. Historical evidence preservation since a prior production Repair requires the pre-repair snapshot or relevant existing audit; counts and current hashes alone cannot prove it.

### Regression additions and current results

- Ten datasets cover each supplied boundary with uniquely contained real canonical evidence and with two real pairs spanning the boundary. Missing neighboring production ranges are explicitly synthetic test fixtures. No fabricated production Row/Assignment IDs or root causes.
- Tests compare original row business attributes, pairing/member/interval snapshots and full source history, retain mixed evidence/blocking and verify repeated Repair creates no new mutation/audit. Audit overlaps are checked against a stale materialized sibling while sources remain disjoint.
- SELECT-only audit regression verifies no data mutation, no locking SELECT, missing IDs/input errors, NULL gap/RETURN classifications, raw-note exclusion and read-only flush rejection.
- Performance audit: 1200 rows / 40 machines / 30 days has the same **18 queries** as 1 row; targeted run **492.91 ms**. Diagnostic service intentionally reuses the existing Validator's Eloquent read path; the zero-model-hydration guarantee applies to Repair, not the full diagnostic report.
- Final targeted reconciliation: **147 tests / 1417 assertions PASS / 16.60 s**. Full Laravel: **464 tests / 3283 assertions PASS / 119.89 s**. Pint --test and PHP syntax PASS on all 4 changed/new PHP files; git diff --check PASS. Final full-run audit: **428.32 ms / 18 queries**, same count as 1 row. Final Repair: assigned **403.12 ms / 147 queries / 0 models**, unassigned **507.60 ms / 147 / 0**, long gap **129.97 ms / 23 / 0 row models**. All tests use APP_ENV=testing / SQLite :memory:, no live provider/production DB.
- Retained Repair canonical benchmarks: assigned **449.75 ms**, unassigned **650.81 ms**, both **147 queries / 0 row/case/OCR models**. Long-gap repair **140.68 ms / 23 queries / 0 row models**. Timing is local SQLite host-dependent, not production latency. Timeout unchanged.

### Current safety, deployment and STOP

No migration/dependency/provider/worker/Collector/lifecycle/source assignment change; no altered auto-fix/business acceptance or Validator bypass. No production access/repair/split/merge/hour redistribution. No commit/push/PR/merge/deploy/restart. Reviewed/locked/canonical protection and atomic Repair writes retain defaults. Real production root causes, exact 126-row payload history and MySQL concurrency remain NOT VERIFIED.

NEXT ACTION (local checks complete): review the local diagnostic diff, then collect the runbook's read-only production evidence under operator control. Diagnose each five-case row against actual source/evidence intervals before designing any further mutation. Keep Phase 17.3 open. REPORT → STOP; future Git/deploy/production writes need explicit separate authorization.

## Prior continuation milestone — historical record

The following sections record the already-merged implementation and its prior local verification. Their f690ecf baseline, counts, file list and STOP checkpoint are historical; current verified Git and the section above take precedence.

## Goal, scope and continuity

Complete existing nullable UNASSIGNED representation while preserving identity and every business payload. Keep Laravel authority, RapidOCR primary, AI Rescue rescue-only, HUMAN/reviewed/locked protections, bulk preview/confirm/recheck authority, Phase 17.1 batching and Phase 17.2 relink/dedup/propagation protections. No schema migration, transfer redesign, provider/model/prompt/worker/Collector/OpenClaw changes, evidence merge/split, production repair or deployment.

Read/restored AGENTS, laravel-mmtb skill, PROJECT_STATE, PHASE_INDEX, Phase 17.1/17.2/17.3, related reconciliation/canonical/Daily Photo phase context and PHASE-MANAGEMENT. Current implementation overrides historical design. The initial 17.3 rich-gap review policy is explicitly superseded by this continuation; it remains a historical safety milestone, not current desired behavior.

Initial local milestone at f690ecf: 434 tests / 2866 assertions; 14 gap regressions; canonical benchmark about 257.16 ms / 147 queries / 0 row/case/OCR models. Previous State/Index still described an uncommitted 17.2 base at 6bb75f2/no upstream; current verified Git supersedes that checkpoint. No rollback of earlier improvements.

## Confirmed root causes and rejected hypotheses

1. Repair recognized legitimate gaps/AFTER_RETURN but treated all rich/manual/canonical data as review-only; therefore stale assignment/project/BCH relationships survived. This is the proven code mechanism behind the reported classes of residuals; it does not imply business data was wrong.
2. Nullable row relationships and nullable canonical assignment already exist, with a unique unresolved case scope. A schema redesign is unnecessary for deterministic unassignment. Canonical protection must preserve content, not freeze a demonstrably stale BCH link.
3. AssignmentTimelineState previously marked a machine invalid globally when any historical source record failed `start < end`; all periods/rows then inherited INVALID_TIMELINE. It also relied on the original item index when finding the first usable assignment. Historical reversed/empty records could therefore poison unrelated periods. Exact source histories for machines 79/261 were not supplied: the code mechanism is confirmed, their individual root causes remain NOT VERIFIED.
4. Generator and Daily Photo ensureRows treated any NULL assignment as a whole-day wildcard, blocking disjoint valid assignments. Unconditional automatic Daily Photo sync could clear payload when an unassigned row had no allocatable assignment.
5. BCH workbook export filtered out NULL BCH rows. Valid unassigned business data needs a visible workbook group, rather than disappearing from this export.
6. Preloaded Bulk Backlog candidates were date-scoped; DailyPhotoCaseService trusted that supplied collection without applying the direct-query capture-time filter. A sole future IN candidate could rematerialize gap evidence into the future BCH. The shared materializer now filters positive machine-owned ranges at the capture timestamp (half-open OUT), also for provided snapshots; no additional assignment queries or worker/provider changes.
7. T-XL0345 source times supplied by the user do not overlap. Its stale materialized row/canonical times span the new IN boundary; that is a relationship/segment issue, not proof of source overlap. Keep the previous corrected warning layer. Do not infer the other two machines share this cause.

## Architecture audit and implementation

| Component | Current continuation behavior |
|---|---|
| MachineAssignmentTimelineService / MachineService / transfer controllers | Existing distinct OUT/IN and history/boundary/atomic propagation retained; no transfer redesign |
| AssignmentInterval | Existing positive containment, strict overlap and same-day/equal boundary semantics retained |
| AssignmentTimelineState | Full-history gap union; valid-first coverage; binary indexed corrupt ranges with assignment IDs/start/end/issue; empty boundary records contribute no coverage; lifecycle event IDs/times and RETURN versus BCH-claiming events distinguished |
| ReconciliationLinkRepairService | One raw locked snapshot per machine batch; empty cleanup; rich DRAFT unassignment with stable ID; additive counters; target identity/canonical protection; lazy detailed diagnostics; existing dependency waves/batch writes retained |
| CanonicalAssignmentRelinker | Existing unresolved scope, NULL assignment, relationship metadata only; empty/populated target and protected shared case guards; no materialization/pairing/OCR recompute. Evidence-proven segment narrowing helper uses preloaded snapshots |
| AssignmentRelationshipPropagation | Same states for all affected historical periods and canonical-only cases; existing next lifecycle/window and locked-period guards retained |
| ReconciliationExportValidator | Same full-history states; validated NULL relationships accepted; source overlap blocks export; actual hours/canonical/OCR identity conflicts remain blockers; materialized overlap warning still names its layer |
| ReconciliationGenerator / ReconciliationIdentityGuard | Same assignment-derived generation and segment occupancy; NULL gaps no longer block disjoint append; full rebuild refuses NULL rows to preserve their content; unchanged identity guard protects overlapping inserts |
| DailyPhotoSyncService / DailyTimeAllocator | Existing NULL canonical lookup works; sync preserves NULL-row payload; preview/manual allocation accepts only proven unassigned time and existing caps/overlap protections; no hours recalculation in Repair |
| RelationshipBatchWriter | Existing bounded CASE writes, period scoping and quoted bindings retained, including NULL values |
| Period controller / two views / exports | Separate relink/unassign/cleanup/residual reporting, reason details and Không BCH display/group; auth/policies unchanged |
| DailyPhotoCaseService / membership / pairing | Provided date-scoped candidate collections are rechecked against the capture timestamp and positive source ranges, matching the direct query; the existing unique `machine:{id}|date:{date}|assignment:unresolved` contract reused. Repair never invokes membership rematerialization or recomputes pairing |

## Representation and business semantics

A proven uncovered range is valid UNASSIGNED. Row `machine_assignment_id`, `project_id`, `command_center_id` are NULL; work date, segment, stable ID and all business columns remain intact. Canonical case ID is retained, assignment becomes NULL and scope becomes `machine:{machine_id}|date:{work_date}|assignment:unresolved`. Existing OCR `case_materialization` relationship keys are updated; candidate assignment list is empty and existing resolution status becomes NOT_FOUND. OCR extraction and other metadata remain intact. Historical membership resolution/pairing information is preserved.

A.time_out < B.time_in creates [OUT, IN). Do not extend A or backdate B. Full history proves cross-month and retroactive gaps independently of the current/open period and repair order. OUT == IN is contiguous; OUT 15:00 / IN 15:01 has a real one-minute gap. No synthetic overlap, evidence, assignment or work hours are created.

Supported proven states: LEGITIMATE_UNASSIGNED_GAP, BEFORE_FIRST_HANDOVER, AFTER_LAST_ASSIGNMENT, AFTER_RETURN. Missing usable history is not proof of an assignment and remains manual. A zero-length historical boundary record is not coverage; when there is no usable history it does not manufacture a gap. Reversed intervals are scoped to their uncertain closed endpoint span and reported as REVERSED_INTERVAL; missing starts are conservatively unknown across history.

RETURN itself introduces no BCH. If an entire range is already proven uncovered on both sides of RETURN, it remains unassigned, including ACTIVE-period validation; crossed RETURN IDs are available. A live assignment after a previous RETURN is LIFECYCLE_ASSIGNMENT_CONFLICT. HANDOVER/TRANSFER inside uncovered time, or genuinely contradictory boundaries, remain LIFECYCLE_AMBIGUITY. No source event or assignment is edited by repair.

## Repair, cleanup, protection and data safety

- Empty stale draft with a machine-owned source, no content/protection/canonical dependency: deterministic cleanup with the existing full audit snapshot. Cleanup counters include lifecycle context.
- Rich/manual DRAFT entirely in a proven unassigned range: update only the three row relationship keys and updated_at; audit old/new/state. Canonical links are moved atomically to unresolved scope. No work-hour/content overwrite.
- Protected/reviewed/confirmed/locked row or shared canonical scope: manual PROTECTED_RELATIONSHIP / PROTECTED_CANONICAL_RELATIONSHIP, retaining existing protection.
- Populated conflicting canonical target or unassigned row identity: CANONICAL_CONFLICT / UNASSIGNED_IDENTITY_CONFLICT; no auto merge. Even an empty source case cannot delete a populated target.
- Existing validated NULL relationship: no change/no audit on second run, including an already-correct canonical scope shared with a locked period. Existing duplicate NULL identities are explicitly manual; protection is checked for moves rather than no-op scope validation. Canonical reference/time conflicts still diagnose explicitly rather than claiming correct just because row keys are NULL.

Repair invokes no DailyPhotoCaseService materialization. Its new read-only candidate filter governs subsequent authoritative materialization; existing explicit recheck/pairing workflow is retained. No member/interval/pair/photo IDs are rebuilt by Repair; no image/attachment/message bytes or references, OCR extraction/job status, AI Rescue reference, GPS/location, manual fields, journal IDs, regular/OT/confirmed/raw/rounded times or audit history are deleted. Rich rows retain stable IDs. Snapshot tests compare all payload fields except the explicitly audited relationship/segment/timestamp keys. Canonical/OCR snapshots exclude only relationship metadata and updated_at; pairing/evidence/interval/photo snapshots stay exact.

Existing row/period/source/canonical locks and atomic batch ordering remain. Existing NULL row uniqueness is guarded in the service/period lock rather than assumed from SQL NULL uniqueness. One unresolved canonical scope per machine/date is retained; distinct competing unassigned identities are manual, never automatically merged. Audit failure rolls back row deletion/updates, canonical scope and OCR metadata. SQLite tests prove rollback/interleaving invariants, not MySQL lock contention; actual concurrent repair/transfer/materialization on a production-equivalent MySQL copy remains NOT VERIFIED.

## Segment ambiguity and overlap policy

A fully contained row retains Phase 17.2 deterministic relink. For a stale broad segment, `canNarrow` additionally requires one canonical source with real intervals; all canonical timestamps/members and referenced OCR jobs must fit the intersection with one source assignment. Existing raw/rounded/confirmed/GPS/allocated times and interval JSON must fit; positive hours require actual allocated endpoints. Untimed journal references, unknown evidence association, overnight/discontinuous contradictions, conflicting times, protected state or source overlap prevent narrowing. Only segment/relationship keys change; no hours or evidence are split/copied/synthesized. Dependency ordering uses the same proven candidate.

A row whose business evidence really spans multiple source/lifecycle intervals remains SEGMENT_AMBIGUITY with candidate IDs/start/end. T-XL0345's supplied pairs before and after 15:00 do not meet the proof; preserve them for review. Actual remaining five production rows were not supplied, so their individual disposition is NOT VERIFIED.

True source overlap remains manual in Repair and blocking in Validator (plus the existing warning). Exact-equal boundaries do not overlap. Materialized reconciliation overlap retains its separate warning; never rename it true source overlap without source proof.

## Validator taxonomy and consistency

Valid UNASSIGNED rows have no missing-assignment/BCH/project, stale-source or generic ACTIVE/no-BCH blocker. Validator independently checks the same timeline proof rather than trusting NULL keys. Existing duration allocation and daily cap checks remain.

Genuine reasons include INVALID_TIMELINE with assignment issues, LIFECYCLE_ASSIGNMENT_CONFLICT with event ID/time, LIFECYCLE_AMBIGUITY, TRUE_ASSIGNMENT_OVERLAP, SEGMENT_AMBIGUITY, PROTECTED_RELATIONSHIP, PROTECTED_CANONICAL_RELATIONSHIP, CANONICAL_CONFLICT/TIME_CONFLICT, UNASSIGNED_IDENTITY_CONFLICT and UNASSIGNED_TIME_CONFLICT. Batch canonical interval/OCR reference checks prevent a NULL row from hiding a still-assigned/foreign canonical identity. Absent legacy JSON OCR IDs retain existing reference semantics; repair does not synthesize missing jobs or delete references.

## Verification checklist A–U

- [x] T-XX0694 equivalent rich row unassigned; identity/content preserved; validator and second-run consistency.
- [x] Empty draft cleanup/audit/idempotency; cross-day/cross-month/long gaps; independent repair order.
- [x] Historical retroactive propagation and A→GAP→B→GAP→C; next-transfer/return/window limits.
- [x] AFTER_RETURN rich/empty, before handover, RETURN inside an already proven gap, conflicting live assignment after return.
- [x] Same-day, exact OUT==IN and one-minute gap; disjoint NULL gap does not block append/materialization.
- [x] True overlap, malformed scoped history, historical zero boundary, candidate diagnostics.
- [x] Business/canonical fully before/after boundary; conflicting hours/spanning evidence stays manual.
- [x] Canonical/pairing/photographic payload, populated target conflict, empty source case, protected shared weekly/monthly case.
- [x] Row/canonical/metadata audit rollback, duplicate identity fail-safe, second-run no-op, bounded query/model benchmarks.
- [x] Provided date candidate cache cannot assign cross-day or one-minute gap photos to future BCH; timestamp authority retained for Bulk Confirm/recheck.
- [x] Authenticated period/row Blade rendering and explicit workbook Không BCH group; full rebuild protection.

Opaque OCR/AI Rescue metadata retains unknown JSON object/list types (including `{}` versus `[]`); only known case-materialization relationship properties are updated. A dedicated regression verifies nested payload preservation. Canonical-only unassigned cases may exist without a reconciliation row, as permitted by the existing independent case lifecycle; no automatic row synthesis was added.

## Local tests and performance

Final source verification: full suite **451 tests / 3060 assertions PASS** (29.09 s), SQLite :memory: / APP_ENV=testing. Reconciliation regression **134 / 1194 PASS**; targeted gap/17.2/performance **63 / 552 PASS**. Pint --test and PHP syntax: **19 changed non-Blade PHP files PASS**; both changed Blade pages render through authenticated HTTP tests. `git diff --check` PASS. No migration added, so separate new-migration checks are not applicable.

Repair keeps machine/date indexes, full source snapshot per batch, bounded CASE/audit writes and zero reconciliation/case/OCR Eloquent hydration. 2400 rows / 1200 cases / 2400 photos retain 147 queries both for assigned relink and new UNASSIGNED normalization. Long-gap cleanup remains 23 queries. Timing is variable and must be reported rather than assumed equivalent: adjacent isolated baseline runs 479.84/442.89 ms versus then-current after runs 555.08/585.14 ms; earlier full-scope after was about 405–407 ms. Detailed candidate diagnostic expansion is now deferred to unresolved rows. Final full run: assigned canonical merge 232.89 ms, UNASSIGNED normalization 218.89 ms (both 147 queries / 0 models); 1200-row long-gap cleanup 71.95 ms / 23 queries / 0 row models. These local SQLite timings vary with host state and do not guarantee production latency. Production timeout/DB lock performance is not verified, no timeout increased.

## Residual production audit — no guesses

- Machine 23 / T-XX0694: supplied Jan→Aug8 / Oct13→open history proves September unassigned; rich unprotected rows should normalize rather than remain pinned to old/new BCH. Protected/conflicting cases retain explicit reason. No exact resulting count promised without current data.
- Machines 79/261: inspect assignment IDs, zero/reversed/missing boundaries, lifecycle IDs and affected time ranges using new diagnostics. Historical unrelated invalidity no longer poisons September. Exact root of the 60 rows NOT VERIFIED.
- Five SEGMENT_AMBIGUITY rows: deterministic containment or canonical/time proof can resolve; spanning/untimed/conflicting evidence stays manual. Exact five row histories NOT VERIFIED.
- 117 AFTER_RETURN: rich valid unassigned rows normalize; genuinely empty drafts clean; real lifecycle/protection/canonical/identity conflicts stay manual. Their exact creation histories NOT VERIFIED.
- T-XL0345 Sep11: source 256 OUT Sep10 15:00 / 420 IN Sep11 15:00 remain non-overlap. Row 85905 has before/after evidence and cannot auto split/merge with empty row 98926; row 85906 evidence preserved. Correct materialized-layer warning remains.
- SGC-T-3C0466 Sep8 and VT-3C0664 Sep3: actual source histories not available; do not assume the same root cause. Keep true source overlap if proven, otherwise diagnose materialized segments. Three remaining warning dispositions NOT independently VERIFIED.

## Production verification plan — separately authorized future work only

1. Review this local diff and the full validation evidence. Verify intended production Git commit and migration history match the nullable schema; production SHA equivalence is currently unknown. Obtain explicit separate authorization before any release/production action.
2. On a production-equivalent MySQL copy first, snapshot the September period selected by dates/status (do not guess its ID); all row IDs/segments/relationship/status/payload hashes, canonical case/scope/member/interval/pair IDs, OCR metadata/extraction/AI refs, images/attachment checksums, GPS/hours/manual/journal and audit counts.
3. Snapshot complete source assignment and lifecycle histories for machine 23 (110/398), 79/261, the five ambiguous rows and the three warning machines. Check exact times, event IDs, protected weekly/monthly sharing and unresolved target scopes. Do not fill gaps or change source data to make validation green.
4. Run one explicitly approved Repair on the copy/selected production period. Capture total/already_correct, assigned relinks, normalized_unassigned, unassigned_by_context, cleanup/cleaned_by_context, each unresolved reason and row diagnostics. Compare all immutable payload/identity snapshots. Verify only proven empty drafts/empty canonical duplicates were removed with audit.
5. Verify machine 23 September rows have all three relationship keys NULL, canonical unresolved scope, preserved photo/OCR/GPS/hours/manual/pair identities, and no stale/missing-BCH/ACTIVE blocker solely from the gap. Verify same-day disjoint assignment rows still append once; read/preview/manual confirm/export retain content.
6. Independently diagnose machines 79/261 and each of the five rows using raw timestamps plus scoped diagnostics. Keep true broken/ambiguous/protected cases manual; do not mark 60 invalid rows resolved solely from the code change.
7. Check source versus materialized overlap for all three warning machines. T-XL0345 must never receive the false true-source message; evidence spans require review. No conflicting business merge/split.
8. After first-run safety is approved, run Repair a second time: zero relink/normalization/cleanup, zero new repair audits or evidence/payload changes (unchanged timeline). Confirm row/case/evidence counts and relationship/canonical FK consistency.
9. Exercise concurrent repair/transfer/append/Daily Photo materialization, locked overlapping periods, historical windows, same-day/exact boundaries and long gaps on MySQL; measure 6k+ repair runtime/query/lock behavior under the unchanged timeout. Record actual results before production PASS.

Expected production improvement is conditional: the 39 gap and 117 return rows are normalization candidates, not a guaranteed 156 repairs. Generic gap review reasons should disappear for deterministic unprotected data; genuine conflicts get specific reasons. No guaranteed zero blockers/warnings, no claim of deployment or production PASS.

## Exact files changed

- app/Exports/ReconciliationBchSheet.php
- app/Exports/ReconciliationBchWorkbookExport.php
- app/Exports/ReconciliationRowsExport.php
- app/Http/Controllers/ReconciliationPeriodController.php
- app/Services/DailyPhotoCaseService.php
- app/Services/Reconciliation/AssignmentRelationshipPropagation.php
- app/Services/Reconciliation/AssignmentTimelineState.php
- app/Services/Reconciliation/CanonicalAssignmentRelinker.php
- app/Services/Reconciliation/DailyPhotoSyncService.php
- app/Services/Reconciliation/DailyTimeAllocator.php
- app/Services/Reconciliation/ReconciliationExportValidator.php
- app/Services/Reconciliation/ReconciliationGenerator.php
- app/Services/Reconciliation/ReconciliationLinkRepairService.php
- docs/PHASE_INDEX.md
- docs/PROJECT_STATE.md
- docs/phases/PHASE-17.3.md
- resources/views/reconciliation/periods/show.blade.php
- resources/views/reconciliation/rows/show.blade.php
- tests/Feature/Reconciliation/ReconciliationExportValidatorTest.php
- tests/Feature/Reconciliation/ReconciliationRepairPerformanceTest.php
- tests/Feature/Reconciliation/ReconciliationRepairStabilizationTest.php
- tests/Feature/Reconciliation/ReconciliationRepairTimelineTest.php
- tests/Feature/Reconciliation/RetroactiveBchTransferTest.php
- tests/Feature/Reconciliation/UnassignedGapRecoveryTest.php

## Stop condition

AUDIT → IMPLEMENT → TEST → DOCUMENT → REPORT → STOP. No commit/push/PR/merge/deploy/production migration/Repair/batch/data changes/restart or next Phase. Next action is human review; any future production verification requires separate explicit authorization.
