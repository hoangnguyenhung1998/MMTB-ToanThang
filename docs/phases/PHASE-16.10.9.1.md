# Phase 16.10.9.1 — Safe Manual Daily Photo Re-OCR

- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS
- Date: 2026-09-24
- Branch: `hotfix/phase-16-10-9-1-manual-reocr`
- Verified base: `854b175` — production merge of Phase 16.10.9
- Dependency: Phase 16.10.9
- Related: Phase 16.10.5 backlog recovery; OCR claim/lease lifecycle

## Goal and scope

Add an explicit, dry-run-first Laravel command that safely requeues unresolved Daily Photo source images for one full OCR attempt by the already-deployed Phase 16.10.9 worker. This Phase does not change the OCR engine, TimeMark ROI, parser, source hierarchy, classification gate, machine resolver or any Python worker runtime file.

Production context before the hotfix was `total=946`, `recovered=541`, `queued_retry=17`, `still_exception=370`, `protected=18`. These values are operational context only and are not selectors or constants.

## What “Manual” means in current code

There is no `MANUAL` OcrJob status. The “Cần manual” value shown by `daily-photos.settings` comes from `DailyPhotoBacklogService::report()`:

1. query `document_type=DAILY_TIMEMARK` and `status=EXCEPTION`;
2. analyse stored OCR, mapping, image gate and protection state;
3. count records where `auto_recoverable=false`.

That UI count alone is not a safe mutation selector because protected records also have `auto_recoverable=false`. The new service therefore starts from the exact same exception population and manual calculation, then applies explicit protection, canonical, lease, attempted and source-image guards before requeue.

`Manual/unresolved` means the automatic backlog analysis cannot currently recover the exception. Human-protected data is separately identified by `reviewed_at`, `review_status` in `APPROVED/CORRECTED/REJECTED`, `machine_resolution_method=HUMAN`, or human resolution metadata. Those records are never requeued.

## Command and behavior

```bash
php artisan ocr:daily-manual-retry --dry-run --sample-limit=20
php artisan ocr:daily-manual-retry --execute --sample-limit=20
```

Exactly one of `--dry-run` and `--execute` is required. Dry-run performs no database writes. Samples show job, machine/date/time, current status, the UI manual diagnostic, final eligibility reason, source availability and prior manual re-OCR marker.

Execute uses 200-row `chunkById` batches. Eligible rows are locked and fully re-analysed before mutation. A fingerprint mismatch or changed classification increments `eligibility_changed`. No transaction is held while OCR runs; the command only changes the job to a worker-claimable `RETRY` state.

## Deterministic eligibility

An eligible job must:

- be in the exact UI-manual population (`DAILY_TIMEMARK`, `EXCEPTION`, `auto_recoverable=false`);
- have no human correction, review or approval protection;
- have no canonical `daily_photo_case_id` or evidence membership;
- have no active processing lease or competing queued retry;
- not have a prior `daily_metadata.manual_reocr.attempted_at` marker;
- retain a `STORED` image attachment with an `image/*` MIME type and an existing storage object.

Weekly Journal and ignored hour-meter/non-daily document types are outside the selector. No `created_at`, deploy timestamp, commit SHA or job-ID range participates in eligibility.

Protection totals intentionally overlap their breakdown: `protected_skipped` is the aggregate of `human_corrected_skipped`, `reviewed_confirmed_skipped` and `protected_only_skipped`. All other final skip classifications are exclusive, so `still_ineligible` is auditable as the total non-eligible population.

## Requeue transition and anti-loop provenance

The existing OcrJob is reused; no retry row is created. The transition sets `status=RETRY`, clears claim/lease/error/processed runtime state, clears targeted `ocr_retry_reason`, and keeps `ocr_retry_attempts>=1` so completion cannot automatically schedule another targeted OCR pass. Existing attempts are not reset, because `ocr_processing_runs` has a unique `(ocr_job_id, attempt)` audit history.

`daily_metadata.manual_reocr` stores:

- Phase/version and request timestamp;
- a one-use `claimable` flag;
- worker claim/completion timestamps and result status;
- the full previous extraction/state, including raw OCR, candidate/recovery metadata and retry provenance.

The claim service consumes `claimable` atomically. This permits one manual re-OCR even when the old job already reached normal `max_attempts`, without resetting/deleting processing-run history. After completion or failure the attempted marker remains, so a later command run skips the job. A transient normal retry remains bounded by the existing max-attempt lifecycle.

## Source, canonical and history safety

- Source validation uses the same attachment storage disk/path consumed by the worker image API.
- Any canonical case link/evidence membership fails safe as already resolved.
- Historical raw OCR and provenance are snapshotted before the worker overwrites current extraction fields.
- No manual correction, reviewed field, canonical evidence or downstream materialization is detached or recomputed by this command.
- No external OCR call occurs on Laravel hosting.

## Production runbook

1. After an approved PR is merged to `production`, deploy the Laravel release. No worker-file update is required by this hotfix.
2. Confirm the laptop OCR worker is already at Phase 16.10.9 or later.
3. Run `php artisan ocr:daily-manual-retry --dry-run --sample-limit=20`.
4. Review `eligible_reocr`, protection breakdown, `already_reocr_attempted_skipped`, missing images and active/pending guards.
5. Only after separate explicit approval, run `php artisan ocr:daily-manual-retry --execute --sample-limit=20`.
6. Monitor the laptop worker and wait for the queue to drain.
7. Run `php artisan ocr:daily-backlog-recover --dry-run --limit=20` and/or `php artisan ocr:daily-exception-diagnose --limit=20`.
8. Do not execute another recovery until its dry-run has been reviewed.

The historical production continuity document records checkout `/home/mmzoxgme/repositories/MMTB-ToanThang` and PHP `/opt/alt/php83/usr/bin/php`. Verify both on the host, then the no-migration deployment sequence is:

```bash
cd /home/mmzoxgme/repositories/MMTB-ToanThang
git fetch origin
git checkout production
git pull --ff-only origin production
/opt/alt/php83/usr/bin/php artisan optimize:clear
git rev-parse HEAD
```

Do not run these commands until push/PR/merge/deploy are separately authorized.

## Verification

| Check | Result |
|---|---|
| New command/service, lifecycle, protection and 1,000-row tests | PASS: 13 tests, 63 assertions |
| Related Laravel API/backlog/canonical/workflow integration | PASS: 114 tests, 738 assertions |
| Full Laravel suite | PASS: 306 tests, 1,624 assertions, 41.58s |
| Full Python worker suite | PASS: 58 tests, 1.509s |
| 1,000-row manual scan guard | PASS: 0.77s, at most 55 queries |
| PHP syntax | PASS: 4 changed PHP files |
| Pint `--test` | PASS: 4 changed PHP files |
| `git diff --check` | PASS |

## Database, API and runtime impact

- Migration/backfill: NO. Existing `daily_metadata` is sufficient for durable idempotency and audit provenance.
- API: compatible. Claim response and completion request schemas are unchanged.
- Laravel update: YES.
- OCR worker runtime file update/restart: NO, provided the worker is already Phase 16.10.9 or later.
- Collector update/restart: NO.
- Production requeue/recovery: NOT PERFORMED.

## Checklist

- [x] Audit UI Manual derivation and human protection states.
- [x] Add dry-run/execute command with bounded samples.
- [x] Add lock/recheck, source, canonical, lease and retry guards.
- [x] Add one-use claim override without resetting attempt history.
- [x] Preserve old extraction/provenance and enforce anti-loop behavior.
- [x] Add protection, claim/complete, idempotency, race and 1,000-row regressions.
- [x] Run full Laravel and Python suites plus final static checks.
- [x] Commit local checkpoint; resolve the commit with `git rev-parse HEAD`.
- [ ] Push/PR/merge/deploy/production execute — NOT AUTHORIZED.

## Remaining risks

- Source existence is checked through the configured Laravel filesystem; a remote object can still become unavailable after the command and before worker download.
- The command proves the worker is claim-compatible but cannot itself verify the laptop binary/version; operations must confirm Phase 16.10.9+ before execute.
- Production eligibility distribution remains unknown until an authorized production dry-run.
