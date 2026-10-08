# Phase 17.3 — October root cause and guarded fixes

## Current checkpoint — 2026-10-08 audit contract and production evidence

This section supersedes the stale local/remote status below; earlier implementation/test totals are historical. Phase remains open. Goal: fix the proven audit tool defect and collect evidence for the reported `3` repairs, without changing any repair rules.

- Actual branch `phase17-3-audit-details-fix`; initially clean; HEAD `3d27a76bd8100433ac63b16b910f1913b801128c`; no upstream configured. Cached/local production match. `git ls-remote origin refs/heads/production refs/heads/phase17-3-final-fix` independently returned `3d27a76` and `8f8b6fa`. Local history includes PR64 merge `4baa0e3` and PR65 implementation `8f8b6fa`. GitHub code is verified; hosting code/DB are NOT VERIFIED.
- Proven defect: `collect(..., bool $details, ...)` reassigned `$details=[]` per group and appended row summaries. With rows, the final group array was truthy even with `details=false`, selecting schema2 and exposing the detailed evidence/release label by default. Empty scopes retained the boolean. Minimal correction: use `$rowSummaries` in three places; public signature, schema defaults, dispositions and business rules unchanged.
- Test gap: OctoberResidualFixTest asserted detailed schema2 and invalid release handling, but never asserted default schema1 on a populated scope. New ConsistencyAuditContractTest covers service/CLI empty/nonempty/multiple groups, absence of evidence/release by default, empty detailed evidence, last-group protection independence, forbidden values and SELECT-only queries. Baseline matrix **4 FAIL / 8 PASS /253 assertions**, exactly nonempty default service/CLI single/multiple-group failures; after correction matrix **12 PASS /287**, final test file **13 PASS /307**. SQLite in-memory; no live DB used.
- Requested regression command `php artisan test --compact --filter='OctoberResidualFixTest|CrossPeriodConsistencyTest|DayBasedBchOwnershipTest'`: **30 PASS /494 assertions**,3.16s. Final audit-only command **13 PASS /307**,0.98s. Existing detailed canonical regression also asserts all observed queries are SELECT. Audit path invokes only read methods (`reason`, `needsRelink`, `protectedEvidence`, classifier, Validator, evidence collection), never `plan`/`flush`/repair. Transactions are control statements; no application table writes. No full suite repeated for this narrow audit fix.
- Final checks: scoped Pint `--test` **2 files PASS**; PHP syntax service/test **PASS**; extracted hosting collector PHP syntax and hosting block `bash -n` **PASS**; `git diff --check` **PASS**. Final diff contains only the three variable-use replacements, the direct contract test and four documentation files. Hosting/MySQL execution of the collection block is **NOT VERIFIED**; syntax validation is not a production run.

### Verified execution path; limits of the counter

`UI repairLinks → ReconciliationLinkRepairService::repair → locked period/batch rows → full assignment/lifecycle history → daily owner → source/segment/project/BCH/overlap guards → CanonicalAssignmentRelinker::reason → relationship/protection check → target occupants → duplicate classifier → guarded plan/batch writes → counters/diagnostics → UI`. Validator separately compares references to the stored row and daily ownership; period page shows it, and export refuses blockers (warnings require acknowledgement). Generator runs canonical preflight and guarded append/Repair; Sync runs preflight, occupied-day checks and refuses conflicted duplicate-day allocation; replay materialize uses daily ownership and relationship guards. These are possible writer entry points, not proof of the writer of any existing production row.

Canonical guards can stop before duplicate handling: multiple populated cases/scopes, protected/shared locked evidence, wrong machine/date/case/member, out-of-owner capture/interval, or foreign/missing reference. Target handling permits only proven empty source/target, identical complete payload, or category C independent complementary descriptors. Different hours/intervals remain category D / DUPLICATE_PAYLOAD_CONFLICT; multiple target occupants yield TARGET_DUPLICATE. No survivor is selected by this investigation. `reason=null` against projected owner does not prove stored-row compatibility or safe duplicate merge.

| Repair result key | Verified code meaning | Current production observation |
|---|---|---|
| repaired | Incremented for canonical plan when row links need no changes, or a guarded row-link/segment update; not number of fields or total affected rows | User reports 3; original result/diagnostics absent |
| removed | Proven empty/equal/descriptor-consolidated row removals and stale draft cleanup; separate counter | NOT VERIFIED |
| normalized_unassigned | Rich deterministic gap/return relationship normalization | NOT VERIFIED |
| unresolved | Inspected unresolved rows with reasons/row diagnostics; not duplicate-pair count | NOT VERIFIED |
| diagnostics.already_correct | Row with no link change and no canonical relink needed | NOT VERIFIED |

**Production cause: chưa xác minh nguyên nhân production.** Possible skip classes above are code-supported hypotheses until mapped to actual rows/diagnostics. Historic 129 D/672 OCR/220 canonical/21 identical/108 warnings cannot be substituted for a current audit. No current evidence establishes why exactly three plans/updates occurred. Need original repair result including diagnostics, scope/time, actual hosting SHA/deployment timeline, fresh full/focus audit and retained activity/action records. Never rerun Repair on live DB just to recreate counters. Missing writer logs means UNKNOWN; equal hashes/reference IDs do not prove interval equivalence.

### Checklist, risks, rollback, next action

- [x] Baseline/status/history/upstream checked; GitHub refs independently verified.
- [x] Contract regression FAIL before minimal fix and PASS after; requested regression PASS.
- [x] Default/detailed empty/nonempty and multiple-group behavior covered; SELECT-only/redaction verified.
- [x] Hosting fail-fast collection block prepared in residual runbook; no hosting command executed.
- [ ] Hosting release and production diagnostics supplied and classified by actual evidence.

Only changed service, direct tests and Phase/state/index/runbook documentation. No schema/migration, worker/runtime restart, ownership/repair/Validator/OCR/time/GPS/assignment-history change. Rollback is reverting these local audit/test/docs hunks; there is no data repair to undo. Deployment is separate and currently unauthorized; old deployed code must fail the default-mode check in the collection block rather than silently collect unintended detailed data. Separate command snapshots may differ while production writers run; capture UTC times and use an authorized consistent snapshot for before/after claims.

NEXT ACTION: review local diff; an authorized operator, after the audit fix is separately released, supplies the runbook packet and original Repair result/deployment timeline. Validate checksums/schema/scope, split duplicate/single-row days and stored-row/owner compatibility, then join each unresolved reason to actual reference/time/protection evidence. Stop with production NOT VERIFIED if packet/original result is missing. No commit/push/PR/merge/deploy/live Repair/Sync/Generator/replay/repair-preview is authorized.

## Historical implementation checkpoint (superseded status)

- Updated 2026-10-08. Local code/tests/docs COMPLETE FOR REVIEW; production NOT VERIFIED. This checkpoint supersedes the previous audit-only continuation. No new Phase.
- Verified local branch `phase17-3-final-fix`, HEAD `21f458d` (PR #64 implementation commit). Owner-reported production merge `4baa0e3` is absent in local Git. No fetch/pull or hosting access. JSON schema 1 matches the local command contract, but does not identify the executable/deployed release. Hosting must report HEAD separately.
- Evidence: user-provided `october-consistency.json`, period **9**, full October, SHA256 `33630a14688fc3740f129190ff6460b323bf1605f7a7c700ab4542a77ca1c32f`. Preserve this local evidence unmodified/unversioned. September evidence is used only for the existing 126-day regression.

## Verified production classification and its limits

| Evidence from October JSON | Count | Proven interpretation |
|---|---:|---|
| Rows / machine-days | 6484 / 6355 | 129 extra rows, 129 pairs on 23 machines, Oct1–7 |
| Category D, only `daily_intervals` differs | 21 | Interval JSON business payload differs; no proof of serialization-only or safe merge |
| Category D, interval plus time/allocation fields differ | 108 | Includes confirmed/rounded check-in/out, regular/overtime/lunch allocation; no automatic overwrite/merge |
| A / B / C pairs | 0 / 0 / 0 | Existing classifier identifies no proven safe duplicate consolidation |
| CANONICAL_OCR_CONFLICT | 672 | Per-reference Validator diagnostics, not 672 distinct rows |
| CANONICAL_CONFLICT | 220 | Per-interval Validator diagnostics; investigate separately from duplicates |
| DAY_OWNERSHIP_MISMATCH | 129 | Stored row/source ownership disagrees with day owner |
| DAY_OWNERSHIP_DUPLICATE | 129 | Multiple rows for one machine/day |
| IDENTICAL_ALLOCATED_TIME | 21 | Duplicate allocated range diagnostic; alone does not prove equal evidence |
| MATERIALIZED_OR_SOURCE_OVERLAP | 108 | Warnings; source-versus-materialized cause still requires timeline/value proof |
| Total blockers | 1171 | 672 + 220 + 129 + 129 + 21; diagnostics, not deletion count |

All 6484 row summaries have projected `canonical_reason = null`; all 258 duplicate row summaries are unprotected and have `canonical_needs_relink = false`. This **does not** contradict Validator: `CanonicalAssignmentRelinker::reason` tests whether references are compatible with the projected daily owner, while Validator checks the stored row assignment. The old-BCH sibling can reference an already-correct new-owner case and still fail export. Schema 1 has neither actual reference values nor per-message mapping; it cannot establish that all 892 reference diagnostics originate in the 129 pairs. No conclusion is derived from snapshot hashes.

No differing `daily_ocr_job_ids`, `journal_row_ids`, GPS or descriptor fields are reported by the pair comparator. This establishes equal normalized reference sets / equal reported payload fields, not equal source OCR, valid pairing, HUMAN provenance or disposable hours. Distinct interval values remain significant.

| Representative, verified numeric IDs | Stored identities | Daily owner / finding |
|---|---|---|
| #16 Oct1–7 | Old assignment 50 / BCH15 vs 416 / BCH42; Oct1 rows91968/98342, Oct7 rows91974/98348 | Owner416/BCH42; all seven D: one interval-only, six time/interval |
| #255 Oct1–7 | Old334/BCH25/project5 vs402/BCH3/project6; Oct1 rows96618/98280, Oct7 rows96624/98286 | Owner402/BCH3; all seven D, differing time/intervals |
| #25 (September snapshot maps T-XL0345; recheck current catalogue) Oct1–3 | Old256/BCH17 vs420/BCH28; rows92247/98652,92248/98653,92249/98654 | Owner420/BCH28; Oct1 interval-only; Oct2–3 time/intervals |
| T-XL0034 | Current machine ID/reference values absent | Collect ID from catalogue; investigate all October, including single-row days |

The report has no assignment timestamps, interval values, source OCR/protection/shared-lock details, creation action/release provenance. Two materialized rows under different assignments are proven; their actual creation command and whether values were historical projection versus conflicting business input are NOT VERIFIED. September success cannot change October's separately materialized snapshots.

## New code defects reproduced and fixed

1. **Same-case replay skips OCR relationship metadata.** `DailyPhotoCaseService::materialize(job,false)` returned when membership/capture/canonical were already correct, before refreshing stale `case_materialization`. Relinker also returned when source and target case IDs were equal, and `needsRelink` ignored metadata. A regression with an already-correct October case and stale predecessor metadata failed (assignment1 persisted instead of2). Shared guarded metadata-only planning now repairs existing relationship fields, preserves opaque OCR JSON, photos/member/interval IDs and pairing, and emits an old/new relationship audit. Repair, preflight/Sync/Generator and replay reuse this path. Second run has no new write/audit. HUMAN/MANUAL/reviewed/shared locked cases remain untouched.
2. **Machine-day occupancy depends on malformed legacy segment overlap.** The old guard allowed a full-day owner row to be inserted beside another assignment's zero-length row because timestamp ranges did not overlap. Repeated Sync reproduced two rows rather than one. Occupancy now considers any row in the caller's locked machine/day group, regardless of segment. Generator and Sync retain the unresolved legacy row for review and cannot insert a sibling. No row is deleted or time normalized by this guard.
3. **Canonical proof omitted OCR source-date mismatch.** Case/member dates could look correct while a job's extracted date belonged to another day. Shared canonical proof now blocks that relationship correction with CANONICAL_OCR_CONFLICT. No source date/time or pairing is edited.

These tests prove local logic defects. They do **not** prove those defects caused all production pairs/reference errors, or that this code reduces the existing 1171 diagnostics to zero. Existing category-D payload/protection guards and Validator remain strict. No parser/worker/API/schema/migration or raw assignment-history change. Validator uses actual job/case relationships, not OCR relationship metadata, so the metadata replay defect alone does not explain672 Validator messages.

## Implementation files

- `app/Services/Reconciliation/ReconciliationIdentityGuard.php`: day occupancy shared by Generator/Sync.
- `app/Services/Reconciliation/CanonicalAssignmentRelinker.php`: metadata-only repair, same-case protection, source-date proof, in-memory idempotency and relationship audit.
- `app/Services/DailyPhotoCaseService.php`: guarded replay of stale metadata without rematerialization/pairing.
- `app/Services/Reconciliation/ReconciliationConsistencyAuditService.php`, `ReconciliationEvidenceAudit.php`, `app/Console/Commands/ReconciliationConsistencyAudit.php`: opt-in `--details`, schema2 SELECT-only time/interval/reference/HUMAN/shared-period evidence, operator-labelled release. Default schema1 output remains compatible.
- `tests/Feature/Reconciliation/OctoberResidualFixTest.php`: seven new regressions covering metadata replay/Repair, protected sources/shared locks, wrong date, differing intervals, independent single-row reference conflict, zero-length occupancy, future-month Generator replay and prior-month preservation.

Detailed disposition is deliberately conservative: SAFE means a preview candidate/no demonstrated impediment, not authorization; all duplicate pairs and protected/reference/ownership conflicts remain HUMAN_REVIEW; missing referenced job/case/interval is UNSAFE. Unknown interval fields are listed but values withheld; consult them securely before considering consolidation. The packet exports no opaque OCR, descriptive text, coordinates, image paths or images. Reference mismatch totals are diagnostic comparisons, not a replacement for Validator, especially normalized unassigned/lifecycle checks.

## Verification

- Baseline new regressions: **2 failed**, confirming stale metadata and duplicate insertion before fixes.
- Targeted `OctoberResidualFixTest|CrossPeriodConsistencyTest|DayBasedBchOwnershipTest`: **30 PASS / 505 assertions**. Includes September five-boundary outcomes and all126 normalized NULL days, return/gap/manual protection, cross-month repair, Resync and future-month replay.
- Final full `php artisan test --compact`: **494 PASS /3778 assertions**,115.19s (APP_ENV=testing, SQLite in-memory). Scoped Pint `--test`: **7 files PASS**; PHP syntax **7 files PASS**; final diff check PASS. All included performance regressions PASS.
- No production audit/write performed; exact release and production outcome NOT VERIFIED.

## Data repair, acceptance and rollback

The **129 category-D pairs remain HUMAN_REVIEW** until actual intervals/time/source/protection prove a safe case-specific disposition. No new destructive merger or winner-selection rule. Collect schema2 full-period/focus reports per runbook, separate reference errors on duplicate versus single-row days, and obtain creation/audit provenance. Rehearse existing transactional `reconciliation:repair-preview 9` exclusively on an isolated restored database: default SELECT-only audit runs safely on hosting, write-and-rollback preview is refused in production. Backup is mandatory before any separately approved actual repair; preserve original rows/cases/jobs/members/intervals/history and record before/after counts/reference sets/hours/GPS.

Acceptance: unchanged genuine unassigned126, August/September snapshots/protected records; one row per newly generated machine/day; repeated Generate/Repair/Resync/replay yields no extra row/case/photo/job/pairing and no extra audit for no-op; every retained historical conflict explicitly reviewed, every reference blocker mapped to row/reference/cause. Zero blockers cannot be achieved by deleting/overwriting conflicting data or relaxing Validator. Review and evidence are required to complete production normalization.

Rollback: no migration. Revert this patch through a reviewed release if necessary; code rollback alone does not undo a subsequently approved data repair. Relationship-only metadata audit includes old/new relationship; restore guarded relationships or the verified database backup if data rollback is required, keeping opaque OCR unchanged and stopping writers during restore. Never run repair-preview on live data by changing APP_ENV. No commit/push/PR/merge/deploy or production mutation authorized; stop after local code/test/docs review.

---

# Phase 17.3 — Cross-period reconciliation consistency continuation

- Updated: 2026-10-08. Verified new baseline `45c211e`, branch `phase17-3-final-fix`, tracking same origin branch. Initially clean tracked tree; unrelated `tatus --short` is preserved. PR #63 deployment and September success are owner-reported, not independently accessed.
- Scope: extend the same day-based owner to legacy materialized canonical and reconciliation data and new periods. No hardcoded month/machine/row fix; no migration, worker/parser/provider or raw timestamp change. No Git writes or production actions. Local verification in progress; production NOT VERIFIED.
- This continuation supersedes the earlier blanket rejection of all distinct automatic populated payloads only for proven missing independent descriptors. Distinct time/evidence bundles and all HUMAN/manual/reviewed/locked conflicts remain review-only. Earlier day-based acceptance and the 126 normalized NULL baseline remain in force.

## Cross-period audit: proven code causes and evidence limits

| Proven cause at baseline `45c211e` | Resulting behavior | Local correction |
|---|---|---|
| Repair payload hash excluded links and created/updated timestamps, but included `segment_start/end`, `change_type/note`, evidence status/summary/signature/sync timestamp | Identical business hours/reference payload with different derived metadata is rejected as `DUPLICATE_PAYLOAD_CONFLICT` | Shared conservative classifier separates technical fields; business payload/reference equality still required, with before snapshots retained in merge audit |
| Canonical relinker finds source only by row assignment; an already-correct owner row cannot find a populated case under the predecessor | Repair can report already correct while Validator reports canonical OCR/interval conflicts | Index populated canonical by machine/date; adopt only the sole non-conflicting legacy case; inspect canonical even when row links need no update |
| Resync candidates exclude jobs with evidence membership | Existing stale canonical is never rematerialized by replay | Relationship-only canonical preflight before Generator/Sync; membership/pairing/OCR content are retained, no re-OCR or pairing recomputation for existing cases |
| Generator originally takes only assignments physically intersecting the period | Its resolver sees a different historical snapshot from Repair/Validator | Generator uses full machine history for ownership, retaining intersecting assignments only for enumeration |
| Default Generator replay deletes automatic existing rows before rebuilding | Historical materialized hours/row IDs can be replaced | Existing automatic periods replay via guarded append/Repair; manual/review/NULL guards still require explicit append |
| Sync can process the correct-owner sibling while another populated stale sibling remains | Automatic allocation can obscure the unresolved conflict | Skip entire unresolved duplicate day using the existing batch row context; no hours or references cleared |

These defects are reproduced by local regression tests. They do **not** prove that all 794 production rows have technical-only differences. The reported 7149/6355/794, 3084 blockers and 108 warnings have no October JSON/snapshot available in this session. Requested its local path; exact classification remains NOT VERIFIED. The September JSON is not substituted as October evidence.

| Requested production sample | Evidence needed / current result |
|---|---|
| Machine #30, Oct1–31 | Scoped audit of every sibling field/link/reference; a 31-day technical-only regression reproduces and fixes that class, but the actual machine payload is NOT VERIFIED |
| T-XL0034, Oct1–3 | Row job/interval references versus actual canonical assignment and daily owner; already-correct row / stale canonical class reproduced locally; actual IDs/content NOT VERIFIED |
| T-XL0345, Oct2–3, ME HLX/ME 10 | Materialized row overlap versus actual source timestamps; do not infer a valid transfer or equivalent payload from BCH names; NOT VERIFIED |
| T-XL0296, Cơ Hữu/TĐXD 02.1 | Same timeline/row/canonical/payload proof; actual source or materialized overlap NOT VERIFIED |

794 is a reported count of Repair conflict rows. 3084 is a reported count of Validator **unique diagnostic messages**, which can include multiple OCR references, intervals, ownership mismatch and sibling-pair diagnostics for one row/day. Neither number establishes deletable row count. A month is not an isolation boundary for canonical identity: canonical cases pre-exist period generation, while row links/payload are period snapshots. September repair cannot normalize October's legacy snapshots; new periods must inspect existing canonical too. Whether each October row was created before or after PR #63 requires its created/updated/audit evidence; current Generator and ensureRows occupancy guards already prevent inserting a second occupied machine/day. No claim that current Sync created all duplicates is supported.

## Classification and safe consolidation

- **A:** automatic empty row, no payload or scoped canonical evidence; delete only with a valid sole daily owner and successful canonical/protection checks. Preserve rich source identity when target is empty. NULL gaps are not bulk-cleaned.
- **B:** equal business payload/reference sets; link/segment/derived cache differences do not count as distinct evidence. Preserve the survivor's full business values and source/target before snapshots. No hour addition or photo movement.
- **C:** both automatic, shared compatible time/evidence bundles, differences restricted to missing `driver_id`, `work_location`, `work_content`, `explanation`, `notes`. Fill missing descriptors only, without concatenating conflicting text or choosing between two values. Source and target before snapshots remain in the same atomic audit.
- **D:** conflicting hours/endpoints/GPS/reference sets, unknown field differences, distinct populated canonical cases, HUMAN/manual/reviewed/locked/shared protected evidence, invalid/overlapping timeline or missing daily owner. Preserve records and require review. Complementary time/photo bundles with insufficient proof also stay D; no inference or general photo union is implemented.

`ReconciliationDuplicateClassifier` is pure and shared by Repair and SELECT-only audit. `CanonicalAssignmentRelinker` loads referenced jobs in batches even outside the selected day window so wrong-day OCR is a real conflict. It also guards reviewer/confirmation IDs and HUMAN evidence before deduplication. `DayBasedCanonicalRepairService` repairs existing days before row creation, respecting period/date/BCH scope and shared protection. `DailyPhotoCaseService`, existing Resync membership selection, pairing, `AssignmentRelationshipPropagation` and historical BCH recovery reuse the strengthened relinker/day ownership; no timestamp/history rewrite is introduced. Validator retains real blockers and supports an optional read-only machine/date scope without changing normal export behavior. UI/export continue to consume the same stored row/canonical links.

## Tools, data impact and rollout

`reconciliation:consistency-audit PERIOD [--machine=ID] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]` is SELECT-only, no locks or mutation; outputs field names, IDs, hashes and scoped blocker categories, never notes/raw OCR/photos/credentials. Pair A/B/C/D counts are **pairs**, not 794 Repair rows. Map actual conflict row IDs from the production Repair report to those pairs before counting dispositions. Protected/canonical checks can downgrade a payload candidate to D. An A/B/C audit label is not authorization to change production.

`reconciliation:repair-preview PERIOD` is a separate restored-copy dry-run: actual guarded Repair inside a savepoint with **all writes rolled back**, returning period-limited before/after row counts and diagnostics. It refuses APP_ENV=production. It is not the SELECT-only audit, and must never run against a production-connected local environment. No automatic apply or blind rollback command was added. Actual cleanup remains the reviewed existing period Repair workflow; full consistent backup/restore is the data rollback procedure, including shared canonical/jobs and audits. Code rollback alone cannot undo row consolidation. See runbook for backup, review, later deployment and verification; none executed here.

No new schema/data migration. MySQL concurrency/unique constraint/release verification and exact October distribution remain pending. Remaining HUMAN review includes every D category, source anomalies, protected references and incompatible independent canonical cases. The earlier 126 normalized genuine gaps must retain row IDs, NULL links and payload through every replay.

## Cross-period validation checkpoint

- Final reviewed full suite: `php artisan test --compact` **487 tests / 3703 assertions PASS**, 70.25 s, APP_ENV=testing, SQLite `:memory:`. This includes the September five-case/126 NULL replay and all new cross-period regressions.
- Final shared/large-volume targeted run: `--filter='CrossPeriodConsistencyTest|DayBasedBchOwnershipTest|ReconciliationRepairPerformanceTest|DailyPhotoLargeVolumeReconciliationTest'` **30 tests / 528 assertions PASS**, 20.27 s. Initial focused baseline extensions also passed 71/962; final evidence is the reviewed full run.
- New 12-test `CrossPeriodConsistencyTest` covers 31-day October materialized B duplicates with hour preservation, correct-owner/stale canonical, new-period Sync/Generator/Resync, next month replay, C descriptor fill with before audit, D hours/manual/HUMAN/shared locked records, SELECT-only redacted scoped audit, invalid command scope, dry-run full rollback, wrong-day OCR conflict, default Generator row/hour identity preservation, empty duplicates and identical photo references.
- Performance: canonical 2400-row/1200-case relink remains **147 queries / zero row models**; ordinary 2400-row Repair **24 queries / zero row models** (one batched referenced-job check added); long-gap 1200-row Repair **23 queries / zero row models**; historical residual audit 1200 rows **18 queries**, same as one row. Daily Photo 500-row budget is **150 SELECTs**, increased only for the batched canonical preflight, with duplicate detection reusing existing context. No per-row history queries.
- Pint `--test` and PHP syntax: **13 changed/new PHP files PASS**. Diff check final result recorded in checkpoint. Full schema rebuilt by test migrations; **no new migration**.
- An old gap-preservation fixture used fake job IDs 1/2 that accidentally belonged to a different actual canonical date. Fake payload-only IDs moved to 9001/9002, and a separate actual wrong-day OCR regression proves such mismatches remain blocked. Protection assertions compare unordered reason maps because dependency processing order is not a business contract.
- Status: **LOCAL IMPLEMENTATION VERIFIED / STOP FOR OWNER REVIEW**. Exact October 794/3084 classification, requested production sample outcomes, MySQL data replay/concurrency and deployment verification remain **NOT VERIFIED** pending October evidence. No commits/remote/production actions. Earlier results below describe the previous PR #63 implementation only.

---

## Previous PR #63 day-based implementation record

- Updated: 2026-10-08. Existing Phase 17.3 only; dependencies 17.1 / 17.2 and canonical Daily Photo 16.10.1–16.10.4.
- Baseline VERIFIED locally: clean `phase17-3-final-fix`, HEAD `e997531`, tracking `origin/phase17-3-final-fix`. PR #62 deployment is owner-reported; this session has not accessed production.
- Status: LOCAL CODE VERIFIED; mandatory local checks PASS; REPORT → STOP for owner review. Production/MySQL NOT VERIFIED. No commit/push/PR/merge/deploy/data repair performed. STOP after final report.
- The owner's whole-day rule explicitly supersedes all earlier timestamp-splitting/narrowing acceptance statements below for daily BCH ownership. Physical source history remains authoritative evidence and is never rewritten by this fix.

## Scope and acceptance

A machine has one BCH per work date. A valid new assignment starting at any time on D owns every journal entry, OCR photo and reconciliation row on D. Old ownership ends on D-1. A return with no new assignment retains old ownership through D. Completely uncovered dates retain their existing NULL relationships. Multiple chronological, non-overlapping transfers on the same date use the final assignment; genuine source overlap, malformed/reversed timestamps, missing final BCH/project or contradictory lifecycle remain review blockers. Missing final BCH never falls back to the predecessor.

No hardcoded machine or row identity, hourly split, source timestamp rewrite, provider/worker/parser change, dependency update or schema migration. Automatic row/canonical correction protects manual/HUMAN OCR, reviewed/confirmed/locked data and shared protected periods. Different populated payloads or canonical selections remain blocked; no automatic hour addition or evidence merge.

## Audit evidence and precise root causes

Owner supplied `phase17-3-residual-audit.json` (local evidence only, not versioned): SHA256 `32A9B245D133C02476A3FC23B2CBEE582E9FD5E6173F705857C21E5A24473D9A`. Snapshot has 6046 period rows, 131 inspected focus/NULL rows, 126 normalized NULL (9 genuine gap, 117 AFTER_RETURN), five whole-day DRAFT focus rows and three empty automatic siblings. No focus source overlap, manual edit, review or confirmation is present in the snapshot.

| Row / date | Physical source and evidence | Previous blocker | Daily outcome |
|---|---|---|---|
| 85904 / Sep10 / T-XL0345 | #256 ME HLX OUT Sep10 15:00; next #420 starts Sep11 15:00; no canonical case proving an hourly narrowing | Whole-day row extends beyond OUT, `NO_SINGLE_CANONICAL_CASE` narrowing reason | #256 / ME HLX #17, whole Sep10 |
| 85905 / Sep11 / T-XL0345 | Stale #256; #420 ME 10 IN 15:00; case #1950 contains 11:18–13:24 and 17:45–18:01 pairs; five OCR IDs; raw last photo 22:02; empty sibling #98926 | Canonical pairs cross physical IN, `CANONICAL_TIME_CONFLICT`; materialized overlap | #420 / ME 10 #28, all Sep11 evidence preserved |
| 86442 / Sep8 / SGC-T-3C0466 | Stale #274 HTTQ ended **Aug27 15:25**, new #422 starts Sep8 15:25; case #1633 PAIRING_AMBIGUOUS with 14 OCR photos 06:22–21:39 and no pairs; empty #99036 | `CANONICAL_INTERVALS_MISSING` narrowing and `CANONICAL_TIME_CONFLICT`; materialized overlap | #422 / TĐXD 10.4 #35, all Sep8 photos; pairing ambiguity itself remains visible |
| 87577 / Sep3 / VT-3C0664 | Stale #148 is absent from selected machine history; #423 IN Sep3 15:26; no canonical case; empty #99166 | Whole-day containment cannot prove hourly narrowing; materialized overlap | #423 / TĐXD 10.4 #35; no invented predecessor OUT/history |
| 89197 / Sep3 / VT-3C0696 | #296 OUT / RETURN Sep3 16:45, no new BCH on Sep3 and no narrowing case | Whole-day row extends beyond OUT; `NO_SINGLE_CANONICAL_CASE` | #296 / TĐXD 10.4 #35, whole return day |

Source gaps (including Aug27–Sep8) are real. They are not filled by this rule; only a day with a valid assignment starting/ending on that date changes ownership interpretation. The three overlap warnings refer to reconciliation siblings, not simultaneous source assignments.

## Execution path and implementation

1. `MachineAssignment` / existing timeline and BCH resolution records remain unchanged. New `DayBasedAssignmentOwnership` consumes batch snapshots, checks actual timestamp overlap and lifecycle, and returns an immutable virtual daily interval with separate `physical_time_in/out`; it caches machine/date decisions without database calls. Malformed timestamps block the machine when their scope cannot safely be inferred. Reversed intervals retain existing scoped diagnostics; empty legacy zero-duration records retain their existing non-coverage semantics.
2. `DailyPhotoCaseService` uses daily ownership for captures on both sides of a transfer. A stale case is relinked through `CanonicalAssignmentRelinker` when safe, preserving case/member/pair IDs, raw capture times and opaque OCR JSON. Canonical database identity takes precedence over stale OCR scope metadata, so replay cannot create a second case or delete pairing. Replay with unchanged membership and no requested recompute is a no-op. The relinker rejects HUMAN/manual/reviewed OCR and shared protected rows, keeps pairing states and updates only relationship metadata. Distinct populated canonical targets remain conflicts.
3. `DailyPhotoResyncService` and backlog BCH scoping use the final daily owner. Resync reuses a batch ownership/lifecycle snapshot. `DailyPhotoSyncService` generates only one whole-day row from a matching canonical owner and protects stale identity before replacing any payload. Explicit overnight selection and `DailyTimeAllocator` use ownership of the destination date rather than physical OUT time; crossing another BCH's day remains invalid.
4. `ReconciliationGenerator` selects one assignment per machine/day and aggregates the same day's approved journal entries before/after transfer without splitting them. Append occupancy still preserves existing NULL/manual rows and cannot create an extra row against an occupied day. Invalid ownership aborts generation transactionally; missing BCH is never guessed.
5. `ReconciliationLinkRepairService` uses the same cached day decisions. Automatic relationships/segments are corrected to the selected owner and 00:00–23:59:59 without changing business payload. Existing atomic batch writes/audits and safe duplicate policy remain: discard only proven empty/equivalent automatic siblings, preserve rich source identity against empty targets, retain distinct populated conflicts and all protected rows. Canonical consistency is checked even for already-correct row relationships. `AssignmentRelationshipPropagation` and historical BCH resolution delegate to the same daily policy/Repair guards.
6. `ReconciliationExportValidator` checks daily owner, source validity, duplicate machine/day identity, and assigned/NULL canonical references. It retains true blockers; time transfer alone no longer blocks a valid whole-day row. Residual audit now exposes `day_ownership` beside the unchanged physical diagnostics and marks manual protection. UI and `ReconciliationBchWorkbookExport`/`ReconciliationBchSheet` consume stored row BCH and canonical links, so corrected relationships flow to both; no UI/export rewrite was needed. Existing NULL UI/export regressions remain.

## Tests and evidence limits

- New `DayBasedBchOwnershipTest`: transfer at 15:00, actual approved journal entries before/after it, old ownership on D-1, photos/pairs before and after transfer, preserved row/business/history/attachment/member/pair identities, empty duplicate collapse and repeat Repair with no audit, return-day ownership and next-day NULL, manual/reviewed/locked/HUMAN OCR protection, multiple same-day transfers, overlap/same-IN/malformed timestamps/missing final BCH.
- Sanitized `tests/Fixtures/reconciliation/phase17-3-day-boundaries.json` contains only snapshot relationship IDs, dates, physical assignment ranges and lifecycle events. No photos, attachment paths/checksums, chassis, notes, raw OCR, credentials or opaque metadata. Resolver replay verifies the five exact expected assignments/BCH IDs and all 126 days (9/117). A DB regression seeds the 126 normalized dates with those histories and verifies two Repair runs preserve every row/history and add no audit. Focus Validator regressions exercise each projected physical boundary; they do not claim a full 6046-row production database replay. Source #148's absent history is not fabricated.
- Earlier tests were updated only where the owner superseded hourly split/return/gap acceptance or automatic manual correction. Shared automatic fixtures no longer carry manual timestamps; explicit manual and reviewed protection tests remain. Distinct payload, rollback, no-N+1, idempotency, UI and workbook checks are retained.
- Large Daily Photo read budget rises from 130 to 140 SELECTs for 500 rows because assignment/lifecycle snapshots add two batched reads per 100-row batch; no per-row allocator history query. Repair's batched zero-model-hydration checks remain.
- Final targeted reconciliation/canonical/workflow: **214 tests / 2040 assertions PASS / 35.16 s**; final canonical identity/metadata subset **36 / 453 PASS / 4.02 s**; new daily ownership suite **11 / 351 PASS / 2.65 s**, including scoped Resync. Final full Laravel: **475 tests / 3624 assertions PASS / 75.44 s**, APP_ENV=testing, SQLite `:memory:`. **Pint --test and PHP syntax PASS on all 25 changed/new PHP files**; `git diff --check` PASS. No migration changes; full tests rebuild the existing schema in isolated SQLite.
- Final full-run benchmarks: content reassignment, assigned/unassigned canonical relink and long-gap cleanup retain bounded reads and zero row/case/OCR model hydration; canonical **147 queries**, ordinary Repair **23**, long-gap **23**. Residual audit **1200 rows / 18 queries / 524.21 ms**, same query count as one row. Ordinary Repair **408.29 ms**, long-gap **283.05 ms**. Local SQLite timings are not a production SLA; timeout/concurrency unchanged.
- Final diff reviewed: 13 service PHP files (one new shared resolver), 12 test PHP files (one new regression suite), one sanitized JSON fixture and four Phase/checkpoint/runbook documents. User-provided full audit JSON remains unmodified/untracked local evidence. No secret, runtime artifact, migration or unrelated module change is part of the deliverable. No production/MySQL execution or historical payload-preservation claim is made from snapshot counts alone.

## Risks, deployment and rollback

See [residual audit runbook](../runbooks/PHASE-17.3-RESIDUAL-AUDIT.md) for the exact later operator verification sequence. No schema migration is required. This is a cross-service daily ownership change, so review protected/legacy populated duplicates and run on a restored MySQL copy before separately authorizing production Repair. Existing PAIRING_AMBIGUOUS OCR remains a pairing review issue, not permission to recalculate evidence during relink. MySQL concurrency and live full-period results remain NOT VERIFIED. Code rollback does not reverse an already-applied data Repair; before snapshots and audit are required for separately reviewed restoration.

NEXT ACTION: owner reviews the uncommitted local diff and the runbook's restored-MySQL verification plan. Local work is complete; REPORT → STOP. No commit, push, PR, merge, deploy, production access/writes, migration or worker restart authorized.

## Historical diagnostic milestone (superseded daily ownership acceptance)

The following prior documentation is preserved for history. Its old timestamp rule and STOP/checkpoint are not current acceptance or session status.

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
