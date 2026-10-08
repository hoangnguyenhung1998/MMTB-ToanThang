# Phase 17.3 continuation — read-only residual audit

This runbook collects evidence; it does not change assignments or reconciliation rows. Use the existing production schema, under the operator's control. This session did not access production.

## Collection before any new deployment

Execute the SQL below in a MySQL client on the intended database and save all result sets locally. Check period #8 dates and the four machine identities first. No new code deployment is needed. Missing rows or changed IDs must be reported, not replaced by assumptions. If any query fails, end the read-only transaction with `ROLLBACK`; fix the query against the actual schema before interpreting partial results.

Row payloads include business content. Keep results with the project reviewers; credentials and full OCR raw/result/metadata are not selected. Full OCR/evidence payloads stay in the database, accessible by the reported IDs if review needs them.

## Collection after the local command is reviewed and separately deployed

Run `php artisan reconciliation:residual-audit 8 > phase17-3-residual-audit.json` from the intended Laravel checkout. The `>` saves output locally; the command performs only SELECT queries in a transaction, without row locks. Optional `--row=85904 --row=85905` replaces the default five focus IDs. All rows with any nullable relationship in the period are always included. No production command was run in this session.

Compare current timeline contexts, NULL relationship keys, canonical scope/references, normalization audit history and validator independently. `9 GAP + 117 AFTER_RETURN` is a user-reported normalization baseline, not an error count. Candidate narrowing proof does not authorize repair, deletion, splitting, confirmation or export. Actual protected/duplicate/source-overlap/lifecycle/hour/canonical conflicts still require review. Current snapshots cannot prove historical preservation without pre-repair snapshots or existing audits. Shared row snapshots show status/locks in overlapping periods.

The JSON emits selected fields and SHA-256 hashes of diagnostic record snapshots, plus photo checksums and interval/pair IDs. It does not emit opaque OCR metadata or raw extraction. Row/case dates are normalized for timeline semantics before hashing. Hashes compare the same diagnostic section and record between runs; they do not prove semantic equivalence of differently serialized JSON. The diagnostic service reuses Repair's canonical narrowing proof and the existing independent Validator. Query/hydration/runtime limits of Repair remain unchanged.

After an approved release, collect this report before any separately authorized Repair. Recollect after a separately authorized repair and after a second approved run, checking relationship changes versus unchanged business/evidence snapshots. No automatic production Repair command or mutation is provided here. MySQL concurrency/performance and the exact five production dispositions remain NOT VERIFIED until actual evidence is reviewed.

## SQL (existing schema)

```sql
-- Phase 17.3 continuation: run by the operator, on the existing production schema.
-- No Repair, UPDATE, INSERT, DELETE, migration, source correction or pairing.
-- Confirm period 8 dates and machine identities before interpreting any result.
START TRANSACTION READ ONLY;

SELECT id, date_from, date_to, status FROM reconciliation_periods WHERE id = 8;
SELECT id, asset_code, chassis_no, status FROM machines WHERE id IN (25, 55, 135, 221);

SELECT r.* FROM reconciliation_rows r
WHERE r.reconciliation_period_id = 8
  AND (r.id IN (85904, 85905, 86442, 87577, 89197)
       OR r.machine_assignment_id IS NULL OR r.project_id IS NULL OR r.command_center_id IS NULL)
ORDER BY r.machine_id, r.work_date, r.id;

-- Full source history, not only the September slice.
SELECT a.*, b.name AS resolved_bch_name, COALESCE(a.command_center_id, h.command_center_id) AS resolved_bch_id
FROM machine_assignments a
LEFT JOIN machine_assignment_bch_resolutions h ON h.machine_assignment_id = a.id
LEFT JOIN command_centers b ON b.id = COALESCE(a.command_center_id, h.command_center_id)
WHERE a.machine_id IN (25, 55, 135, 221)
   OR a.machine_id IN (SELECT machine_id FROM reconciliation_rows
                      WHERE reconciliation_period_id = 8 AND machine_assignment_id IS NULL)
ORDER BY a.machine_id, a.time_in, a.id;

SELECT e.id, e.machine_id, e.type, e.occurred_at,
       e.from_project_id, e.to_project_id, e.from_command_center_id, e.to_command_center_id
FROM machine_events e
WHERE e.machine_id IN (25, 55, 135, 221)
   OR e.machine_id IN (SELECT machine_id FROM reconciliation_rows
                      WHERE reconciliation_period_id = 8 AND machine_assignment_id IS NULL)
ORDER BY e.machine_id, e.occurred_at, e.id;

-- Siblings across periods include the shared canonical protection context.
SELECT r.*, p.status AS period_status FROM reconciliation_rows r
JOIN reconciliation_periods p ON p.id = r.reconciliation_period_id
WHERE r.machine_id IN (25, 55, 135, 221)
  AND r.work_date IN ('2026-09-03', '2026-09-08', '2026-09-10', '2026-09-11')
ORDER BY r.machine_id, r.work_date, r.reconciliation_period_id, r.id;

SELECT c.id, c.machine_id, c.machine_assignment_id, c.work_date, c.scope_key, c.status,
       c.source_version, c.pairing_computed_at
FROM daily_photo_cases c
WHERE c.work_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND (c.machine_id IN (25, 55, 135, 221) OR c.machine_assignment_id IS NULL)
ORDER BY c.machine_id, c.work_date, c.id;

SELECT m.id, m.daily_photo_case_id, m.ocr_job_id, m.capture_datetime,
       m.pairing_state, m.assignment_resolution_status
FROM daily_photo_case_evidence m JOIN daily_photo_cases c ON c.id = m.daily_photo_case_id
WHERE c.work_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND (c.machine_id IN (25, 55, 135, 221) OR c.machine_assignment_id IS NULL)
ORDER BY m.daily_photo_case_id, m.capture_datetime, m.id;

SELECT i.id, i.daily_photo_case_id, i.sequence, i.start_evidence_id, i.end_evidence_id,
       i.raw_start_at, i.raw_end_at, i.pairing_policy_version
FROM daily_photo_intervals i JOIN daily_photo_cases c ON c.id = i.daily_photo_case_id
WHERE c.work_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND (c.machine_id IN (25, 55, 135, 221) OR c.machine_assignment_id IS NULL)
ORDER BY i.daily_photo_case_id, i.sequence, i.id;

-- OCR raw/result/opaque metadata remain untouched and are not exported by this query.
SELECT j.id, j.machine_id, j.daily_photo_case_id, j.zalo_attachment_id, j.document_type,
       j.status, j.review_status, j.reviewed_at, j.extracted_date, j.extracted_time,
       a.id AS attachment_id, a.sha256, a.byte_size, a.zalo_message_id
FROM ocr_jobs j LEFT JOIN zalo_attachments a ON a.id = j.zalo_attachment_id
WHERE j.document_type = 'DAILY_TIMEMARK' AND j.extracted_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND (j.machine_id IN (25, 55, 135, 221)
       OR j.daily_photo_case_id IN (SELECT id FROM daily_photo_cases
                                   WHERE work_date BETWEEN '2026-09-01' AND '2026-09-30' AND machine_assignment_id IS NULL))
ORDER BY j.machine_id, j.extracted_date, j.extracted_time, j.id;

SELECT jr.id, jr.journal_document_id, d.machine_id, d.ocr_job_id,
       jr.work_date, jr.start_time, jr.end_time,
       j.review_status AS journal_review_status
FROM journal_rows jr JOIN journal_documents d ON d.id = jr.journal_document_id
LEFT JOIN ocr_jobs j ON j.id = d.ocr_job_id
WHERE d.machine_id IN (25, 55, 135, 221) AND jr.work_date BETWEEN '2026-09-01' AND '2026-09-30'
ORDER BY d.machine_id, jr.work_date, jr.start_time, jr.id;

SELECT id, subject_id, occurred_at,
       JSON_EXTRACT(properties, '$.old') AS old_relationship,
       JSON_EXTRACT(properties, '$.new') AS new_relationship,
       JSON_EXTRACT(properties, '$.timeline_context') AS timeline_context
FROM activity_logs
WHERE event = 'reconciliation.relationship_unassigned'
  AND subject_id IN (SELECT id FROM reconciliation_rows WHERE reconciliation_period_id = 8)
ORDER BY id;

COMMIT;

```


## Day-based BCH fix — later deployment and verification (requires separate authorization)

The current local code uses BUSINESS_DAY ownership. Physical candidate narrowing in older SQL output remains diagnostic evidence; it is no longer daily BCH acceptance. New JSON reports expose `day_ownership` alongside raw timestamp diagnostics. No action below was executed against production by this session.

1. Review the uncommitted Phase 17.3 diff and final test results. Prepare a restored **MySQL copy** of production including period #8, assignments, lifecycle, BCH resolutions, all canonical cases/members/pairs/OCR jobs and other shared periods. Confirm the five identities and current locks before interpreting the old snapshot. Run read-only audit before repair; archive row/history/business payload and attachment checksums under operator control.
2. On that isolated copy, deploy the reviewed code using the established Laravel deployment workflow. **No new migration** or OCR/Collector worker update is needed. Run existing Repair Links for period #8 (do not regenerate/re-OCR/recompute pairs to fix assignment). Examine every diagnostic. Protected rows/cases, different populated payloads, true source overlap, invalid dates or missing final BCH must remain blocking; obtain explicit case-specific review before any manual correction.
3. Compare before/after: expected owners 85904→256/ME HLX, 85905→420/ME 10, 86442→422/TĐXD 10.4, 87577→423/TĐXD 10.4, 89197→296/TĐXD 10.4. Whole-day segments. No rewritten `machine_assignments.time_in/time_out` or lifecycle. No new machine/day row. Empty automatic siblings #98926/#99036/#99166 may disappear only with safe duplicate audit. If these remain the only duplicates, period count is expected to fall from 6046 to 6043; verify rather than forcing that count.
4. Keep all 126 truly unassigned row IDs and three NULL relationship keys unchanged (9 legitimate gap / 117 AFTER_RETURN). Compare full business payload (journal/allocated/minutes/GPS/notes/AI), source/member/pair IDs and times, attachment hashes and opaque OCR content. A relationship metadata change is expected; a photo/pair/hour change is not. Case #1633's pairing ambiguity remains visible. Do not invent missing #148 source history.
5. Run read-only `php artisan reconciliation:residual-audit 8` again and archive the JSON. Daily ownership must match expected BCH, canonical references must agree, five false segment blockers and three materialized overlap warnings must clear when safe Repair succeeds; unrelated genuine/protected blockers must remain. Inspect daily OCR and journal views, period BCH grouping, per-BCH Excel sheets and export validation on the copy. Validate ordinary transfer/return days, true gaps, same-day multiple transfers and protected records.
6. Repeat Repair on the copy: no additional row, duplicate photo, link inversion, pairing mutation or new repair audit. Run append/Sync on the copy and check occupancy/NULL/protection. Only after reviewing these concrete results should the owner separately authorize commit/PR/deployment and the specific production Repair window. Preserve a backup and coordinate concurrent writes using the existing deployment process.
7. After an authorized production deployment, collect a fresh before audit and repeat steps 3–6 under operator control. Production is VERIFIED only with those results. Rollback code through the established release workflow; it does **not** undo repaired relationships. Any data restoration must be based on saved snapshots/audits and separately reviewed to preserve later human changes.


## Cross-period consistency continuation — reviewed rollout only

No commands below have been run against production. First obtain the actual October Repair diagnostics and scoped consistency audit; a count-only summary is insufficient to classify 794 rows or explain 3084 messages. Confirm the October period ID/date bounds instead of assuming it is #9. Resolve requested asset codes to machine IDs with an operator-controlled SELECT; do not hardcode those names into services.

After separately approved code release, an operator may collect:

```sh
php artisan reconciliation:consistency-audit PERIOD_ID > consistency-before.json
php artisan reconciliation:consistency-audit PERIOD_ID --machine=30 --from=2026-10-01 --to=2026-10-31 > machine30-before.json
```

Use the same scoped command for T-XL0034 Oct1–3, T-XL0345 Oct2–3 and the actual affected days of T-XL0296 after resolving IDs. It performs only SELECTs in a snapshot transaction and validates scope bounds. Field names and row/link hashes are exported; raw business/HUMAN text, OCR JSON, attachment paths and credentials stay in the database. Inspect actual values securely in the restored database by reported ID only when differing field names require human interpretation. `duplicate_pairs` is a pair count; `blocking_by_reason` counts unique Validator messages. Map the production 794 conflict row IDs to group/pair dispositions; do not assume all duplicate pairs equal the 794 rows.

1. Obtain a consistent database backup and restore it into an isolated local MySQL checkout with production credentials/connections removed and workers stopped. Include assignments/events/BCH resolutions, all periods sharing dates, rows, canonical cases/members/intervals, OCR/attachments and audit history. Check backup restoration before repair. Do not treat APP_ENV=local alone as database isolation.
2. Collect SELECT-only before audit and operator-private full table snapshots/hashes on that copy. Run `php artisan reconciliation:repair-preview PERIOD_ID` there. This is the actual Repair writer rolled back, not a read-only SQL audit; APP_ENV=production is refused. Record proposed before/after counts and unresolved reasons. Verify every table is unchanged after preview. The command has no apply option.
3. Review A/B/C candidates and all D diagnostics. Apply existing Repair Links to **that period on the restored copy**; save before/after audits and activity log snapshots. Do not generate/re-OCR/recompute pairs to hide conflicts. Require unchanged raw assignment/events, canonical case/member/pair IDs, attachment/OCR content and business hour bundles. C only fills independent missing descriptors; conflicting or complementary unproven time/reference bundles remain review-only. Verify all 126 September normalized NULL IDs/payloads unchanged.
4. Compare Validator/UI/export for September, October and a following month. Remaining blockers must correspond to real protected/timeline/payload/canonical conflicts. Run Repair twice: second pass has no consolidation/relink or extra audit. Replay Generator/Sync: no new duplicate row/case/photo, no loss of stored hours, protected/duplicate days unchanged.
5. Rehearse data rollback on the isolated copy by restoring the consistent pre-repair backup, including rows, shared canonical cases/jobs, memberships/pairs and activity logs; compare original table hashes. Atomic Repair/dry-run rollback is regression-tested, but MySQL backup restore/concurrent writer behavior is not proven by SQLite tests. Do not reconstruct deleted rows blindly from partial logs or roll back only code. Preserve later human writes by pausing affected writes and reviewing any restoration window; never replay an old backup over newer human data without separate authorization.
6. Only after owner review and separately authorized commit/release/deployment and data-repair window, deploy via the existing Laravel procedure. No new migration or OCR/Collector worker update. Canonical preflight is part of Generator/Sync, so controlled scheduling and before backup are required before resuming those operations. Obtain fresh production before audit, authorize period-limited Repair separately, repeat preservation/idempotence/export checks and collect after audit. Stop automatic cleanup at genuine D conflicts; do not force a target row count.

If October evidence is still unavailable, report exact classifications/sample outcomes as NOT VERIFIED; deliver the reviewed read-only tool and wait for the snapshot. No blanket deletion of 794 rows is justified.
