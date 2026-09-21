# Project State

- Updated: 2026-09-22
- Current work: Production hotfix — Daily Photo large-volume reconciliation / allocate-times.
- Status: COMPLETED locally — implementation and required checks PASS; review/publication pending.
- Branch: `hotfix/daily-photo-large-volume-reconciliation`.
- Verified production base: `5839be6` (`Merge pull request #46 ... remove-low-confidence`).
- Current HEAD: local checkpoint commit containing this document; resolve with `git rev-parse HEAD`.
- Push / PR / merge / deploy / production migration / production data action: NOT PERFORMED.

## Verified root cause

`POST /reconciliation-periods/{period}/allocate-times` called `DailyPhotoResyncService`, which held one period-wide transaction while loading every matching OCR job and its relations. It then re-materialized every job, queried assignments per job and recomputed cases before `DailyPhotoSyncService` loaded every row/source/case for the period in another transaction. Row creation and `DailyTimeAllocator` also queried inside row loops. At production volume this retained a large Eloquent graph and a database transaction/connection long enough to reach the 30-second PHP limit and `MySQL server has gone away`.

The earlier `bf001e8` hotfix removed the GET-render N+1 and delayed pairing recomputation, but POST still hydrated/reprocessed the full period and the downstream sync/allocator still contained period-wide collections and row-level queries.

## Completed hotfix

- Resync selects only OCR jobs without canonical evidence membership and processes them with stable `chunkById(100)` batches.
- Assignment candidates are bulk-loaded and locked once per materialization batch; already-canonical jobs are not materialized or paired again.
- Canonical pairing remains inside the bounded materialization batch and preserves existing case/evidence/interval uniqueness and idempotency guards.
- Reconciliation row creation uses batched canonical cases, associative existing-row lookups and `insertOrIgnore` under the existing unique identity.
- Downstream synchronization processes row IDs in batches of 100; each batch has its own transaction, row locks, source/case caches and allocator context.
- The allocator can use the preloaded same-machine context, removing per-row overlap and regular-budget queries while preserving the existing HC/OT/rounding rules.
- Protected manual/REVIEWED/CONFIRMED/REJECTED rows retain their saved values and provenance; unchanged reruns do not write them again.
- GET/render remains read-only and does not invoke heavy synchronization.
- Synchronous UX is retained: the 1,001-evidence regression completes far below the hosting limit, so no queue/worker/deployment architecture was added.

## Latest verified checks

PHP executable: `D:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

| Check | Result |
|---|---|
| Large-volume regression | PASS: 1,001 evidence, 500 canonical cases/intervals/rows; 105 SELECTs on initial sync, 105 on idempotent rerun, 0 rerun writes; measured sync 1.805s (3.68s including fixture/test) |
| Batch failure/retry regression | PASS: second batch rollback left the first batch committed; retry completed the remaining 400 rows without duplicates or protected-data overwrite |
| Targeted Daily Photo/reconciliation suite | PASS: 78 tests, 405 assertions |
| Full Laravel `artisan test --compact` | PASS: 272 tests, 1,362 assertions, 30.17s |
| Pint on changed PHP files | PASS: 5 files; one style issue fixed |
| PHP syntax on changed application services and large-volume test | PASS |
| `git diff --check` | PASS after final documentation update |

Tests use SQLite in-memory databases. Production MySQL data distribution and the live browser request remain NOT VERIFIED.

## Index / migration decision

No migration or index was added. The hot query shapes reuse existing indexes and constraints: OCR document/status/review indexes plus `ocr_jobs_machine_date_index`; canonical case machine/date and assignment/date indexes; unique evidence membership plus case/capture ordering; reconciliation period/scope/time lookup and segment uniqueness. The 1,001-evidence query instrumentation completed with 105 bounded SELECTs and did not show an index-driven bottleneck. Adding an unproven production index would widen this hotfix unnecessarily.

## Safety / invariants retained

- Daily Photo machine/date/time, sender fallback, ambiguity, targeted retry and confidence rules from Phase 16.10.5 are unchanged.
- Canonical case/evidence/interval identity, capture-order pairing, odd evidence, ambiguity and correction/requeue behavior are unchanged.
- Allocator rounding, seven-hour regular budget and HC/OT split are unchanged.
- Weekly Journal, OCR worker, Collector, API contracts and frontend are unchanged.
- Batch failure rolls back only the active batch. Rerun is resumable/idempotent; existing unique keys and row locks remain the concurrent double-click protection.

## Blockers / not verified

- No local implementation blocker.
- Production MySQL EXPLAIN against the live data distribution, authenticated production request latency and deployment behavior are NOT VERIFIED.
- No production action is authorized by this checkpoint.

## NEXT ACTION

1. Review the local commit on `hotfix/daily-photo-large-volume-reconciliation` with `git show --stat --oneline HEAD` and inspect the five application/test files plus this continuity documentation.
2. If review passes, explicitly authorize push and PR creation; do not push, merge or deploy automatically.
3. After a separately authorized production deployment, run one scoped allocate-times request and verify HTTP response, duration, Laravel/MySQL logs, row counts and protected rows before considering the hotfix production-verified.
4. Do not start another Phase or change OCR/Weekly Journal/worker rules from this checkpoint.
