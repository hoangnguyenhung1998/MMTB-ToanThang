# Phase 16.10.7 — Residual Daily Photo Exception Root-Cause & Deterministic Recovery

- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS
- Date: 2026-09-22
- Branch: `fix/phase-16-10-7-residual-daily-photo-recovery`
- Verified base: `d5aa4b3` — production merge of Phase 16.10.6
- Dependency: Phase 16.10.6
- Related: Phase 16.10.1–16.10.5 canonical evidence, pairing, reconciliation, mapping and backlog recovery

## Goal and scope

Classify the residual production Daily Photo exceptions by actionable cause, recover every row whose machine/date/time is deterministic, materialize canonical evidence despite stale automatic exception state, and retain true ambiguity, missing OCR data and protected human records for manual handling. This Phase does not redesign OCR, mass re-OCR the backlog, change payroll/allocation rules or operate on production.

## Production evidence leading to this Phase

The initial 774-row backlog recovery reported 275 recovered, 15 queued retries, 466 remaining exceptions and 18 protected rows. After retry processing, the residual diagnostic was approximately 488 rows: 220 `RETRY_RESULT_NOT_APPLIED`, 197 `CANDIDATE_AGGREGATION_FAILURE`, 52 dropped time, 11 dropped date, 4 canonical-not-materialized, 2 missing mapping, 1 dropped machine and 1 retry-not-run. A subsequent recovery reported zero recovered and zero queued despite 469 non-protected residual exceptions. Those counts are user-supplied production evidence; this local task did not access production.

## Root causes proved in current code

1. `DailyPhotoStoredOcrExtractor` reparsed raw OCR but ignored deterministic scalar `date`, `time` and `asset_code` values already present in stored initial/retry/final extraction snapshots. A retry could therefore find the missing field while backlog recovery still classified it as absent.
2. Diagnostic collapsed every candidate conflict into `CANDIDATE_AGGREGATION_FAILURE`; it could not distinguish a real time/date/machine conflict from duplicate-equivalent candidates or a legacy conflict marker whose normalized candidate set is now unique.
3. Complete residual rows require transition from `EXCEPTION` to `COMPLETED` before `DailyPhotoCaseService` accepts them. The old report did not expose this downstream gate as a precise execute action, which obscured VT-LU0196-style rows with complete persisted fields but no canonical membership.
4. Execute analysed a row before obtaining its lock and then used that plan without an explicit state fingerprint. Dry-run and execute shared most code but could not report that eligibility changed between planning and lock acquisition.

## Suspected causes not proved

- The exact production split of all 488 rows after applying the new actionable classifier remains NOT VERIFIED until the read-only diagnostic is deployed and run.
- No evidence in the current Phase 16.10.6 worker implements a trusted-region winner over two different normalized times. Trusted `time_date` only broadens safe syntax to accept dash-separated time. Therefore differing values such as `14:30` and `16:47` remain a true conflict.
- No production image or database row was inspected locally, so the actual count recoverable from structured retry snapshots versus stored raw OCR remains NOT VERIFIED.

## Initial hypotheses ruled out or narrowed

- The live targeted retry merge does not erase valid initial scalar fields; existing `mergeTargetedRetryResult()` already preserves them. The residual gap was the backlog reader ignoring deterministic structured retry scalars after they had been stored.
- `DailyPhotoCaseService` is not failing to create evidence from a valid `COMPLETED` job. It intentionally rejects `EXCEPTION`; the missing step was deterministic stale-state cleanup before invoking the existing canonical service.
- No global dash-to-colon normalization or worker parser rewrite is needed. Trusted-region `06-57` support already exists and remains bounded to `time_date`.
- Candidate aggregation failures are not uniformly recoverable legacy artifacts. Distinct normalized candidates remain true ambiguity; Job 4 proves the time-conflict case.

## Deterministic recovery and ambiguity rules

- Persisted valid fields remain authoritative. Structured initial/retry/final scalars supplement only fields still missing.
- Raw OCR, structured scalar values and candidate metadata are normalized into one candidate view. Distinct normalized values or an ambiguous numeric date fail closed.
- Repeated equivalent candidates normalize to one value and are not a conflict.
- Valid unique image machine wins; invalid/missing image machine may use the receipt-effective historical sender mapping. Machine ambiguity never falls through to mapping.
- Complete deterministic rows are recovered even when their remaining reason is automatic/stale. Recovery clears automatic exception state, materializes evidence/case and recomputes existing pairing/reconciliation paths.
- Reviewed, corrected, approved, rejected or HUMAN-resolved rows remain protected.
- Dry-run and execute use the same analysis. Execute locks a bounded chunk and compares a state fingerprint; a changed row is reported as `eligibility_changed` instead of silently applying a stale plan.

## Actionable diagnostic subtypes

`READY_TO_MATERIALIZE`, `RETRY_VALUE_NOT_MERGED`, `TRUE_TIME_CONFLICT`, `TRUE_DATE_CONFLICT`, `TRUE_MACHINE_CONFLICT`, `DUPLICATE_EQUIVALENT_CANDIDATES`, `LEGACY_AGGREGATION_FAILURE`, `ACTUALLY_MISSING_TIME`, `ACTUALLY_MISSING_DATE`, `MAPPING_RECOVERABLE`, `MAPPING_MISSING`, `OCR_RECOGNITION_FAILURE`, `STALE_EXCEPTION_ONLY`, and `PROTECTED`.

## Required production-shaped cases

- Job 4 / VT-XX5109: `2026-07-27`, time candidates `14:30` and `16:47` classify as `TRUE_TIME_CONFLICT`; recovery leaves the row manual with no canonical evidence.
- VT-LU0196: complete `2026-08-17` rows at `06:24`, `10:33` and `13:45` classify `READY_TO_MATERIALIZE`; stale `OTHER`/`CAPTURE_TIME_MISSING` state is cleared and all three canonical evidence memberships receive capture datetimes.
- SGC-T-3C0715: receipt-effective sender mapping plus deterministic date and trusted `[time_date]` token `06-57` normalizes to `06:57`, clears stale automatic state and materializes canonical evidence. The same dash token outside the trusted region is not parsed as time.

## Retry merge and stale exception semantics

Retry output cannot erase a valid persisted field and is not allowed to resolve a true conflict. Deterministic retry scalars that were never copied into `ocr_jobs` are now visible to recovery and classified as `RETRY_VALUE_NOT_MERGED`. `LOW_CONFIDENCE` remains telemetry only. Automatic legacy reasons may be cleared only after deterministic machine/date/time resolution; protected human state is never cleared.

## Performance strategy

- Analysis remains `chunkById(500)` with bulk message, machine, canonical membership, mapping-history and assignment loading.
- Execute uses `chunkById(200)`, one ordered lock query and one bounded transaction per chunk instead of one lock transaction for every manual residual row.
- Canonical pairing is recomputed once for unique affected case IDs after materialization, and reconciliation remains period-batched through the existing service.
- The 1,000-row true-conflict recovery regression asserts completion under 30 seconds and at most 70 queries with zero canonical writes. The existing 1,000-row read-only report guard remains at most 15 queries.

## Tests

- Final targeted diagnostic/backlog result: 29 tests, 227 assertions PASS.
- Coverage includes structured retry date/time merge, VT-LU0196 materialization, Job 4 true conflict, duplicate-equivalent candidates, legacy conflict normalization, protected rows, idempotency, SGC-T-3C0715 trusted dash parsing, untrusted dash rejection and 1,000-row bounded recovery.
- Targeted worker parser/TimeMark result: 29 tests PASS.
- Daily Photo/canonical/reconciliation targeted set: 122 tests, 815 assertions PASS before the final bounded-report assertions; the final full suite below includes those assertions.
- Full Laravel suite: 287 tests, 1,500 assertions PASS in 65.77 seconds.
- Pint `--test`: 7 changed PHP files PASS.
- PHP syntax: 7 changed PHP files PASS.
- `git diff --check`: PASS.

## Database / API / worker / collector

- Migration: NO.
- API contract change: NO.
- OCR worker code change: NO; worker update/restart is not required for this Phase delta.
- Collector change: NO; Collector restart is not required.

## Deployment and rollback safety

No push, merge, deploy, production recovery or runtime restart is authorized. A later deployment needs Laravel code only and no migration. Run the read-only diagnostic first, review subtype counts and samples, then run the dry-run with the same scope. `--execute` is allowed only after the scope shows deterministic recover/retry actions, true conflicts and protected rows remain excluded, and the user separately authorizes the production write. Application rollback requires no schema action; already materialized canonical records are retained and can be audited through existing provenance.

## Remaining work

- Local implementation scope is complete. Create the requested local checkpoint commit.
- Do not push, create/merge a PR, deploy, run production diagnostic/recovery or restart runtime processes without separate authorization.
