# Phase 17.4 — Durable Daily Photo backlog recovery

Status: LOCAL REVIEW FIXES PASS; release NOT AUTHORIZED; LIVE NOT VERIFIED. Updated: 2026-10-23.
Branch: `codex/daily-backlog-background-recovery`; verified production base/HEAD `13adfd0edfbb1bde64d31023525919fcf8804e6e` (PR68 merge435cb64 / PR69 present). Fetch origin at start:0/0 divergence. No commit/push/PR/merge/deploy. Unrelated `october-consistency.json` / `tatus --short` preserved.
Dependencies:16.10.5–16.10.8 recovery eligibility; canonical materialization/pairing/sync. Related:17.3 / PR68 regression.
Runbook: [background recovery and legacy diagnostics](../runbooks/DAILY-PHOTO-BACKGROUND-RECOVERY.md).

## Contract and scope

- Trigger: authenticated sender-settings “Xử lý ảnh đang chờ”.
- Actual: synchronous POST runs recovery + pairing + whole-period sync; hosting 30s HTTP500 interrupted in Collection.php.
- Expected: persist scoped request, return quickly; scheduler continues independently of browser.
- Invariants: sender/receipt dates/reason/BCH/project, original auth/protection/eligibility, manual/approved OCR/hour/source/mapping; OCR retry is distinct from provider success.
- Acceptance:91 synthetic pending(89eligible/2manual)+protected, durable bounded downstream, double click, true concurrent MySQL processes, crash/retry, stale scope, lightweight actor-private progress and PR68 regressions.

Local implementation/testing/patch/handoff only. Actual hosting diagnosis/timing/deployment, runtime restarts, live DB repair and provider calls excluded. User-supplied UI counts are not current live facts or code constants. Old05:58 missing reconciliation_repair_runs incident remains separate.

## Findings and measured root cause

Verified code path: DailyPhotoController.recover -> BacklogService.recover (analysis/chunk transactions/materialize) -> recomputeMany(all affected cases) -> sync(WHOLE overlapping period). POST waited for all of this. Old recovery commits chunks BEFORE pairing/sync and has no durable downstream record; later EXCEPTION-only selection loses already COMPLETED jobs.

MySQL synthetic fixture:91 pending+1protected+600 unrelated reconciliation rows in the same period. Timing wrappers call the REAL services, no provider. Timings overlap only materialization internally; separate final pairing/sync calls are timed directly.

| Stage | Legacy synchronous ms |
|---|---:|
| Analysis |91.106|
| Materialize |782.276|
| Pairing |184.990|
| Whole-period sync |3229.489|
| Mutation/query/other |1252.274|
| Total |5540.136|

Sync is the measured slowest local stage (58.3% total). Collection.php is only the fatal interruption location. Actual hosting slowest stage/DB lock waits remain UNKNOWN; local fixture does not reproduce a30s hosting limit and is not a hosting speed prediction.

Final background fixture on the SAME scenario shape: POST70.634ms, max atomic unit140.578ms; scoped sync74.849ms, total background4989.244ms. Per-job reanalysis/transactions cost more than batch analysis, deliberately bounded; benefit is fast HTTP and scoped downstream/durability, not a promise all91 jobs finish instantly. Default cron budget10units/minute,15s check BETWEEN units. Native contention deliberately adds1s and is separate from timing benchmark.

## Changes and technical decisions

- New migration adds `daily_photo_recovery_runs` and unique `daily_photo_recovery_work`; no existing schema/data rewrite/dependency.
- Controller POST validates/persists actor/sender/filters/form UUID and redirects to dedicated progress. Notification sync middleware removed ONLY from POST/progress/retry. Original auth middleware retained; actor-only progress/retry prevents cross-user access.
- Active sender hash + UUID dedupe; conflicting actor/filter/mode gets409. Snapshot job/period high-water IDs; new inputs need another request. Progress JSON reads one persisted run, not report/analyse. Settings shows last10 actor-owned runs.
- Scheduler minute command `ocr:process-daily-recovery-runs --steps=10`, no queue daemon or dispatchAfterResponse/sync queue/time-limit workaround. Job/case/machine-day-period units; >1000 memberships/rows/sources or >100 obligated periods fails diagnostically, never truncates to success.
- Shared original recover logic accepts optional durable downstream callback inside recovery transaction. Default CLI behavior unchanged; detach can defer its hidden pairing call. Worker locks job before fresh eligibility/protection/scope analysis.
- Durable claim token/two-minute lease/attempt before business transaction. Data/cursor/counters/outbox atomic; exceptions savepoint-rollback business and persist safe diagnostics. Three exceptions/abandoned attempts stop FAILED; explicit resume preserves prior work. No run completion until all downstream entries done.
- Exact eligible period IDs captured/unioned in day outbox WITH recovery mutation. Later CONFIRMED/EXPORTED/missing period causes visible failure and pending obligation; it cannot disappear through a new status query. Existing sync protects reviewed rows and PR68 rich ownership/hour payload.
- Historical partial COMPLETED jobs: SELECT-only single-job proof using explicit backlog_recovered_at marker, membership/pairing count/timestamp and actual/expected row signature. CLI `--downstream --job` preview; explicit execute/actor schedules existing-field materialization/pairing/sync only. No status reset/re-OCR/approved overwrite. `--limit` retains original preview-sample semantics.
- Progress page explicitly distinguishes queued OCR retry from OCR success. COMPLETED means request processing is finished; existing manual/ambiguous business exceptions remain visible in their original workflows.

## Files

Controller/routes/settings/progress view; `DailyPhotoRecoveryRun` model; `DailyPhotoRecoveryRunService`; `ProcessDailyPhotoRecoveryRuns`; original backlog/case/CLI optional interfaces; additive migration; minute schedule. Acceptance class `DailyPhotoRecoveryRunTest`, guarded MySQL harness/bootstrap + two-process PowerShell runner. State/Index/runbook/skill lesson; .gitignore excludes fixture outputs.

## Test evidence

- Targeted legacy backlog SQLite:29tests/236assertions PASS.
- Final recovery acceptance SQLite and MySQL:12tests/119assertions PASS (includes actual HTML render and JSON/private progress).
- Full regression:559tests/5491assertions PASS; all Daily Photo/reconciliation/PR68 classes included.
- Native FINAL schema `p174_backlog_review_20261009_f`: two real HTTP-kernel dispatch process IDs20156/11056, MySQL connections119/118, HTTP302 same UUID212.886/242.872ms; one persisted run.
- Separate worker process IDs6972/6344, MySQL connections133/132, actual run lock waits1013.34/1041.37ms. Final89recovered/2manual/1protected,89memberships/1case/1row, no pending obligations, no duplicate counters. Full protected-job and immutable OCR/source hashes unchanged. Same-time captures intentionally retain original canonical ambiguity; no beautification of hours/evidence.
- Real exit78 after evidence INSERT inside transaction: rollback all evidence/case/row/outbox/counters; durable attempt+lease remains. Worker cannot steal unexpired lease. Fixture-clock3min expiry then recovery resumes. Real exit77 after recovery commit:1member/2pending obligations retained; independent command completes pairing/sync.
- Repeat with NEW request:9 business/source/audit table hashes unchanged,0recovered,4existing audits unchanged. Synthetic runtime outputs retained only in ignored storage, not patch.
- MySQL acceptance includes failure after ACTUAL sync writes (whole unit rollback), pairing failure, no double counters, explicit retry, post-dispatch review/mapping/BCH/project/date/reason checks; completed legacy proof/select-only preview/repeat no extra run; same-second pairing count proof; CONFIRMED-after-commit obligation retained/fails/resumes after fixture-authorized resolution.
- PHP syntax13files PASS; Pint7newPHPfiles PASS; diff/clean patch verification recorded at closure. Own isolated MySQL33317 shut down cleanly; schemas retained and runtime untouched. No OCR/provider invocation. Fixtures are synthetic; actual attachment bytes/hosting data not verified.

## Review findings resolved

Setup corrected to actual machine schema. Raw attribute comparison avoids Carbon object identity. Native invariant excludes observer-controlled unprotected review_status (OcrJob.applyAutomaticReview); full protected-job hash still exact. Added actual progress HTML render: @json with complex comma expressions failed compilation; Js::from fixes encoding/compiler parsing. Final review identified disappearing locked-period debt; exact period outbox and regression added. Two additional review gaps were left open at the stop checkpoint and are addressed in the 23/10 continuation below.

## Review continuation — 2026-10-23

The 09/10 checkpoint left two actionable review gaps despite prior acceptance coverage. New regressions reproduced them before correction (3 tests /5 assertions /3 failures): old case pairing was queued without its old machine/day sync; DOWNSTREAM analysis did not apply the current-reason filter.

- Recovery captures the actual old membership case/day before moving evidence. Durable recording expands all old/new case IDs into unique machine/day obligations and exact period IDs in the SAME mutation transaction. DOWNSTREAM captures both the job pointer and actual membership ID, including absent/stale job pointers. Default synchronous recovery also receives the old scope.
- Diagnostic and worker share the same DOWNSTREAM current-reason predicate after existing scope analysis. CLI preview/execute uses this diagnostic gate. Normalized aliases match recovery semantics; a reason removed after dispatch prevents mutation.
- Added 4 tests: move across both machine AND date in RECOVERY/DOWNSTREAM; reviewed old-row preservation; reason inclusion/exclusion in diagnostic/CLI/worker and reason changes after dispatch. Move tests reconstruct the worker after committed mutation, fail after real sync writes, assert both obligations remain and counters roll back, then resume without duplicate membership/counts. Empty old canonical cases keep existing COLLECTING/DAILY_PARTIAL semantics.
- SQLite16/190 PASS; NEW isolated MySQL `p174_unit_review_20261023_a`16/190 PASS. No original/OCR/hour/mapping normalization or business protection weakened.
- Native NEW `p174_review_20261023_a`: independent HTTP PIDs7456/14672, connections15/14, same run302; worker PIDs16240/14656, connections42/43, actual lock waits1019.44/980.71ms. Real exits78/77 and lease-expiry restart PASS;89recovered/2manual/1protected/89memberships/1case/1row/0pending. New request9business-table hashes unchanged,0recovered,4existing audits.
- Native crash/concurrency scenario covers the run protocol; move-specific old/new scope and failure/retry are covered by SQLite/MySQL tests, not a separate moved-evidence hard-kill scenario.

Own isolated MySQL33317 shut down cleanly after verifying process lineage and exact datadir; new fixtures and old baselines retained. Full regression23/10:563tests/5562assertions PASS including PR68. PHP syntax3edited files/Pint2new edited files PASS. Static self-review completed; no independent review claimed. Review patch: `C:/Users/Administrator/.codex/visualizations/2026/10/09/01a11ec8-755d-75d3-8864-9fae7a4bfef8/phase17.4-review-20261023/Phase17.4-review.patch` and SHA manifest; forward/base and reverse/current checks PASS at export. Original temporary09/10 patch is unavailable and superseded.

## Acceptance checklist

- [x] Source/Git/skills, trace and stage measurement.
- [x] Persist fast POST, private/lightweight progress, browser-independent worker.
- [x]91+protected; filters/rights/current eligibility preserved; OCR retry separately reported.
- [x] Durable bounded recovery/pairing/sync and exact downstream obligations.
- [x] True MySQL concurrent dispatch/claim, crash pre/postcommit, retry/rollback/counters/audits/repeat.
- [x] Legacy downstream proof and controlled isolated repair, no approved/source overwrite.
- [x] Targeted/MySQL/full/PR68 regression, view render, review/runbook/skill/checkpoint.
- [ ] Release and live acceptance — OUTSIDE CURRENT AUTHORIZATION, NOT VERIFIED.

## Deployment/rollback and next action

Two additive tables require approved hosting `php artisan migrate --force`; minute schedule already supplied by user but actual hosting configuration/execution NOT VERIFIED. PHP `/opt/alt/php83/usr/bin/php`, checkout `/home/mmzoxgme/repositories/MMTB-ToanThang`; candidate maintenance/update/migrate/cache/schedule-list sequence in runbook, NOT executed. Reload old forms for request_id. No permanent worker/Collector/OCR restart. Keep run/outbox tables on rollback; never discard pending work.

NEXT ACTION: review the new23/10 patch/manifest on this branch; both checkpoint review items now pass local regression; authorize commit/push/release/deployment separately if desired. Only after approved deployment verify a small sender request and actual cron progress, then fresh per-job downstream preview before separately approved live repair. No live operations currently authorized. LOCAL PASS does not imply hosting fixed.

Release hold: user expanded scope to Phase17.5 bulk AI Rescue. Keep17.4 uncommitted and review/release only the final cumulative17.4+17.5 patch; see PHASE-17.5.md. Final expanded regression568/5625 and MySQL40/452 include these two review fixes PASS.
