# Phase 11.1 — Reconciliation repair-links timeout and BCH reassignment hotfix

- Date: 2026-10-06
- Status: COMPLETED locally — implementation, targeted/dependency/full Laravel checks and final review PASS; production NOT VERIFIED.
- Branch: `phase16-11-1-ai-rescue-foundation`; starting HEAD `a3937b0`, clean and 0/0 versus cached upstream.
- Origin: Phase 11; repair-links introduced by `6d4c2a4`.
- Later direct changes: `4daf630`, `e7bd82e`, `afae749`, `80a14fc`, `dd843065` (catalog links, missing BCH, stale cleanup, evidence deduplication, historical BCH resolution).
- Dependencies/related: Phase 15 segmented export (verified in code/history; no separate Phase 15 document found); 16.9 protected Daily Photo rows; 16.10.1–16.10.4 canonical identity/pairing/downstream integration; 16.11.1–16.11.3 AI Rescue protection/read paths.
- Number: independent Phase 11 hotfix, first 11.1 entry in the current index; newer OCR phases retain their numbers and behavior.
- Instructions read: AGENTS.md; PROJECT_STATE.md; PHASE_INDEX.md; PHASE-MANAGEMENT runbook; Phase 11, 16.9, 16.10.1–16.10.4 and 16.11.1–16.11.3 relevant sections. Skill: `.agents/skills/laravel-mmtb/SKILL.md`. No worker/Collector/deployment implementation is in scope.

## Goal / scope

Remove repair-links computational and write bottlenecks, preserve effective historical BCH assignment and reconciliation payload, keep ambiguity explicit and repair idempotent. Only the repair service, two tests and continuity documents change. No timeout increase, queue architecture, migration, worker/OCR/canonical changes, production operation or Git publication.

## Audit map

Route `POST /reconciliation-periods/{period}/repair-links` → auth → `ReconciliationPeriodController::repairLinks` → `ReconciliationPeriodPolicy::appendMachines` → `ReconciliationLinkRepairService::repair` → assignment/catalog/resolution reads → locked reconciliation rows → changes/deletion → explicit ActivityLog. Controller redirects without invoking export validation, rebuild, pairing, OCR, regenerate or unrelated sync.

| Component | Before | Decision / after |
|---|---|---|
| Route, controller, policy | Authenticated POST, period authorization, redirect/count contract | REUSE AS-IS |
| `ReconciliationLinkRepairService::repair` | Entire period hydrated, referenced assignments eager-loaded, stale evidence scans all rows | REUSE WITH FIX: machine batches, raw locked snapshots, date/candidate/payload maps |
| Assignment/BCH resolver in repair | Uses exact referenced assignment and historical resolution; no current/latest BCH lookup, but no unique historical replacement for orphan/stale empty drafts | MISSING / NEED EXTENSION: unique machine/date interval for empty draft replacement, explicit overlap handling |
| `MachineService`, handover/generator | Transfer closes old `time_out` and creates new `time_in`; return closes source; generator derives day segments | DO NOT TOUCH: reuse `time_in`/`time_out` authority; `handover_date` does not replace these intervals |
| ReconciliationRow model | Date/JSON/timestamp casts; no accessors/appends, row observer or mutator | REUSE AS-IS; bypass repeated hydration/casts in repair only |
| Assignment model | Date casts and handover-date accessor; accessor is not called by repair | DO NOT TOUCH |
| Catalog and BCH resolution | Source project/direct BCH or explicit historical resolution | REUSE AS-IS via one joined lookup per batch |
| ActivityLog and row writes | Two statements per meaningful update/delete; explicit audit; no row/audit observers registered | REUSE WITH FIX: CASE update, bulk delete and audit insert in same transaction |
| `ReconciliationExportValidator` / workbook export | Separate authoritative source/date/segment checks and export | REUSE AS-IS; existing regression + repaired-timeline export validation PASS |
| Canonical case/evidence/pairing, Daily Photo, AI Rescue | Shared assignment identity/protection contracts | DO NOT TOUCH; regression checks only |
| Tests | Small repair/source/historical-BCH tests | MISSING / NEED EXTENSION: timeline, protection, failure, >1,000-row read/write budgets |

## Root cause and evidence

**CONFIRMED ROOT CAUSE:** `app/Services/Reconciliation/ReconciliationLinkRepairService.php::repair` executed `$rows->contains(...)` once for every stale evidence row. The candidate loop read Eloquent IDs, cast work dates/assignment dates and JSON evidence lists repeatedly across the whole period: O(stale rows × period rows). Successful row changes/deletions and ActivityLog creation each issued a separate statement.

Assignments were already eager-loaded: no assignment/catalog SQL lookup N+1, no lazy-loaded relationship loop found. The production HasAttributes location is consistent with repeated attribute work, but does not alone identify the cause.

**SECONDARY / POSSIBLE BOTTLENECK:** full-period Eloquent hydration and repeated Carbon/JSON casting amplified the scan; their individual timing contribution was not separately profiled. Production database latency, query plan, host resource contention and exact data size were not inspected. No evidence justifies treating a missing index as the primary cause.

The test fixture uses isolated migrated SQLite `:memory:` from phpunit.xml, never the development/production database. Baseline was measured before the service was edited. It reproduced near-30-second work and deliberately failed the new query-budget test. No wall-clock assertion is used.

| Fixture / metric | BEFORE | AFTER final full-suite run |
|---|---:|---:|
| 40 machines × 30 days × old/current rows | 2,400 rows; 1,200 stale | Same seed/code path |
| Repair elapsed | 29,621.63 ms | 144.38 ms |
| Query count | 2,406 | 21 |
| ReconciliationRow models retrieved | 2,400 | 0 |
| Removed / audited | 1,200 / 1,200 | 1,200 / 1,200 |

Other AFTER runs ranged 144.38–347.41 ms; local timing is diagnostic, not a production SLA. The 1,200-row catalog-update fixture spans 120 machines (two machine batches): 235.03 ms, 42 queries, 1,200 updates and 1,200 matching audit entries. Assignment reads = two, independent of row count. Second run makes zero changes/audits.

## Implementation / algorithm

1. Keep period lock and status recheck in one atomic transaction. Enumerate period machine IDs, then handle batches of 100 machines; all same-machine siblings remain together so evidence donors are not missed across chunks.
2. Read raw locked rows. Normalize `work_date` to YYYY-MM-DD once; SQLite legacy fixtures can store midnight datetime whereas MySQL DATE stores a date.
3. Lock/load referenced historical assignments plus assignments overlapping actual row dates, with source catalogs/historical BCH resolution in one joined query per machine batch. Existing transfer locks cannot update those assignments halfway through repair.
4. Build machine/date effective candidates and source-identity occupancy maps. Construct one payload hash per valid evidence donor; duplicates are O(1) lookups instead of scanning period models.
5. Retain valid exact source identity. Resolve missing/stale source only for an empty, unprotected draft with exactly one valid effective candidate and no target row already occupying that assignment/date identity.
6. Reject ambiguous overlaps without first/latest/ID tie-breaking. Same-day disjoint segments remain valid using exact source identity. Narrow stale source boundaries only for empty drafts; never overwrite existing hours/manual/evidence or expand segments.
7. Remove expired drafts only when empty with no effective candidate, or when a valid sibling preserves the complete meaningful payload. Deduplication compares all payload fields except identity/catalog/database timestamps; OCR/journal ID lists are normalized. Different durations, notes, pairing metadata, evidence signatures or review data prevent deletion.
8. Only changed link/segment fields and `updated_at` are written. CASE updates: 50 rows per statement; deletes: 250 IDs; audit inserts: 100 rows. Values are parameter-bound and identifiers come from internal change keys, quoted by the connection grammar. No upsert creates replacement rows.
9. Preserve explicit audit events and per-row old/new properties. No ReconciliationRow or ActivityLog observers/mutators are bypassed in the audited repository. Return contract remains `repaired`, `removed`, `unresolved`.

Work now scales with rows plus machine/date assignment candidates and batches of actual changes. Full-period inspection remains necessary to identify valid sibling evidence; only raw snapshots for a machine batch are held at once, not all period Eloquent models.

## BCH business rule / data protection

- Single BCH: unchanged valid rows produce no writes/audits.
- A → B: days 01–14 retain A; days 15–30 resolve to B for empty stale drafts. A → B → C retains all three intervals.
- Return: historical dates stay with source; empty future drafts are safely removed; return-day empty segment respects `time_out`.
- Handover: no invented assignment before start; empty orphan from the effective date resolves uniquely and obeys `time_in`.
- Same-day transfer: exact source IDs and disjoint effective segments retain both BCHs; no whole-month latest BCH substitution.
- Overlap: remains unresolved/manual; even a source ID does not mask another assignment overlapping the effective row segment.
- Manual/HUMAN: same-source catalog-link restoration is retained for compatibility with existing repair tests; manual hours, content, evidence and other fields are untouched. Manual rows never change assignment identity, get deleted or have segments truncated.
- Protected: non-DRAFT rows (including REVIEWED/CONFIRMED/REJECTED) and review/confirmation timestamps prevent changes. CONFIRMED/EXPORTED periods are rejected under the period lock. LOCKED/APPROVED are not row enum states in this schema; no invented states are added, and the non-DRAFT guard would protect future states.
- OCR/image/pairing/canonical/AI results: no corresponding tables/services are mutated or invoked. Evidence-bearing source identity never changes automatically; duplicates are removed only when the complete meaningful payload survives on a valid sibling.
- Different evidence, hours or metadata remains unresolved even if three evidence-ID fields happen to match. Repair is not a reconciliation rebuild.

## Concurrency / failure

Period lock serializes double-click/concurrent repair; row locks and assignment read locks preserve the inspected snapshot. A second completed run is a no-op. Batch statements are inside the same outer transaction, not independent commits. A test forces audit insertion to fail after row update and deletion; both changes and all audits roll back. SQLite does not exercise real InnoDB concurrent lock contention; actual MySQL concurrency remains NOT VERIFIED. No distributed lock or background job was introduced.

## Tests

PHP runtime: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` (PHP was absent from PATH).

- `artisan test --compact --filter='ReconciliationRepairTimelineTest|ReconciliationAppendAndRepairTest|ReconciliationRepairPerformanceTest'`: PASS — 28 tests / 227 assertions.
- `artisan test --compact --filter='Reconciliation|Machine|CanonicalDailyPhoto|DailyPhotoWorkflow|AutomaticDailyPhotoIntegration|DailyPhotoAiRescue|DailyTimeAllocator'`: PASS — 208 tests / 1,278 assertions.
- `artisan test --compact`: PASS — 363 tests / 2,157 assertions, 62.71 seconds.
- Scoped Pint: PASS — three changed PHP files formatted.
- `php -l` for service and both new tests: PASS.
- `git diff --check`: PASS; final six-file scope reviewed, no secret/runtime files.
- Worker tests not required: no worker, OCR, Collector contract or shared worker code changed.

## Files / database / deployment

Modified: `app/Services/Reconciliation/ReconciliationLinkRepairService.php`, `docs/PROJECT_STATE.md`, `docs/PHASE_INDEX.md`.
Added: `tests/Feature/Reconciliation/ReconciliationRepairTimelineTest.php`, `tests/Feature/Reconciliation/ReconciliationRepairPerformanceTest.php`, `docs/phases/PHASE-11.1.md`.

No migration or schema change. Existing `reconciliation_row_segment_unique` starts with period/machine/date/assignment, `reconciliation_row_time_lookup` covers machine/date/segments, assignment FK indexes and `machine_assignment_effective_at_index(machine_id,time_in,time_out)` already exist; historical-resolution assignment ID is unique. Source indexes were verified from migrations; live MySQL plans/index presence were not checked. No speculative new index added, no `migrate --force` required. No frontend build or worker/Collector restart required for this code-only hosting change.

## Limitations / production smoke / next action

Production timing, data cleanup and the observed period #8 are NOT VERIFIED. The fixture is representative of the proven scan/write bottleneck, not a copy of the 862-warning production dataset. A period with many stale manual/evidence rows can retain unresolved warnings intentionally. An orphan with multiple effective same-day assignments stays manual even if a human might infer a segment. One machine's siblings are kept together, so 100-machine batching is not a hard row-memory ceiling for pathological per-machine history.

After separate user approval for Git/deployment/production repair: back up SQL, deploy the exact reviewed commit by the existing runbook, clear Laravel caches as needed (no new migration), snapshot period #8 counts and protected rows, inspect one transferred machine's A/B dates/segments and evidence. Run one repair, record HTTP status/elapsed/repaired/removed/unresolved; ensure below the unchanged 30-second limit on hosting. Verify earlier A history, later B/C, manual/protected payload and export validation; run repair again to verify zero meaningful changes. Compare OCR/canonical/evidence counts before/after and review unresolved rows explicitly. Do not confirm/export a period merely because repair returned success.

Rollback is code-only after separately approved deployment. Already audited link repairs/deletions are not automatically reversed by reverting code; use the backup/audit and separately reviewed data repair if necessary.

## Checklist / result

- [x] Context, source/history/dependency and skill audit.
- [x] Confirmed root cause; measured BEFORE/AFTER.
- [x] Minimal repair-only implementation; protected/timeline/idempotency/batch/failure tests.
- [x] Targeted and dependency regression PASS.
- [x] Full suite PASS, final diff/checkpoint review.
- [ ] User review and separately authorized publication/deployment/smoke.

NEXT ACTION: user review of the six-file local diff and this audit/benchmark. After separate authorization only, follow the normal Git/deploy flow and the controlled period #8 smoke plan above. Local implementation is complete; report and STOP. Commit/push/PR/merge/deploy/runtime restart/production repair are explicitly prohibited by the current request.
