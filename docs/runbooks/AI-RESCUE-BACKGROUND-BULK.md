# Runbook — Confirmed bulk AI Rescue (Phase17.5)

## Operator flow and snapshot

Select Manual reason groups on Ảnh và ngoại lệ OCR. Selection union count uses the SAME SQL normalized persisted reason taxonomy as dashboard cards (Phase16.11.3), with OcrJob IDs unique across groups. It no longer runs the complete derived Daily Photo backlog report. Preview is SELECT-only: no run, attempt, audit, cache write or provider invocation. Encrypted previewv2 carries exact selected job IDs, reason groups, actor, UUID and default30-minute expiry. Confirmation persists that ID list; it never expands it with later arrivals. Actor ownership applies to preview token execution/progress/retry. Oldv1 previews must be refreshed after release.

Detailed eligibility/source analysis in a web preview is bounded to50 selected jobs. Larger selections explicitly display the MAXIMUM candidate count and unknown skip categories; they do not claim every photo is eligible. Confirm once to authorize checking/enqueueing the entire frozen selection. Worker rechecks full original eligibility/protection/source before each attempt. There is no need to split groups or repeatedly confirm batches. Protected/resolved/canonical/missing-source/prior terminal AI outcomes remain excluded as before. Reuse of an active attempt is linked and shown separately; no extra attempt is requested.

Confirmation HTTP persists one AI run and one request audit, then redirects to private progress. It calls no recovery/backlog analysis or provider. Recent requests appear on OCR Review (latest10 owned requests), so reopening after browser closure resumes visibility. A new preview represents a NEW deliberate selection; replaying the SAME confirmed preview UUID within its TTL returns its existing run, even after attempts become terminal. After token expiry use the recent-request/progress link; existing run and selected work do not expire with the preview.

## Runtime and progress

`ocr:process-ai-rescue-runs --steps=50` runs every minute through existing hosting schedule:run. No queue daemon or after-response work. Each unit processes ONE selected job transactionally; steps clamped1–100 and15-second budget checked BETWEEN units. It validates using existing DailyPhotoAiRescueBatchService category and DailyPhotoAiRescueService request under the job lock. Attempt/item link/cursor/counters commit together; a crash rolls back all or retains all. A two-minute durable claim exists before business work, with max3 failures/abandoned claims then visible FAILED. Explicit retry preserves completed work and never resubmits linked terminal attempts.

Existing journal-worker still claims durable AI attempts and uses the original image, provider/model/prompt/schema, fenced lease, bounded retries, optional usage and fairness (max3 consecutive daily rescues before other queues get a chance). This patch adds no provider transport/concurrency/quota override, no automatic enqueue before confirmation and no real AI call. Actual deployed model/quota is environment-owned and NOT VERIFIED. Existing OCR lease/retry semantics remain; this enqueue fix does not promise provider-side exactly-once billing across ambiguous network outcomes.

Progress JSON aggregates only indexed items/linked attempts of this run, without hydrating images or reanalysing backlog. It reports selected/checked/newly-enqueued/already-queued/skipped plus OCR queued/processing/success/review/failed. Enqueue completion is OCR_RUNNING while any linked attempt is pending/retry/processing. COMPLETED requires no active OCR; COMPLETED_WITH_REVIEW includes skipped/manual/failed outcomes and is not a claim all OCR succeeded. Retry button resumes enqueue errors only; it does not rerun terminal provider failures or manual outcomes.

## Diagnose and resume

Use the owned progress URL `/ocr-reviews/ai-rescue/runs/RUN_UUID`. FAILED shows safe exception class and selected job ID; logs identify run/job/type without provider payload/secrets. Investigate a persistent DB/storage/domain error before retry:

```bash
/opt/alt/php83/usr/bin/php artisan ocr:process-ai-rescue-runs --run=RUN_UUID --retry --steps=50
```

A live claim cannot be stolen. Hard crash recovery waits until its lease expires; repeated hard crashes stop instead of looping forever. Do not alter protected data to force enqueue, reset attempts or enqueue the whole dashboard outside the frozen request. Retain run and item/attempt history for diagnostics.

## Approved release only — NOT executed

Cumulative patch includes Phase17.4 plus17.5. Two additive migrations add FOUR new tables total:17.4 recovery run/work,17.5 AI run/items. Existing AI attempt history table is unchanged. Keep tables/pending work on rollback; no destructive down migration while records exist.

After explicit release/deployment approval, verify clean hosting checkout/branch and approved exact release SHA; preserve host .env/local config and stop on unexpected changes. See DAILY-PHOTO-BACKGROUND-RECOVERY.md for full controlled update/maintenance sequence. Once approved code is updated:

```bash
cd /home/mmzoxgme/repositories/MMTB-ToanThang
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
/opt/alt/php83/usr/bin/php artisan schedule:list
```

Verify actual cron invokes this checkout's schedule:run every minute. schedule:list proves registration only. No permanent queue worker or Collector/reconciliation/journal-worker source change/restart is introduced by17.5; an already compatible AI-enabled journal-worker must be running to drain AI attempts. Hosting and laptop deployed versions/config are NOT VERIFIED here. Confirm one small approved request, close/reopen page, observe scheduled enqueue and actual OCR terminal outcomes before broader use. A scheduler enqueue alone does not prove OCR works. Live AI calls incur provider usage and require separately authorized operation; none were made during local tests.

Rollback: pause ONLY new enqueue schedule under authorized maintenance if needed; retain confirmed selections/items/attempts. Already queued attempts remain owned by the existing worker. Do not discard them or silently recreate a request.

## Reproducible isolated tests

Use new fixture schemas only. `tests/Support/AiRescueMySql.php` refuses non-p175 names, wrong localhost33317 or datadir outside phase17-3/mysql-data; setup refuses existing schema. It uses synthetic source files and Http::preventStrayRequests. Native `RunAiRescueMySql.ps1 -Database p175_NEW_UNIQUE_NAME` runs2062 fixture, independent actual HTTP dispatch and worker processes, hard exits78insideTX/77aftercommit, valid-lease exclusion/expiry restart, full enqueue, real AI claim/complete through a LOCAL fake provider (UNKNOWN), duplicate callbacks and replay after terminal work. No provider network/cost. Use lowercase names, not literal uppercase placeholders. Failed fixture schemas are retained, never reset for a cleaner result.

MySQL PHPUnit uses existing guarded DailyRecoveryMySqlBootstrap with NEW p174_unit_* schema; its storage is isolated per process to avoid fake-disk cleanup racing SQLite/native tests. Runtime outputs live only under ignored storage/p174-fixtures. Do not include them, encrypted live tokens, raw logs or fixture data in review patches. Python worker tests use provider mocks and verify fairness. Full SQLite and both Phase17.4 review fixes must remain PASS.
