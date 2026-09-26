# Phase 16.10.10.1 — Versioned Manual Daily Photo Re-OCR Hotfix

- Status: COMPLETED locally — implementation, required Laravel tests, static checks and local checkpoint commit complete
- Date: 2026-09-26
- Branch: `hotfix/versioned-manual-daily-reocr`
- Verified base: `cdbab09` — Phase 16.10.10 local checkpoint
- Dependencies: Phase 16.10.9.1, Phase 16.10.10
- Related: OcrJob claim/lease lifecycle; manual Daily Photo backlog

## Goal

Allow an operator to explicitly requeue an unresolved manual Daily Photo once for a named OCR pipeline version, while preserving every existing protection and keeping the original no-option behavior unchanged. This hotfix is used to process the legacy backlog with pipeline `16.10.10` before any decision about Phase 16.10.11; it does not claim that Phase 16.10.11 fixes the backlog.

## Root cause

The original command correctly treated `daily_metadata.manual_reocr.attempted_at` as a permanent anti-loop marker. Production dry-run evidence showed 332 jobs already carried that legacy marker, so none could be reprocessed after the Phase 16.10.10 OCR improvements even though they had never attempted that pipeline version.

## Scope and non-goals

- Add optional `--retry-version=<version>` to the existing Laravel command.
- Permit one explicit requeue per validated version key.
- Preserve legacy metadata/snapshots and append version-specific request, claim, worker, completion and result provenance.
- Keep dry-run read-only and execute requeue-only; Laravel hosting never performs OCR.
- Keep HUMAN/reviewed/protected, canonical, lease/queue, source-image and non-daily guards unchanged.
- No parser, Python worker, Collector, schema, migration, backfill or production data change is included.

## Commands

```bash
php artisan ocr:daily-manual-retry --dry-run --retry-version=16.10.10
php artisan ocr:daily-manual-retry --execute --retry-version=16.10.10
```

Without `--retry-version`, the legacy `manual_reocr.attempted_at` marker still blocks retry exactly as before. A different version is eligible only when explicitly supplied and only when that exact version key has no recorded attempt.

## Metadata and technical decisions

- Existing `daily_metadata.manual_reocr.attempted_at`, `previous_state` and other legacy fields are retained.
- Version history is appended at `manual_reocr.version_attempts[<validated-version>]`.
- A version entry snapshots the pre-requeue state and records `requested_at`, `claimed_at`, `worker_id`, `completed_at`, `completed_attempt` and `result_status` as lifecycle events occur.
- The version accepts only two to four dot-separated numeric components, is trimmed, is limited to 32 characters and is used as a direct array key rather than a dot-path.
- Execute locks and re-analyses the existing OcrJob, writes the version marker in the same transaction as `EXCEPTION -> RETRY`, and reuses the one-use `manual_reocr.claimable` override.
- Claim, completion and terminal worker failure update the active version entry. Normal jobs and legacy no-version retries retain their existing metadata behavior.
- Dry-run includes both `already_reocr_attempted_skipped` and `already_attempted_for_version`; `requeued` remains zero.

## Safeguards retained

- HUMAN resolution and human-resolution metadata.
- `reviewed_at` and `APPROVED` / `CORRECTED` / `REJECTED` review states.
- Backlog `protected` classification.
- Canonical case or evidence membership.
- Pending/queued/targeted retry, processing and active lease state.
- Missing, non-image, non-STORED or inaccessible source attachment.
- Ignored hour-meter, ignored non-daily and non-Daily Photo jobs.
- Lock/recheck, fingerprint race detection and existing attempt fencing.

The 22 protected production jobs reported by the pre-hotfix dry-run remain ineligible when `--retry-version=16.10.10` is supplied.

## Verification

| Check | Result |
|---|---|
| Baseline manual retry suite | PASS: 13 tests / 63 assertions |
| Updated versioned manual retry suite | PASS: 18 tests / 101 assertions |
| Manual retry + OcrJob API + Daily Photo workflow | PASS: 59 tests / 333 assertions |
| Full Laravel suite | PASS: 319 tests / 1,707 assertions / 50.90s |
| PHP syntax / Pint `--test` / diff check | PASS: 3 application files / 4 PHP files / clean diff check |

Mandatory regressions cover legacy blocking, first version eligibility, same-version one-shot behavior, explicit different version, HUMAN/review protections, canonical evidence, active lease/running state, missing source, read-only dry-run, requeue-only execute, immutable legacy provenance and competing same-version execution.

## Deployment order

1. After separate approval, publish/merge the Laravel hotfix and deploy it to hosting.
2. No migration or schema step is required; clear Laravel caches according to the normal deployment runbook.
3. Confirm the existing laptop OCR worker is already running the approved Phase 16.10.10 pipeline. This hotfix itself requires no worker file update or restart.
4. Run the versioned dry-run and verify the protection count remains 22 plus the other guard totals.
5. Only after separate approval, run the versioned execute command once.
6. Monitor the existing worker queue and completion results. Do not run a different version unless explicitly authorized.
7. Collector update/restart is not required.

## Checklist and result

- [x] Preserve no-option legacy behavior.
- [x] Add validated version-specific one-shot eligibility.
- [x] Preserve legacy provenance and append version lifecycle history.
- [x] Keep version marker/requeue atomic and race-safe.
- [x] Add all mandatory targeted regressions.
- [x] Run related lifecycle tests and full Laravel suite.
- [x] Update continuity documentation.
- [x] Local checkpoint commit; resolve its immutable SHA from the current branch `HEAD`.
- [ ] Push/PR/merge/deploy/production commands/runtime restart — NOT AUTHORIZED and NOT PERFORMED.

## Remaining risks

- A source object can disappear after execute and before the laptop worker downloads it; existing worker failure handling remains authoritative.
- Production eligibility totals after the hotfix are NOT VERIFIED until an authorized read-only versioned dry-run is reviewed.
- This hotfix records one attempt per explicit version; choosing a future version remains an operator decision and is never automatic.
