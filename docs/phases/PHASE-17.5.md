# Phase17.5 — Durable bulk AI Rescue requests

Status: LOCAL ACCEPTANCE PASS; cumulative review pending, no release authorized; LIVE NOT VERIFIED. Date2026-10-23.
Branch/base: codex/daily-backlog-background-recovery /13adfd0. Depends16.11.1–16.11.3; related17.4 (kept uncommitted and regression protected).

## Contract and design

Trigger: select2062 unique missing-date Manual images and confirm AI Rescue; reported HTTP timeout has no stage log. Trace count->preview->execute->journal-worker. Measure all HTTP stages using synthetic MySQL fixtures before attribution; provider never called during test.

Count uses the dashboard's SQL reason taxonomy; readonly preview snapshots exact job IDs in an actor-bound encrypted token. It must never enqueue or call AI. Large previews disclose that full storage/domain eligibility is deferred, with a maximum candidate count rather than claiming all are eligible. Detailed small previews remain bounded. Confirmation persists a dedicated AI enqueue run, not a Daily Photo recovery run. Selected IDs never expand; late protected/resolved/missing-source/previous-attempt changes are checked under lock by the original service before enqueue.

Minute scheduler processes bounded per-job transactions with durable lease/attempt, cursor, item->attempt links and counters atomic. Reuses existing AI attempt request/claim/complete/fail, journal-worker fairness/provider/config/quotas without parallel providers. Run progress distinguishes selection, enqueue, active OCR and terminal results, aggregate only linked items/attempts; no backlog analysis in polling. Actor owns preview/execute/progress/retry. Crash/retry/double clicks cannot create a second attempt for a processed item, even after its first attempt becomes terminal.

## Acceptance / validation pending

- [x] Stage timings2062 before/after; clarify hosting stage remains UNKNOWN.
- [x] Readonly bounded count/preview, fast HTTP confirmation, immutable selection.
- [x] Full unique eligible enqueue with protected/ineligible/overlapping reasons; prior attempts preserved.
- [x] Native MySQL independent-process dispatch/enqueue/crash/restart and provider mock worker.
- [x] Eligibility changes, ownership, retry counters and actual OCR progress semantics.
- [x] AI Rescue/worker fairness regression and Phase17.4 two fixes; full regression as needed.
- [x] Final review, runtime/migration/runbook/checkpoint and cumulative clean patch+manifest.

No commit/push/PR/merge/deploy or hosting DB writes. Do not combine domain recovery with paid AI request logic. No PHP timeout workaround or after-response work.

## Measured evidence and results

Before fix MySQL synthetic2062 (2060 eligible): count1762.294ms, preview1796.179ms, synchronous execute38865.214ms. No provider called. Local execute alone exceeds30s; hosting timeout stage remains UNKNOWN without logs. Source Collection.php timeout from the other incident is not evidence for this endpoint.

After fix separate same-size fixture: SQL count23.787ms, readonly ID preview669.983ms, persist confirmation54.963ms, zero attempts enqueued in HTTP. Large preview2062 is explicitly an upper bound pending eligibility, not2062 promised successes. Native HTTP kernel two concurrent processes15020/9624, MySQL connections68/67, both302same run138.664/141.767ms. Repeat after terminal work108.875ms.

Native NEW `p175_native_20261023_b`:2062 selected, late eligible arrival excluded, two protected+one missing source+one changed protection skipped;2058 unique attempts/items2062, exact counters. Real exit78 after attempt+item INSERT within TX rolls back both/cursor/counters; lease prevents immediate reclaim. Fixture clock+3minutes permits restart; exit77aftercommit keeps1attempt/item and progress. Concurrent worker processes16132/11968, connections83/84, actual lock waits1013.30/1031.37ms. All eligible selections enqueue, status stays OCR_RUNNING with2058pending, not OCR completed. Native drain max atomic unit4674.276ms under concurrent fixture/test load; injected contention unit2114.094ms, full drain150.842s. These are local stress values, not hosting predictions. Default command remains50units/minute,15s checks BETWEEN units.

Native real AI claim/complete loop with LOCAL fake provider (UNKNOWN) makes2058mock calls, each completion callback replayed; all2058terminal HUMAN_REQUIRED and zero queued/processing/failed, statusCOMPLETED_WITH_REVIEW, no invented success. Replaying confirmed HTTP and running worker again keeps2058attempts/items2062, no new paid work. Provider billing/network-level exactly-once is not claimed; existing lease/retry transport is unchanged. Python worker mocks/fairness42tests PASS. Actual CLI `ocr:process-ai-rescue-runs --steps=50` processes50 checked units/47attempts in2164.421ms on timing fixture.20real HTTP JSON polls: mean34.516ms/max215.204ms with no OCR job hydration/backlog report/analyse.

Final tests: full SQLite568tests/5625assertions PASS including PR68/Phase17.4. Guarded MySQL NEW `p174_unit_ai_20261023_b`:40tests/452assertions PASS (AI Run/UI/domain and Phase17.4). Test classes cover2062readonly preview/light HTTP, overlapping reasons, late eligible arrivals, protected/source/eligibility changes, owner/expiry/progress/retry, actual attempt-insert rollback/counters, bounded abandoned claims and OCR status success/review/failure with mocks. Updated old UI tests now drain the asynchronous request explicitly; repeated confirmation returns persisted run/progress instead of a new per-call synchronous summary.

## Findings corrected during verification

- True concurrent dispatch exposed `firstOrCreate` fallback reading within the losing REPEATABLE READ snapshot: HTTP500 with UniqueConstraintViolation/SQLSTATE23000/1062, reproduced twice. Changed to create+audit transaction, catch unique outside rollback, then fresh existing-run lookup. Final native dispatch302/302 PASS, single request audit/run.
- Concurrent PHPUnit fake-storage cleanup deleted another test process's synthetic sources and skewed enqueue counts; bootstrap now isolates storage by PID as well as DB. Initial failed MySQL/SQLite/targeted runs are retained as failed evidence, not counted as acceptance. Full run while controller/view was being edited also exposed transient undefined variable; final source was frozen before clean final runs.
- Earlier source-start connection refusal occurred before schema creation while MySQL was starting; retried after ready. Baseline and failed/new schemas retained, never reset.

## Database, runtime and release

New17.5 migration2026_10_23_000001 adds AI request and run-item tables, leaves attempt table/provider contract unchanged. Together with17.4:2new migrations/4additive tables. Rollback must preserve pending requests/linked attempts; no destructive migration rollback. Existing minute hosting cron is user-provided; registration can be checked locally, actual cron/runtime/deployed provider/model/quota remain NOT VERIFIED. No journal-worker/Collector/OCR source/config change or restart required by17.5; existing compatible journal-worker must be running to consume attempts. No permanent Laravel queue daemon, no timeout increase/after-response workaround. Runbook: ../runbooks/AI-RESCUE-BACKGROUND-BULK.md.

Static self-review completed for snapshot/actor/TTL, transactions/failure/retry, read-path limits, scope/attempt dedupe and partial OCR status. No independent reviewer claimed. Cumulative whitelist patch+SHA manifest includes17.4 and17.5; secrets/backup/raw logs/runtime/unrelated untracked excluded. LOCAL PASS does not verify hosting fixed.

NEXT ACTION: review final cumulative patch/manifest, then obtain separate commit/push/PR/merge/release/deploy authorization. Do not release17.4 separately from this supplement. After approved release, run additive migrations and cache clear using hosting PHP83, verify actual minute cron plus small authorized AI flow through an already compatible journal-worker. No hosting/database writes/provider calls or remote mutations authorized now.

Final handoff: own guarded MySQL33317 shut down cleanly; schemas retained. Fetch confirms base13adfd0/origin-production0/0. Cumulative artifact `C:/Users/Administrator/.codex/visualizations/2026/10/09/01a11ec8-755d-75d3-8864-9fae7a4bfef8/phase17.4-17.5-review-20261023/Phase17.4-17.5.patch` with41-file source/hash manifest. Forward/base and reverse/current checks PASS; syntax24PHP files/diff/secret scan PASS; Pint11AI files PASS.
