# Phase 17.3 — guarded global repair operations

## Scope and approval boundary

This describes the locally implemented **Sửa liên kết tất cả kỳ** action at `/reconciliation-periods`. No production operation was executed or authorized by the implementation task. Commit/push/PR/merge/deploy, migration, scheduler changes and production apply require explicit authorization. `october-full.json` is evidence input, not apply approval; preserve privately/unversioned.

Preview snapshots all existing periods regardless of pagination/filter. It does not create periods/rows, rerun OCR or recalculate allocation. Protected/unauthorized periods remain reported. Periods created after preview need fresh preview. Approved/confirmed/locked/HUMAN/manual evidence and lifecycle ownership rules remain protected.

Review correction: explicit allocation `0`/`'0'` is populated and differs from NULL, in either survivor direction. Snapshot hashing sorts stable database keys/record IDs only; it retains field types/values and shared-row protection snapshots. On restored MySQL acceptance also reverse query result order/compare locked and unlocked reads, while confirming any real business/protection change remains stale-rejected. Old fingerprints from the preceding implementation must be previewed afresh.

## Release and restored-copy acceptance

1. Review shared proof/planner/writer, migration, policies, middleware and tests; record exact release. Independently observe hosting HEAD rather than substituting an operator release label.
2. Obtain an approved consistent database backup plus referenced attachment storage. Record time, scope, checksum and private location; include rows, assignments/history/events, canonical cases/members/intervals, OCR jobs, audits and repair runs. Test restore on an isolated copy with ingestion/OCR/cron writers disabled by its operator. A backup without tested restore is insufficient.
3. On the restored MySQL copy, verify actual simultaneous POSTs and workers: one active global run, one committed period result, no double deletion/audit or partial mutation. SQLite tests do not prove MySQL locks. Exercise shared canonical evidence across weekly/monthly periods and concurrent authorized assignment/period-state writers; changed evidence must be protected or stale-skipped.
4. Compare full-scope SELECT-only preview and restored-copy apply for every business field, rich-row identity and endpoint/job relationship. Differing populated time/GPS/descriptors, independent journals, HUMAN/manual/reviewed/locked evidence and missing/foreign references must be retained. Verify August/September, 126 genuine NULL days, gaps/return/A→B→C, Generator/Sync/replay idempotence and Validator/audit totals. Packet summaries omit some live fields, including OCR document type; 129 pairs are only candidates.
5. After separate release/deploy authorization, deploy reviewed code and execute the additive migration with the established hosting PHP binary (`php artisan migrate --force` only when approved). It creates `reconciliation_repair_runs`, without changing business tables. Keep global UI inaccessible until migration completes; background command must not run before the table exists. Run existing authorized cache-refresh workflow if required by deployment. Verify existing Laravel scheduler runs every minute. Its new command is `php artisan reconciliation:process-repair-runs --limit=1`, integer limit 1–10, default 1. No extra dependency, scheduler or runtime restart is required by this implementation. Requests wait if scheduler is unavailable; verify it before production submission.

## Preview, approved apply and progress

1. Open the period list and select **Sửa liên kết tất cả kỳ**. GET uses shared Repair decisions in memory and application-table SELECTs only, never rolled-back writer preview. Core authentication/session/CSRF remains; notification synchronization is excluded from read-only routes.
2. Review every period's relinks, unassigned normalization, removals, same-source consolidations, already-correct/protected/unresolved counts and reasons. Protected-period row counts are separate. Preview is encrypted, actor-bound, expires in 30 minutes and binds captured period IDs and evidence fingerprint. Retain approved scope/counts privately; do not discard independent evidence to meet a target count.
3. Obtain separate explicit production scope approval with tested recoverable backup. POST/CSRF persists a run without synchronous repair. Same preview UUID double-submit returns same run; different preview while active is rejected with `GLOBAL_REPAIR_ALREADY_RUNNING`. Class/per-period policies apply, and actor/permission/period state is rechecked by worker.
4. Browser may close; scheduler processes one pending period per invocation. Refresh run page for persisted results. RUNNING means pending; PARTIAL means failed periods; COMPLETED_WITH_REVIEW means skipped/unresolved; otherwise COMPLETED. A committed period with unresolved rows is not fully repaired. Applied counts come from result, not preview estimates.
5. Worker locks run, period, rows and relevant evidence/history, replans and checks fingerprint before writing. `STALE_PREVIEW` skips without mutation. Missing period, revoked permission or new protection skips. Earlier-period canonical changes may conservatively stale-skip later overlapping periods; fresh preview after completion resolves this without overriding proof.

## Failure, retry and audit

Each period atomically commits data, child audits and progress. An exception rolls back that period; a separate transaction marks FAILED/PERIOD_TRANSACTION_FAILED. Subsequent periods continue; earlier commits remain. Crash before commit leaves pending work for the next bounded invocation. UNIQUE active slot and row locks protect global concurrency; scheduler `withoutOverlapping` is supplementary.

After completion choose fresh preview/retry. Already completed rows are idempotent no-ops; failed/stale periods are revalidated. Never replay expired tokens, reset progress manually or delete active rows to bypass concurrency. For stalled runs inspect scheduler availability and sanitized error class/run/period IDs. Operational logs omit exception messages/raw SQL/secrets.

Activity logs record actor, UUID, scope/result/fingerprint. Consolidation records source/target snapshots, survivor before/after, OCR/interval IDs and owner; relink/canonical/OCR metadata changes have correlated child audits. Business snapshots remain private; do not paste raw rows/OCR/auth material into public logs/PRs. Consolidation never reconstructs source OCR, images, assignment history or interval pairing.

## Rollback and final verification

Code rollback cannot restore deleted rows. Before separately approved data rollback, control global submissions/related writers under an authorized maintenance plan, retain run/child audits and identify all committed periods/shared canonical evidence. Validate restoration on isolated copy using backup plus before/after audits, accounting for primary/unique/FK constraints and later legitimate changes. Restore approved coherent scope transactionally or from tested consistent backup; never blindly overwrite subsequent writes or reinsert isolated rows without relationship checks. No automatic production rollback/destructive repair script is provided.

Dropping run table loses progress/correlation and must not occur during active run. Migration rollback is a separate approved schema action. Preserve completed records.

After approved apply, collect same read-only audit scope with actual release/timestamps/checksums using `PHASE-17.3-RESIDUAL-AUDIT.md`. Compare pre/post counts, rich-row business fields/identity, canonical/OCR relationships, protected rows, historical NULL days and Validator/export. Record skipped/unresolved/failed separately. Historical writer and original reported `3` remain UNKNOWN until original diagnostics prove them. Production verification requires observed evidence.
