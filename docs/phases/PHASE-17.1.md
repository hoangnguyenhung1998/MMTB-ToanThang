# Phase 17.1 — Reconciliation Repair Links Production Stabilization

- Date: 2026-10-07
- Status: COMPLETED locally — implementation, required regression/performance checks and final diff review PASS; awaiting user review. Production NOT VERIFIED.
- Parent: Phase 17 — Production Stabilization & Bugfix.
- Branch: `phase16-11-1-ai-rescue-foundation`; HEAD `9d46e71`, initial clean tree; cached upstream 0/0.
- Dependencies: Phase 11 / 11.1 repair; segmented reconciliation; 16.9; 16.10.1–16.10.4 canonical identity/downstream; 16.11.1–16.11.3 protection. Applicable skill: laravel-mmtb.

## Goal and production symptom

User reports repair completes after deployed Phase 11.1 but returns 0 repaired / 0 removed / 889 unresolved, about 1240 blocking errors and 381 warnings in September 2026, including T-XL0034. Production merge `0001dca` and these counts are user-provided, NOT independently VERIFIED. Preserve Phase 11.1 performance while repairing deterministic stale draft relationships without modifying content.

## Audit findings / confirmed code root causes

Route POST repair-links → auth → controller Gate appendMachines → repair service (no separate FormRequest/payload) → locked period/raw rows → batch joined assignment/project/direct-or-historical BCH → indexed candidates/donors/targets → batch update/delete/audit in transaction → redirect. Export validation is separate, triggered by period display/export. No ReconciliationRow/ActivityLog observers or mutators; explicit ActivityLog insert is required.

1. `hasData()` excludes all evidence/hours/content rows from assignment replacement; manual timestamp is an additional blanket guard. Proven in source and original `stale_manual_evidence_and_hours_are_not_reassigned_or_deleted` expectation. This explains the class of unresolved rows, not the exact production distribution without data access.
2. Stale-source candidates were counted at date level without segment containment; unique same-day segments were missed. An exact same-date ID outside the row segment also prevented replacement.
3. Occupancy detection used a single ID per machine/date/assignment and only checked empty-draft replacement; minimal writes must respect the actual unique identity even for catalog/segment repair.
4. Validator and repair duplicated interval rules; reversed/zero segments and invalid source intervals were not consistently rejected.

## Domain decisions / safety

Manual update FormRequest exposes time/content, no relationship overrides; manual timestamp alone does not freeze draft relationship metadata. Reviewed/confirmed/rejected/status/timestamp locks are retained from existing repair and canonical/BCH recovery contracts. Canonical case membership is assignment-scoped (`DailyPhotoSyncService::caseForRow`); moving a row away from an existing canonical membership or canonical interval reference is separately diagnostic, never a canonical rewrite.

Date bounds follow existing inclusive day enumeration; positive time intervals use strict overlap, so touching endpoints do not overlap. Data-bearing/manual rows can move only if one target contains their whole existing positive segment; no segment changes. Empty legacy drafts retain exact-source narrowing; no latest/current assignment inference. Invalid/overlap/missing-BCH/duplicate/locked cases remain untouched. No schema/index/worker/provider change; no production operation.

## Implementation

- Shared `AssignmentInterval` handles raw snapshots/Eloquent casts, inclusive date membership, positive intervals, full-segment containment and strict time overlap. Repair and export validator reuse it.
- Draft hours/content/manual/OCR/journal/AI/Daily Photo references no longer blanket-block replacement. A unique effective candidate must contain the whole row segment; another partially overlapping assignment still prevents repair. No source/latest-ID tie-break.
- Exact source remains authoritative when valid. A same-date source outside the row's segment can be replaced by a unique containing target, including an empty draft. Only empty legacy drafts without a containing target use the existing exact-source narrowing rule.
- All same-machine siblings remain together. Full source identity is period/machine/date/assignment, as enforced by `reconciliation_row_segment_unique`; schema permits only one row per assignment/day, even if segments differ. No invented merge semantics.
- Occupancy tracks all IDs, including nullable legacy orphans. A dependency graph orders rows that must release occupied targets. Batched update waves release identities before dependents claim them; cycles stay unresolved. No second operator run to discover a releasable-target dependency. Empty/identical-payload stale cleanup remains batch-safe; manual/locked/canonical data is never deleted by this phase.
- Raw snapshots, batch joined historical resolution, machine/date indexes, full-payload duplicate hash and transaction/batched writes/audits from Phase 11.1 remain. Added one raw canonical-scope lookup per machine batch, no row model hydration.
- Controller retains original repaired/removed/unresolved keys and adds diagnostic flash data. Blade shows totals, reason counts and links to individual unresolved rows. No unrelated UI redesign.
- Validator retains existing export blocking/warnings and compares actual BCH work ranges. It additionally batch-loads source timelines and warns on real overlapping assignments even without a second reconciliation row. Invalid timeline/segments fail closed; no stale errors are suppressed.

## Diagnostic taxonomy

Each unresolved row has one primary reason; reason counts sum to unresolved. Absent reason keys mean zero. Result includes total inspected, already correct, assignment identities repaired (`repairable_stale_links`), repaired/removed/unresolved totals, reason counts, and every unresolved row's ID/machine/date/reason. Every inspected row is correct, repaired, removed or unresolved. No no-op/unresolved audit entry is generated.

| Reason | Meaning / next review |
|---|---|
| PROTECTED_RELATIONSHIP | Non-DRAFT or review/confirmation timestamp locks the row relationship; valid protected rows count as already correct |
| CANONICAL_RELATIONSHIP | Existing assignment-scoped canonical membership or canonical interval reference prevents automatic identity move; review canonical provenance separately |
| NO_EFFECTIVE_ASSIGNMENT | No assignment is effective on this machine/date; no invented current assignment |
| TRUE_ASSIGNMENT_OVERLAP | Multiple full-segment targets or another source interval genuinely overlaps the proposed segment |
| SEGMENT_AMBIGUITY | No unique assignment contains the full segment; may be a handover/return gap, same-day range crossing or an adjusted boundary |
| NO_BCH_RESOLUTION | Exact/unique source has neither valid direct BCH nor historical resolution |
| NO_PROJECT_RESOLUTION | Source project catalog cannot be resolved |
| TARGET_DUPLICATE | Another row occupies the schema identity; distinct payload is not merged, including dependency cycles |
| INVALID_TIMELINE | Non-positive/reversed source timeline or effective candidate timeline |
| INVALID_SEGMENT | Missing data-bearing segment, reversed/zero segment, or an empty narrowing result |

There is no manual relationship-override field exposed by the existing edit request, so no fictional override reason was added. Historical BCH resolution is unique per assignment by a DB unique constraint, not a list of effective-dated resolutions: multiple resolutions are rejected by schema and tested. Direct BCH takes precedence over the explicitly confirmed historical fallback, as before. Resolution is not inferred from the machine's current BCH.

## Test expectation correction

Old `stale_manual_evidence_and_hours_are_not_reassigned_or_deleted` expected all five stale rows unresolved: manual timestamp, OCR IDs, minutes, content and reviewed status. Source inspection confirms form manual edits change content/time only; preserving content does not require freezing a DRAFT assignment ID. New expectation is four deterministic draft links repaired and the reviewed row unchanged/unresolved. Full raw payload snapshots are compared after removing only assignment/project/BCH/updated_at fields. No hours, content, evidence or manual timestamp is discarded to make the tests pass. Other original safety/performance assertions remain.

## Data safety / concurrency

- Allowed writes: changed machine_assignment_id/project_id/command_center_id plus updated_at; segment narrowing only on empty non-manual drafts. No unrelated status, review timestamp, hours, duration, content, driver, image, source ID, signature or pairing mutation.
- Canonical case/evidence/interval identity is not rewritten. A regression creates real case memberships, OCR jobs, paired interval and attachments, edits the source timeline, then compares all six evidence/image tables and the row before/after repair.
- Reviewed/confirmed/rejected and timestamp locks remain. Period CONFIRMED/EXPORTED guard is checked under lock. No approved/HUMAN/canonical semantics weakened.
- Period lock, raw row locks, assignment/catalog/historical resolution locks and added canonical case read locks retain one atomic transaction. Dependency update waves and audit writes share that transaction. Injected audit failures roll back both new content-bearing reassignment and existing update/deletion cases.
- No OCR run, re-pairing, canonical rebuild/materialization, sync or provider request occurs. Existing duplicate cleanup preserves the entire meaningful payload on a valid sibling; different evidence/hours/content is unresolved.
- Idempotent rerun makes zero meaningful changes and adds no repair audit or timestamp change. SQLite verifies atomicity/unique identity/idempotency, not real MySQL lock contention. InnoDB concurrent insert/lock behavior requires production-equivalent verification; no distributed lock or background job introduced.

## Test matrix coverage

| Required cases | Evidence |
|---|---|
| Single BCH whole month; A→B; A→B→C | Stabilization whole-month raw snapshots / content-bearing three-interval test; original timeline tests retained |
| Same-day; multiple date candidates but unique segment; changed source | Orphan + same-date stale source, empty same-day reassignment, touching boundaries, three-step occupied-target chain |
| Handover / return / boundary | Original empty boundary tests; data-bearing handover, before/after gap and immutable return-day payload |
| True overlap / duplicate assignments | Original overlap tests, whole-period diagnostic, unseen source-overlap validator warning, no arbitrary ID choice |
| Regular/overtime/OCR/journal/AI/content/manual/Daily Photo | Nine provider payload cases; all raw fields compared, source ID arrays and paired references unchanged |
| Reviewed/confirmed/rejected/timestamp/canonical | Original protected tests plus stale lock fixture, explicit canonical reference, real membership/pairing/image table snapshots |
| No effective assignment / missing BCH / historical resolution | Whole-period reason test; original BCH recovery; assignment-scoped historical fallback and DB multiple-resolution rejection |
| Duplicate sibling / invalid timeline / correct row | Distinct payload cannot merge; multiple orphans; dependency cycle; reversed timeline; whole-month no-op |
| Idempotency / audit rollback / no mutation / validator | Rerun per payload, three-interval fixture, audit rollback, segment/boundary and post-repair validator checks |
| ≥1000 rows / query budgets / hydration | Retained 2400-row cleanup and 1200-row catalog benchmarks; new 1200-row content/manual identity reassignment benchmark |

## Performance and verification

No full-period rescan per stale row or assignment SQL N+1. Complexity is rows + machine/date candidate checks + dependency vertices/edges + batches of actual changes. Dependency edges use indexed occupancy; ready-queue processing is linear in vertices/edges. Writes are batched per dependency wave (cycles are not written). One machine's siblings are kept together, as before; machine batching is not a hard row-memory cap for pathological history.

Phase 11.1 documented baseline (not remeasured in this session): 29,621.63 ms / 2406 queries / 2400 row models before optimization; 144.38 ms / 21 queries / 0 models after, with other runs 144–347 ms. Current isolated targeted run: retained 2400-row cleanup 184.07 ms / 22 queries / 0 models; catalog 1200 rows / 120 machines 111.39 ms / 44 queries (two assignment reads); new 1200 content/manual reassignments 102.26 ms / 41 queries / 0 models (one assignment read). Added query per machine batch is canonical-scope protection. Query budgets remain under 100; timing is diagnostic, not a hosting SLA. Concurrent verification runs were slower; final-suite measurements recorded below.

PHP: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. All tests use SQLite `:memory:` / APP_ENV=testing from phpunit.xml, never the development/production DB.

- Repair-focused final tests: PASS — 56 tests / 595 assertions.
- Dependency regression: PASS — 231 tests / 1594 assertions; final full suite includes the later test additions too.
- Final full suite `artisan test --compact`: PASS — 391 tests / 2525 assertions, 96.21 seconds. Final-suite benchmark: content reassignment 359.66 ms / 41 queries / 0 models; catalog 148.25 ms / 44 queries; retained cleanup 274.75 ms / 22 queries / 0 models. Query/hydration budgets unchanged, local timing remains within Phase 11.1 recorded run variation for the retained cleanup fixture.
- Scoped Pint: PASS — AssignmentInterval, repair service, stabilization/timeline/performance tests. Existing controller/validator/append-test formatting was preserved to avoid unrelated reformatting.
- PHP syntax: PASS — eight affected PHP files.
- Blade diagnostic rendered through authenticated feature GET: PASS.
- Whitespace `git diff --check` and final 12-file source/test/documentation review: PASS. Initial tree was clean; only the requested repair/validator/diagnostic/test/continuity scope changed. No secret/runtime data included.

## Files / database / runtime

- Added: `app/Services/Reconciliation/AssignmentInterval.php`; `tests/Feature/Reconciliation/ReconciliationRepairStabilizationTest.php`; this phase document.
- Modified: repair service; export validator; period controller; period show Blade; append/repair, timeline and performance tests; PROJECT_STATE.md; PHASE_INDEX.md.
- No migration/index/data-repair script, dependency, frontend build, worker/Collector restart or provider/config/secret change needed.
- No commit, push, PR, merge, deployment, production migration, production repair/data change or runtime restart performed.

## Production verification checklist (separate authorization required)

1. Back up the period/rows, assignments/historical BCH resolutions, activity logs and canonical/OCR/image data before an approved deployment/repair. Deploy only the exact reviewed code; no new migration or worker restart required by this change.
2. Snapshot period #8 totals, every protected/manual/evidence payload and canonical membership/pairing/image identity. Record validator blocking/warnings before repair; inspect T-XL0034 plus other machines across A→B→C and same-day boundaries.
3. Run one controlled Repair Links call; record HTTP status, elapsed, inspected/correct/repaired/removed/unresolved and complete reason breakdown. Verify hosting stays under the unchanged 30-second limit. Counts must reconcile across all inspected rows.
4. Compare machine/date/segment effective targets and historical BCHs; verify all data-bearing payloads exactly unchanged apart from link metadata/updated_at. Reviewed/confirmed/canonical unresolved rows must retain their old state. Confirm identical-donor deletions preserve all content and evidence elsewhere.
5. Revalidate export: repaired rows no longer produce stale-source errors. True source overlaps and unrelated hours/allocation blockers remain visible. Review each unresolved row by its reason, particularly canonical identity, protected lock, gaps, invalid timelines and conflicting targets. Do not confirm/export just because repair completed.
6. Run a second controlled call to verify zero repair/deletion/audit/timestamp changes; compare OCR/canonical/evidence/attachment counts and exact pairing/provenance snapshots. Check concurrent transfer/repair on a production-equivalent MySQL copy before asserting InnoDB concurrency verified.
7. Record actual production commit/runtime/counts in continuity docs only when evidenced. A code rollback does not reverse prior repaired links/deleted duplicates; any data recovery must be separately reviewed against backup/audit.

## Known limitations / remaining risk

Exact causes and distribution of the reported 889 production rows are NOT VERIFIED without production data; no machine-specific fix or promise that all 889 are auto-repairable. Canonical membership with stale assignment needs separately scoped provenance review, not a canonical rebuild inside Repair Links. Protected relationship locks, full segments spanning multiple assignments, real overlaps, invalid timelines and distinct duplicate targets intentionally remain unresolved with reasons. Cyclic identity swaps remain explicit review (no temporary unlink or data merge). Zero-length source intervals are invalid, not a selectable historical assignment. Real MySQL concurrency, live host timing and deployment state are NOT VERIFIED.

## Checklist / next action

- [x] Restore AGENTS/state/index/current/direct dependency docs and skill; verify Git.
- [x] Audit route/policy/request/service/resolution/models/audit/writes/validator.
- [x] Publish short audit findings before implementation; prove source behavior and correct stale expectation.
- [x] Implement content-safe effective-segment repair, diagnostics, occupied-target ordering and shared validator semantics.
- [x] Targeted/dependency tests, performance/hydration budgets, idempotency and rollback PASS.
- [x] Record final full-suite PASS and review final diff/docs.
- [ ] User review and separately authorized publication/deployment/production smoke.

NEXT ACTION: user review of the 12-file local diff and this report. Local scope is code-complete; STOP. Any publication/deployment/production smoke requires separate user instruction. Do not commit/push/PR/merge/deploy/repair production/restart runtime now.
