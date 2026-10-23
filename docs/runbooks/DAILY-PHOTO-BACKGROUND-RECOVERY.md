# Runbook — Daily Photo background recovery (Phase17.4)

## Semantics and operation

The authenticated settings form POST persists actor, sender, receipt dates, reason, BCH/project and a form UUID; it returns a dedicated progress page. No backlog analysis/recovery/pairing/sync runs in POST. Progress JSON reads one persisted actor-owned run. Settings links the actor's10 recent requests. Existing settings report remains a separate read-only analysis; progress polling never calls it.

The first request freezes maximum OCR job ID and period ID; current eligibility/scope is checked again under the job lock at execution. Later jobs/periods are handled by a later request/normal reconciliation schedule. Reusing the form UUID returns the same run even after completion. A unique active-sender hash prevents overlapping requests; same actor/filter/mode reuses the active run, another actor/scope gets409. Refresh settings before requesting a different scope.

`ocr:process-daily-recovery-runs --steps=10` is registered every minute, `withoutOverlapping(2)`. Hosting minute `schedule:run` cron is user-supplied, actual configuration/execution must be verified after release. No resident queue worker required. CLI defaults10 units, accepts1–100, checks15s elapsed BETWEEN units. Units are: one OCR job; one case; or one machine/date/period including prior-day reconciliation dependency. Pairing >1000 memberships or sync >1000 rows/sources in a day fails visibly for explicit investigation. The cap is a safety boundary, not a silent partial success or eligibility change.

Each attempt has a durable two-minute lease before the business transaction. A live lease cannot be stolen. Each successful unit commits mutation, cursor, counters and downstream work together, then releases lease. Recovery stores unique pairing/day obligations for BOTH previous and destination evidence scopes inside its transaction (old case pairing alone does not clear stale old reconciliation); discovery completes before pairing, then sync advances one period cursor at a time. Exact open period IDs are captured/unioned in the day obligation in the SAME recovery transaction (max100; oversized scopes fail). A subsequently CONFIRMED/EXPORTED/missing period leaves an error and pending obligation; it cannot silently disappear or allow completion. Do not reopen a live approved period just to make a run green; use the authorized business resolution workflow. Completion requires all obligations done. Crash before commit rolls back business data/outbox/progress; crash after commit retains obligations. A hard-crashed unit resumes after lease expiry. Three exceptions/abandoned attempts stop at FAILED with safe code/context; run keeps its active sender until resumed. This prevents endless crashing. Database row locks/token checks enforce ownership even if cron cache locks are bypassed.

“Recovered” means deterministic fields were recovered from existing data and downstream processing completed before the RUN completes. “Queued OCR retry” means RETRY only, never provider success. A COMPLETED request can still contain manual/protected/ambiguous business cases; inspect their existing exception/reconciliation screens. No OCR provider, source photo, payroll rules or human approvals changed. Existing `OcrJob` observer can legitimately set unprotected complete jobs AUTO_APPROVED.

## Diagnostic and resume

Run progress shows safe exception class/domain code plus stage/job/work/period context. Actual exceptions are logged with run ID/stage/type, excluding provider response/secrets. FAILED resume uses the actor-only progress button, or an authorized operator:

```bash
/opt/alt/php83/usr/bin/php artisan ocr:process-daily-recovery-runs --run=RUN_UUID --retry --steps=10
```

Do not blindly retry safety-cap/canonical ambiguity or repeated permanent errors. Inspect the indicated case/day and address the cause; retry retains cursor, durable obligations and prior counters. Closing a browser has no effect. Reopening settings finds recent requests; reload the progress URL after a network failure.

## Historical partial COMPLETED jobs — preview before apply

Old recovery commits job/case changes before synchronous pairing/sync. A later EXCEPTION-only recovery cannot discover the completed jobs. Scope a SELECT by verified sender/receipt dates and the explicit historical `machine_resolution_metadata.backlog_recovered_at` marker; do not infer every COMPLETED job is broken. Example discovery query, substitute a VERIFIED sender/date scope:

```sql
SELECT j.id, j.machine_id, j.extracted_date, j.extracted_time, j.review_status,
       j.daily_photo_case_id
FROM ocr_jobs j
JOIN zalo_attachments a ON a.id=j.zalo_attachment_id
JOIN zalo_messages m ON m.id=a.zalo_message_id
WHERE j.document_type='DAILY_TIMEMARK' AND j.status='COMPLETED'
  AND JSON_EXTRACT(j.machine_resolution_metadata, '$.backlog_recovered_at') IS NOT NULL
  AND m.sender_id='VERIFIED_SENDER_ID'
  AND m.received_at >= '2026-10-01 00:00:00'
  AND m.received_at < '2026-11-01 00:00:00'
ORDER BY j.id LIMIT 100;
```

For EACH reviewed job, SELECT-only preview:

```bash
/opt/alt/php83/usr/bin/php artisan ocr:daily-backlog-recover --dry-run --downstream --job=JOB_ID --sender=VERIFIED_SENDER_ID
```

Proof includes missing membership/case, pairing evidence-count/timestamp gap (including equal-second changes), or row's actual/expected evidence signature and period/row IDs. Protected jobs/rows are excluded from automatic mutation. Unknown origin/no historical marker is not eligible for this repair. Preview does not create run/cache/audit or write-and-rollback. Preview can inspect several overlapping periods for ONE job; it is an operator diagnostic, not the polling/worker path.

ONLY after explicit approval for that live job/scope and a tested backup, enqueue downstream:

```bash
/opt/alt/php83/usr/bin/php artisan ocr:daily-backlog-recover --execute --downstream --job=JOB_ID --sender=VERIFIED_SENDER_ID --actor=AUTHORIZED_USER_ID
```

The optional `--reason=CURRENT_REASON` applies to both preview and execute, using the same normalized current reasons as recovery; repeat the identical filters at execution. A job that no longer matches at worker time is skipped without mutation. A requested reason is separate from diagnostic proof reasons such as STALE_EVIDENCE_SIGNATURE.

It prints run UUID; scheduled worker materializes/pairs/syncs existing fields, rechecking current status/protection/scope. When existing evidence belongs to a previous machine/day, its membership scope and the destination are both queued for pairing/sync atomically; reviewed old rows retain their payload and flag changed evidence through existing sync behavior. No COMPLETED→EXCEPTION reset, no re-OCR, no source/hour overwrite. New downstream work is bounded like normal recovery; second preview/execute with no missing proof creates no extra run. A missing/ambiguous canonical ownership is preserved by existing services for manual review, not force-merged.

Original `ocr:daily-backlog-recover --dry-run/--execute` remains synchronous for compatibility with existing callers/tests; `--limit` STILL means preview sample count, never execution size. HTTP uses durable run API only. Never use the synchronous CLI as a web action.

## Release commands — NOT executed; require approved release/deployment

Preserve hosting-specific files/.env. Before updating, read `git status --short`, branch and approved release SHA; STOP on unexpected tracked changes or wrong branch and reconcile them without reset/stash/discard. Existing deployment runbook is a template; verify hosting update procedure with the operator. No dependencies/provider/Collector/OCR worker changes.

For a clean approved production checkout, the candidate sequence is:

```bash
cd /home/mmzoxgme/repositories/MMTB-ToanThang
git status --short
git branch --show-current
/opt/alt/php83/usr/bin/php artisan down --retry=60
git pull --ff-only origin production
git rev-parse HEAD
# Confirm HEAD equals approved release SHA before continuing.
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan up
/opt/alt/php83/usr/bin/php artisan schedule:list
```

New migration `2026_10_09_000002_create_daily_photo_recovery_runs` adds two tables only. A migration failure leaves maintenance on: investigate; do not expose routes against a missing table. Reload settings forms after release so they contain `request_id`. Verify existing cron invokes this checkout's `schedule:run` every minute with PHP83. Verify a small approved sender request returns quickly, advances across minutes after browser close, preserves manual/protected and reaches completion only after downstream. Only then record LIVE VERIFIED. `schedule:list` alone verifies registration, not cron execution. Running `schedule:run` manually triggers OTHER scheduled commands too; do not use it casually as a test.

Rollback: retain new tables/run/outbox. Drain pending runs or pause ONLY this new schedule entry under approved rollback before reverting app code; do not drop tables or lose pending obligations. Old synchronous HTTP code can timeout again. No runtime process restart is needed for the cron-based worker. No live operation is authorized by this document itself.

## Reproducible local acceptance

SQLite: `php vendor/bin/phpunit tests/Feature/DailyPhotoRecoveryRunTest.php --no-progress`, then full suite. Tests use fake storage/Http::preventStrayRequests.

MySQL: `tests/Support/DailyRecoveryMySql.php` guards localhost33317 and isolated datadir suffix `phase17-3/mysql-data`. `MMTB_RECOVERY_TEST_DB=p174_*` setup MUST use a new schema; never resets an existing schema. Modes: setup, dispatch (actual HTTP kernel), crash-uncommitted(exit78), crash-committed(exit77), work(real CLI), drain, verify, benchmark, benchmark-background. Two independent PHP processes wait on a common optional gate-file argument before dispatch/work; work holds first run lock1s to prove contention. `MMTB_RECOVERY_FIXTURE_CLOCK=after-expiry` advances ONLY fixture process clock3minutes for abandoned-lease restart, not live configuration. Verify checks immutable OCR/source hash, full protected-job hash, exact89 evidence/1case/1row/2manual/1protected and no pending obligations. Old snapshot counter91 is fixture input only.

`DailyRecoveryMySqlBootstrap.php` guards a NEW `p174_unit_*` schema for the same PHPUnit acceptance class; force DB_CONNECTION=mysql/DB33317 in a local PHPUnit config, retain testing/cache/session defaults. No existing schema may be reset. Process output/state/config belongs only in ignored `storage/p174-fixtures`, never patch/Git. Benchmark timing is local evidence, not a prediction of hosting wall time.


Native reproducible runner (requires the guarded isolated MySQL already running):

```powershell
& tests/Support/RunDailyRecoveryMySql.ps1 -Database p174_NEW_UNIQUE_FIXTURE_NAME
```

Use lowercase `p174_*` name per guard, not the literal uppercase placeholder. Runner records independent process IDs/connection IDs, expected exits78/77, lease-expiry restart, verifies exact business counts and repeat hashes. It refuses existing schemas. It never starts/stops Laragon runtime or changes live configuration.

Review validation 2026-10-23: SQLite/MySQL acceptance16tests/190assertions includes moved evidence across machine/day (both modes), missing job case pointer, old reviewed row, actual-sync rollback/retry and current-reason preview/CLI/worker revalidation. Native `p174_review_20261023_a` independently verifies concurrency/crash protocol. Use a new schema name for every rerun.
