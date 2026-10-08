# Phase 17.3 continuation — read-only residual audit

This runbook collects evidence; it does not change assignments or reconciliation rows. Use the existing production schema, under the operator's control. This session did not access production.

## Current October collection — fail-fast, after the audit fix is released

This block supersedes the abbreviated October examples below. The GitHub production ref was independently observed at `3d27a76bd8100433ac63b16b910f1913b801128c` on 2026-10-08, containing PR64/65; hosting is NOT VERIFIED. That baseline has the audit shadowing defect. An authorized operator runs this block only after a separately authorized release with the audit fix. It does not deploy code. Default-mode schema verification stops an old checkout. No new credentials, password, token or `.env` are requested.

Set the two actual absolute directory paths. The private base must already exist, be writable, not publicly served by another site, and outside the **entire project** (thus outside its webroot); the block creates a restricted evidence subdirectory there. It requires a clean hosting Git checkout on branch `production`, PHP83, MySQL/MariaDB and existing Laravel dependencies/config. A non-Git deployment, another branch, dirty checkout, missing mapping, wrong period dates or error requires operator verification, not bypassing the guard. No APP_ENV/config/source changes. MySQL `SET SESSION TRANSACTION READ ONLY` is connection-local transaction control, not a data write; every new transaction on the audit connection is read-only. A reconnect loses this setting, so the helper also verifies its connection ID before/after each command and rejects changed connections. Laravel's before-execution callback rejects non-SELECT application queries before they can execute, in addition to the DB transaction guard.

```bash
set -Eeuo pipefail
umask 077
PROJECT='/home/REPLACE_WITH_ACCOUNT/REPLACE_WITH_LARAVEL_ROOT'
PRIVATE_BASE='/home/REPLACE_WITH_ACCOUNT/private-audits'
PHP='/opt/alt/php83/usr/bin/php'
test -x "$PHP"
test -d "$PROJECT" && test -d "$PRIVATE_BASE" && test -w "$PRIVATE_BASE"
PROJECT=$(realpath -e -- "$PROJECT")
PRIVATE_BASE=$(realpath -e -- "$PRIVATE_BASE")
case "$PRIVATE_BASE/" in "$PROJECT/"*) echo 'Private output is inside project; STOP' >&2; exit 1;; esac
cd -- "$PROJECT"
test -f artisan && test -f bootstrap/app.php && test -f vendor/autoload.php
test "$(git rev-parse --show-toplevel)" = "$PROJECT"
BRANCH=$(git branch --show-current)
test "$BRANCH" = production
STATUS=$(git status --porcelain=v1)
test -z "$STATUS" || { echo 'Dirty checkout; STOP for review' >&2; exit 1; }
HEAD=$(git rev-parse --verify HEAD)
[[ "$HEAD" =~ ^[0-9a-f]{40}$ ]]
OUT=$(mktemp -d "$PRIVATE_BASE/october-audit-XXXXXXXX")
chmod 700 "$OUT"
trap 'echo "Collection failed; partial files are NOT evidence. Private output: $OUT" >&2' ERR
export MMTB_AUDIT_PROJECT="$PROJECT"
printf 'branch=%s\nobserved_sha=%s\noperator_reported_release=%s\ncollected_at_utc=%s\n' \
  "$BRANCH" "$HEAD" "$HEAD" "$(date -u +%FT%TZ)" > "$OUT/observed-release.txt"
git status --short --branch > "$OUT/git-status.txt"
"$PHP" artisan reconciliation:consistency-audit --help > "$OUT/command-help.txt" 2> "$OUT/help.stderr"
grep -q -- '--details' "$OUT/command-help.txt"

# Temporary collector lives outside webroot, never in the deployed source tree.
cat > "$OUT/collect.php" <<'PHP'
<?php
require getenv('MMTB_AUDIT_PROJECT').'/vendor/autoload.php';
$app = require getenv('MMTB_AUDIT_PROJECT').'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') { throw new RuntimeException('MySQL required'); }
foreach ([$db->getPdo(), $db->getReadPdo()] as $pdo) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
}
$db->beforeExecuting(function ($query) {
    if (! preg_match('/^select\b/i', ltrim($query))) {
        throw new RuntimeException('Non-SELECT application query; STOP');
    }
});
$connectionIds = function () use ($db): array {
    return array_map(fn ($pdo) => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), [$db->getPdo(), $db->getReadPdo()]);
};
$before = $connectionIds();
$mode = $argv[1] ?? '';
if ($mode === 'preflight') {
    $data = $db->transaction(function () use ($db): array {
        $period = $db->table('reconciliation_periods')->where('id', 9)->first(['id', 'type', 'date_from', 'date_to', 'status']);
        if (! $period || $period->type !== 'MONTHLY' || substr($period->date_from, 0, 10) !== '2026-10-01' || substr($period->date_to, 0, 10) !== '2026-10-31') {
            throw new RuntimeException('Period9 is not full October2026');
        }
        $machines = $db->table('machines')->whereIn('asset_code', ['T-XL0034', 'T-XL0345'])->orderBy('id')->get(['id', 'asset_code']);
        foreach (['T-XL0034', 'T-XL0345'] as $code) {
            if ($machines->where('asset_code', $code)->count() !== 1) { throw new RuntimeException('Asset mapping missing/ambiguous'); }
        }
        if ($db->table('machines')->whereIn('id', [16, 255])->count() !== 2) { throw new RuntimeException('Focus IDs missing'); }
        return ['period' => $period, 'asset_mappings' => $machines->all(),
            'focus_machines' => $db->table('machines')->whereIn('id', [16, 255])->get(['id', 'asset_code'])->all()];
    });
    $output = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} elseif ($mode === 'audit') {
    $args = ['period' => 9, '--from' => '2026-10-01', '--to' => '2026-10-31', '--release' => $argv[3]];
    if ($argv[2] === 'detailed') { $args['--details'] = true; }
    elseif ($argv[2] !== 'default') { throw new RuntimeException('Invalid mode'); }
    if (isset($argv[4])) {
        if (! ctype_digit($argv[4]) || (int) $argv[4] < 1) { throw new RuntimeException('Invalid machine'); }
        $args['--machine'] = (int) $argv[4];
    }
    if ($kernel->call('reconciliation:consistency-audit', $args) !== 0) { throw new RuntimeException('Audit command failed'); }
    $output = $kernel->output();
} elseif ($mode === 'provenance') {
    $data = $db->transaction(function () use ($db): array {
        $rowIds = $db->table('reconciliation_rows')->where('reconciliation_period_id', 9)->select('id');
        // Safe numeric relationship/counter projections; never full properties/description/raw OCR.
        return $db->table('activity_logs')->where('event', 'like', 'reconciliation.%')
            ->where(function ($q) use ($rowIds) {
                $q->whereIn('machine_id', function ($q) { $q->select('machine_id')->from('reconciliation_rows')->where('reconciliation_period_id', 9); })
                    ->orWhere(function ($q) use ($rowIds) { $q->where('subject_type', App\Models\ReconciliationRow::class)->whereIn('subject_id', $rowIds); })
                    ->orWhere(function ($q) { $q->where('subject_type', App\Models\ReconciliationPeriod::class)->where('subject_id', 9); });
            })->orderBy('id')->get(['id', 'event', 'machine_id', 'subject_type', 'subject_id', 'occurred_at',
                $db->raw("JSON_EXTRACT(properties, '$.old.machine_assignment_id') AS old_assignment_id"),
                $db->raw("JSON_EXTRACT(properties, '$.new.machine_assignment_id') AS new_assignment_id"),
                $db->raw("JSON_EXTRACT(properties, '$.repaired') AS repaired"),
                $db->raw("JSON_EXTRACT(properties, '$.removed') AS removed"),
                $db->raw("JSON_EXTRACT(properties, '$.normalized_unassigned') AS normalized_unassigned"),
                $db->raw("JSON_EXTRACT(properties, '$.unresolved') AS unresolved")])->all();
    });
    $output = json_encode(['reconciliation_activity' => $data, 'writer_inference' => 'UNKNOWN_UNTIL_ACTION_AND_DEPLOYMENT_CORRELATION'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown collector action'); }
if ($before !== $connectionIds()) { throw new RuntimeException('DB reconnect; read-only session not proven'); }
echo $output, PHP_EOL;
PHP

"$PHP" -l "$OUT/collect.php" > "$OUT/collector-syntax.txt"
json_ok() {
  test -s "$1"
  "$PHP" -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$1"
}
run_collect() {
  local file=$1; shift
  printf '%s start %s\n' "$(date -u +%FT%TZ)" "$file" >> "$OUT/timeline.txt"
  "$PHP" "$OUT/collect.php" "$@" > "$OUT/$file.partial" 2> "$OUT/$file.stderr"
  json_ok "$OUT/$file.partial"
  mv -- "$OUT/$file.partial" "$OUT/$file"
  printf '%s end %s\n' "$(date -u +%FT%TZ)" "$file" >> "$OUT/timeline.txt"
}
check_audit() {
  "$PHP" -r '
    $j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $schema=(int)$argv[2]; $machine=$argv[4]==="all"?null:(int)$argv[4];
    if ($j["schema_version"]!==$schema || $j["read_only"]!==true || $j["period_id"]!==9 ||
        $j["scope"]!==["machine_id"=>$machine,"from"=>"2026-10-01","to"=>"2026-10-31"]) { exit(1); }
    if ($schema===1 && (array_key_exists("evidence",$j) || array_key_exists("operator_reported_release",$j))) { exit(1); }
    if ($schema===2 && (($j["operator_reported_release"]??null)!==$argv[3] || ($j["evidence"]["mode"]??null)!=="SELECT_ONLY" || !is_array($j["evidence"]["rows"]??null))) { exit(1); }
  ' "$OUT/$1" "$2" "$HEAD" "$3"
}
run_collect preflight.json preflight
run_collect october-default.json audit default "$HEAD"
check_audit october-default.json 1 all
run_collect october-full.json audit detailed "$HEAD"
check_audit october-full.json 2 all
for MACHINE in 16 255; do
  run_collect "machine-$MACHINE.json" audit detailed "$HEAD" "$MACHINE"
  check_audit "machine-$MACHINE.json" 2 "$MACHINE"
done
for CODE in T-XL0034 T-XL0345; do
  MACHINE=$("$PHP" -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); foreach($j["asset_mappings"] as $m) { if($m["asset_code"]===$argv[2]) { echo $m["id"]; exit; } } exit(1);' "$OUT/preflight.json" "$CODE")
  [[ "$MACHINE" =~ ^[1-9][0-9]*$ ]]
  run_collect "$CODE.json" audit detailed "$HEAD" "$MACHINE"
  check_audit "$CODE.json" 2 "$MACHINE"
done
run_collect provenance.json provenance
test "$(git rev-parse HEAD)" = "$HEAD"
test "$(git branch --show-current)" = "$BRANCH"
test -z "$(git status --porcelain=v1)"
( cd -- "$OUT"; sha256sum -- *.json *.txt collect.php > SHA256SUMS; sha256sum -c SHA256SUMS )
find "$OUT" -maxdepth 1 -type f -exec chmod 600 {} +
printf 'Validated private packet: %s\n' "$OUT"
```

`--release` is the **operator label** taken from observed Git HEAD; it does not attest loaded PHP/opcache, DB contents or the original Repair release. Capture deployment logs/manifests and the original operation time separately. Full/focus queries intentionally use all October, including single-row days; machine16/255 Oct1–7 are present within these scopes. Each command uses a separate read snapshot, so concurrent writers may change data between files. Retain partial/failed files privately for diagnosis, but never interpret them as valid reports. Empty scope is valid JSON only if schema/scope checks pass; an empty output file is always failure. No Repair/Sync/Generator/replay/preview, migration or service restart appears in this block.

Minimum additional evidence: original repair result with all four counters, `diagnostics.reasons`, `diagnostics.rows`, scope and operation timestamp; actual release/deployment history at that time; retained action/activity logs. The current audit alone cannot reconstruct the historical result. The safe activity projection may be empty or lack counters; that means missing evidence, not zero repairs or proof of a writer. Morph aliases/custom action logs must be inspected securely if model-class subject names do not match. Classify per row reference: existence, machine/date/case/assignment, stored-row compatibility versus daily-owner compatibility, duplicate-day versus single-row-day, time/interval values and protection. Keep genuinely differing populated intervals in HUMAN_REVIEW; missing sources UNSAFE; no automatic winner. Historic 129/672/220/21/108 are comparison context only. **Chưa xác minh nguyên nhân production** until those diagnostics are supplied.

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


## Post-PR #64 residual investigation — SELECT-only evidence packet

Owner reports production merge `4baa0e3`: August/September clear, October 6484 rows / 6355 correct / 129 DUPLICATE_PAYLOAD_CONFLICT / 1171 blockers / 108 overlap warnings. These are reported totals, not a classified snapshot. This session has no October JSON and has not run a production command. Local HEAD is `21f458d`; merge object `4baa0e3` is absent locally. Do not infer release equivalence or repair dispositions until the production release is confirmed by an operator. No fetch/pull/deploy is required for this collection if the approved commands are already installed.

### 1. Identify period and representative machines (operator-controlled read-only client)

```sql
START TRANSACTION READ ONLY;
SELECT id, name, type, date_from, date_to, status
FROM reconciliation_periods
WHERE type = 'MONTHLY' AND date_from = '2026-10-01' AND date_to = '2026-10-31';
SELECT id, asset_code, status FROM machines
WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345')
ORDER BY id;
COMMIT;
```

Resolve the actual October `PERIOD_ID`, `ID_XL0034`, `ID_XL0345`; do not assume any value. Capture deployed commit/PHP command as release metadata without dumping `.env` or credentials. If no unique period or machine is returned, stop and clarify identity. SQL/CLI IDs here are diagnostic selection only, never hardcoded business rules.

### 2. Collect the existing SELECT-only consistency command

Replace the uppercase placeholders below with the verified integer IDs; use the hosting's established PHP binary. Save outputs in a private operator directory outside public web storage.

```sh
php artisan reconciliation:consistency-audit PERIOD_ID > october-consistency.json
php artisan reconciliation:consistency-audit PERIOD_ID --machine=ID_XL0034 --from=2026-10-01 --to=2026-10-31 > xl0034-consistency.json
php artisan reconciliation:consistency-audit PERIOD_ID --machine=ID_XL0345 --from=2026-10-01 --to=2026-10-31 > xl0345-consistency.json
php artisan reconciliation:consistency-audit PERIOD_ID --machine=16 --from=2026-10-01 --to=2026-10-07 > machine16-consistency.json
php artisan reconciliation:consistency-audit PERIOD_ID --machine=255 --from=2026-10-01 --to=2026-10-07 > machine255-consistency.json
```

Whole-month scopes for the two asset codes avoid guessing remaining affected dates. Full-period output supplies `blocking_by_reason` and warning totals; focused reports identify daily owners, siblings, differing technical/business/conflicting fields, canonical reasons, protected rows and hashes. It emits **pair** classifications, not the 129 conflict row dispositions. Reconcile the actual remaining Repair diagnostic row IDs against groups, with each conflict row counted once; do not sum pair counts as 129. Preserve the existing Repair diagnostic output that reported 129; do not rerun a writer merely to obtain diagnostics. If it is unavailable, record that limitation.

### 3. Collect selected-row residual detail

Obtain **all** representative row IDs (including siblings) in the same read-only SQL client:

```sql
START TRANSACTION READ ONLY;
SELECT r.id, r.machine_id, r.work_date, r.machine_assignment_id,
       r.command_center_id, r.status
FROM reconciliation_rows r
WHERE r.reconciliation_period_id = PERIOD_ID
  AND r.work_date BETWEEN '2026-10-01' AND '2026-10-31'
  AND r.machine_id IN (
      SELECT id FROM machines
      WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345')
  )
ORDER BY r.machine_id, r.work_date, r.id;
COMMIT;
```

Replace `ROW_ID_A`, `ROW_ID_B` with actual IDs and repeat `--row=...` for every selected representative row. An explicit list replaces the historical five September defaults:

```sh
php artisan reconciliation:residual-audit PERIOD_ID --row=ROW_ID_A --row=ROW_ID_B > october-focus-residual.json
```

This command is also SELECT-only; it includes period NULL rows automatically. It exports full assignment history, lifecycle events, daily ownership, row/sibling hour buckets and GPS times, canonical case/member/pair IDs and capture times, selected OCR relationship metadata, attachment checksums, journal references and shared protected periods. No raw OCR or note text is emitted. It contains business identifiers/times; retain it within project reviewers. Its legacy physical `contains_segment`/narrowing fields do not supersede `day_ownership`.

### 4. Supplement OCR protection/provenance (SELECT-only)

The residual command currently omits `machine_resolution_method` and `ocr_final_source`, and row reviewer/confirmation IDs. Obtain these existing fields directly without changing audit code. The following selects focus-machine OCR in October plus canonical/member/row-referenced OCR even if its stored machine/date is inconsistent:

```sql
START TRANSACTION READ ONLY;
SELECT j.id, j.machine_id, j.extracted_date, j.extracted_time,
       j.document_type, j.status, j.review_status, j.reviewed_at,
       j.machine_resolution_method, j.ocr_final_source,
       j.daily_photo_case_id, c.machine_id AS case_machine_id,
       c.work_date AS case_date, c.machine_assignment_id AS case_assignment_id,
       c.scope_key AS actual_scope_key,
       JSON_UNQUOTE(JSON_EXTRACT(j.daily_metadata, '$.case_materialization.scope_key')) AS metadata_scope_key,
       JSON_EXTRACT(j.daily_metadata, '$.case_materialization.machine_assignment_id') AS metadata_assignment_id,
       SHA2(COALESCE(j.raw_text, ''), 256) AS raw_ocr_sha256,
       SHA2(COALESCE(CAST(JSON_REMOVE(j.daily_metadata, '$.case_materialization') AS CHAR), ''), 256) AS non_relationship_metadata_sha256
FROM ocr_jobs j
LEFT JOIN daily_photo_cases c ON c.id = j.daily_photo_case_id
WHERE (
    j.machine_id IN (SELECT id FROM machines WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345'))
    AND j.extracted_date >= '2026-10-01' AND j.extracted_date < '2026-11-01'
) OR EXISTS (
    SELECT 1 FROM daily_photo_case_evidence e
    JOIN daily_photo_cases ec ON ec.id = e.daily_photo_case_id
    WHERE e.ocr_job_id = j.id AND ec.work_date BETWEEN '2026-10-01' AND '2026-10-31'
      AND ec.machine_id IN (SELECT id FROM machines WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345'))
) OR EXISTS (
    SELECT 1 FROM reconciliation_rows r
    WHERE r.reconciliation_period_id = PERIOD_ID AND r.work_date BETWEEN '2026-10-01' AND '2026-10-31'
      AND r.machine_id IN (SELECT id FROM machines WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345'))
      AND JSON_CONTAINS(COALESCE(r.daily_ocr_job_ids, JSON_ARRAY()), CAST(j.id AS CHAR), '$')
)
ORDER BY j.machine_id, j.extracted_date, j.id;

SELECT r.id, r.reconciliation_period_id, p.status AS period_status,
       r.machine_id, r.work_date, r.machine_assignment_id, r.command_center_id,
       r.status, r.manually_edited_at, r.reviewed_at, r.reviewed_by,
       r.confirmed_at, r.confirmed_by, r.daily_ocr_job_ids, r.daily_intervals,
       r.gps_check_in, r.gps_check_out, r.gps_check_in_diff_minutes, r.gps_check_out_diff_minutes
FROM reconciliation_rows r
JOIN reconciliation_periods p ON p.id = r.reconciliation_period_id
WHERE r.work_date BETWEEN '2026-10-01' AND '2026-10-31'
  AND r.machine_id IN (SELECT id FROM machines WHERE id IN (16, 255) OR asset_code IN ('T-XL0034', 'T-XL0345'))
ORDER BY r.machine_id, r.work_date, r.reconciliation_period_id, r.id;
COMMIT;
```

Use the existing production schema. If a query fails, ROLLBACK the read-only transaction and report the exact missing field/version; do not add columns or guess a result. Hash differences in opaque metadata do not alone prove business conflicts; evaluate the actual reference/date/time/protection and differing field names securely. `ocr_final_source=MANUAL`, `machine_resolution_method=HUMAN`, reviewed OCR and locked/shared rows must remain protected. The full-period audit is still needed to classify all 1171 messages, beyond these four samples.

### 5. Decision gate and acceptance

Stop at evidence collection until snapshots are supplied. For every affected machine/date, record owner assignment/BCH, each row and conflicting field, canonical scopes/member/pair IDs, row-job references versus actual job-case/metadata links, hour/GPS bundles and protection sources. Classify proven code defect, safely repairable historical relationship-only/empty/equivalent data, or genuine/unsupported/protected conflict requiring human review. A missing snapshot is UNCLASSIFIED, not evidence of a business conflict.

A later generic fix is accepted only with a production-derived sanitized regression for the confirmed defect, unchanged August/September behavior and 126 genuine NULL rows, no change to raw assignment events/time/hour/GPS/OCR/photo/pairing values, no protected/shared record mutation, one intended daily owner, no extra row/case/photo on Generator/Sync replay, second Repair with no extra changes/audits, and Validator/UI/export consistent. Genuine conflicts remain explicit blockers until reviewed. Any cleanup requires separately approved backup, restored-copy verification and a period-limited production action; no preview writer, Repair, Sync, Generator or pairing operation belongs to this read-only collection.


## October definitive-fix evidence packet (period9)

Schema1 October totals are confirmed from the supplied local JSON; its129 D pairs are not approved for merge. New `--details` adds schema2 values and actual reference-versus-stored-row / versus-day-owner proof. Default command remains SELECT-only without payload values; detailed mode remains SELECT-only and has **no apply option**. Do not invoke Repair/Sync/Resync/Replay/Generate on hosting during evidence collection.

After an explicitly approved release containing the new audit flag is available, an operator runs from the hosting Laravel root (these commands do not deploy it). Keep outputs outside public web directories, restrict file access, and supply local paths. The release argument is a separately observed operator label, not executable provenance verified by the command.

```bash
git rev-parse HEAD
php artisan reconciliation:consistency-audit --help
php artisan reconciliation:consistency-audit 9 --details --release=4baa0e3 > /private/audit/october-evidence.json
php artisan reconciliation:consistency-audit 9 --details --machine=16 --from=2026-10-01 --to=2026-10-07 > /private/audit/october-machine16.json
php artisan reconciliation:consistency-audit 9 --details --machine=255 --from=2026-10-01 --to=2026-10-07 > /private/audit/october-machine255.json
```

Replace `4baa0e3` with the actually observed deployed SHA containing the new flag; `/private/audit` is an example existing private writable operator directory, not a directory this session created. Capture command help, actual HEAD/deployment manifest, time and report checksum. If no Git checkout is on hosting, record the approved release manifest separately and leave the flag absent when unknown. Do not report PR#64 as the running new patch merely from the example argument. Before the new tool is approved/deployed, run the existing schema1 command and the preceding supplemental SELECTs; never edit hosting code directly.

Read-only SQL to establish the remaining asset mappings:

```sql
SELECT id, asset_code FROM machines WHERE asset_code IN ('T-XL0034','T-XL0345');
SELECT id, machine_id, work_date, machine_assignment_id, project_id, command_center_id,
       created_at, updated_at
FROM reconciliation_rows
WHERE reconciliation_period_id=9
  AND machine_id IN (16,255)
  AND work_date >= '2026-10-01' AND work_date < '2026-10-08'
ORDER BY machine_id, work_date, id;
```

Use each returned asset machine ID with `--machine=ID --from=2026-10-01 --to=2026-10-31`; full October is required for T-XL0034 because canonical errors must be examined on single-row days too. Schema2 reports:

- Every selected row's allocation/check-in/out/GPS time summaries and safe interval fields; omitted interval field names require secure inspection before a merge decision.
- Full assignment timestamp history and lifecycle events, actual canonical scope/status/pairing, member/photo-job IDs, canonical raw interval/endpoints, journal reference time fields, source OCR date/time/HUMAN/MANUAL/review flags and materialized relationship IDs.
- `matches_stored_row` separately from `matches_daily_owner`; missing references marked UNSAFE; reference mismatch counts by OCR/INTERVAL and daily-owner compatibility. They are comparisons per row reference, not an exact replacement for Validator's deduplicated diagnostics or normalized-NULL/lifecycle logic.
- Shared row/period protection, row/case/job creation timestamps. No opaque OCR, work/driver/notes text, source paths, images, session data or coordinates.

For creation provenance, query matching reconciliation activity logs with the existing runbook SELECTs and inspect securely, comparing created timestamps to the release/deployment timeline and Generator/Sync/Repair audit events. Timestamp chronology and hashes alone cannot name the writer. If action logs were not retained, record that origin as UNKNOWN; do not invent Generator as the cause of all129pairs.

Disposition is conservative per row: SAFE = no demonstrated impediment/candidate for existing guarded preview; HUMAN_REVIEW = any duplicate, protected scope, ownership/canonical or reference conflict; UNSAFE = missing canonical/job/interval. No write authorization follows from these labels. Map every one of129pairs to actual interval/time values and evidence sources. Map672 OCR and220 interval diagnostics separately, splitting duplicate-row versus single-row dates and owner-compatible stale materialization versus wrong machine/date/case/missing/protected source. Retain all108 warnings until source-versus-materialized overlap is established.

### Historical repair rehearsal and rollback

1. Verified database backup and an isolated restored copy are mandatory. Include reconciliation periods/rows, canonical cases/evidence/intervals, OCR jobs, journals, GPS, assignment/lifecycle/audit history. Disable all workers and external side effects on the copy. Do not redirect the production app to it or change live APP_ENV.
2. Run SELECT-only detailed audit on the copy, then the existing dry-run:

```bash
php artisan reconciliation:repair-preview 9 > /private/audit/october-repair-preview.json
```

`repair-preview` executes actual guarded writes in a transaction/savepoint then always rolls back. It is refused with APP_ENV=production. It is **not** a SELECT-only operation and must not run on hosting's live DB. Before/after row counts and repair diagnostics are included; category-D hours/intervals cannot be merged. Take reference/time/protection snapshots before and after to prove rollback, including activity logs and metadata.

3. Review proposed A/B/proven-C/relationship-only changes and every retained D. Run repeated repair on the isolated copy, compare row/case/member/job IDs, intervals, time/GPS aggregates, protected records, original handover timestamps and August/September/126NULL baselines. Existing relationship/audit transactions are atomic and idempotent; no new destructive historical writer added.
4. Obtain separate approval for any eventual production repair, with scope/backups reviewed. This session authorizes neither apply nor cleanup. Unknown or conflicting hour/evidence records require a case-specific HUMAN decision and preserved original snapshots.
5. Rollback without migration: reviewed code revert if needed. If a separately approved writer was executed, stop writers and restore verified data backup or guarded old relationships from audit snapshots. A code revert cannot recover changed data on its own. Metadata repair audit records only old/new relationship; opaque OCR and photos/pairing are preserved.

Acceptance: all newly generated days stay unique on repeated Generate/Sync/Resync/Replay; metadata-only Repair no-ops on second run; August/September,126 genuine NULL days and HUMAN/manual/reviewed/locked sources unchanged. Historical blockers must be explained and reviewed/resolved with evidence; count reduction alone is not proof. No automatic winner, dropped evidence, altered handover timestamp or disabled Validator permitted.
