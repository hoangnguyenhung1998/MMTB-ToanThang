# Phase 16.10.5 — Auto Recovery OCR & Exception Backlog

- Status: COMPLETED locally — implementation, regression tests and continuity documentation verified.
- Date: 2026-09-11
- Branch: `phase16-10-5-auto-recovery`
- Verified base: `8db9f8c` — merged Phase 16.10.4 hotfix.
- Dependency: [16.10.4](PHASE-16.10.4.md).
- Remote publication / merge / deployment / production recovery: NOT PERFORMED.

## Goal and scope

Resolve the production-shaped Daily TimeMark exception backlog by cause rather than opening each image: deterministic catalog normalization, receipt-effective sender fallback, one targeted OCR retry for missing fields, explicit exception reasons, sender-level pending recovery, a read-only report, and safe idempotent batch recovery. Existing allocation/reconciliation rules remain unchanged.

## Verified root causes

- Laravel previously uppercased and trimmed OCR `asset_code` and then used an exact database lookup. Benign operator separators and character spacing could not resolve.
- An OCR-looking but unknown code remained authoritative enough to block sender fallback, even though it did not identify a real machine.
- Missing capture time was accepted as a completed date-only evidence immediately; the worker contract had no field-target hint or stored initial/retry/final provenance.
- Exception reasons mixed legacy OCR codes and canonical pairing states, and the Exception Center did not show normalized causes.
- Sender settings exposed current mapping but not waiting/eligible/manual counts and had no explicit backlog action.
- No read-only operational report or idempotent recovery service existed. Existing resync is period-oriented and is not a sender/reason backlog classifier.

## Architecture before / after

Before:

`OCR full extraction → exact asset_code lookup → optional sender mapping → completed/exception → canonical materialization → reconciliation`

After:

`OCR initial extraction → canonical catalog resolver → valid image machine OR receipt-effective sender fallback → targeted one-time field retry when needed → normalized reason/final provenance → canonical materialization → unchanged reconciliation allocator`

Backlog operations use a separate read-only analysis layer shared by the CLI report, sender dashboard, preview counts and explicit recovery action. Recovery processes only `EXCEPTION` Daily TimeMark jobs, locks each candidate, chunks work, recomputes affected canonical cases once, and then syncs each open period once.

## Machine normalization and priority

Canonical key rules:

- case-insensitive;
- Unicode converted safely to ASCII where possible;
- remove whitespace and only these common separators: `-`, `.`, `_`, `/`, `:`, `;`;
- no edit-distance, OCR-confusion or other fuzzy matching in Laravel;
- match only when the canonical key identifies exactly one current `machines.asset_code`.

Resolution order:

1. Unique catalog match from OCR candidate (`IMAGE_ASSET`).
2. If candidate is missing/not found, use the direct sender mapping effective at `zalo_messages.received_at`; legacy sender-driver history remains compatible in the live resolver.
3. Otherwise exception.

A normalized catalog collision is `MACHINE_AMBIGUOUS` and does not fall through to mapping. A valid OCR machine always wins over a different sender default and never edits the default.

## Targeted OCR retry policy and provenance

- At most one automatic field retry per job.
- Unresolved machine without mapping requests `machine`.
- A resolved machine with missing date/time requests only the missing `date`/`time` fields.
- Claim API returns `retry_focus` and `prior_extraction`.
- The RapidOCR worker scans only relevant regions and sends what it reads; Laravel merges missing retry fields from the immutable initial snapshot.
- Successful retry continues canonical materialization automatically.
- Failed targeted extraction becomes `EXCEPTION` with the missing-field reason plus `OCR_RETRY_FAILED`; no date/time is invented.
- Stored provenance: `ocr_initial_extraction`, `ocr_retry_reason`, `ocr_retry_attempts`, `ocr_final_source`, plus `daily_metadata.ocr_recovery.final_chosen_result`.

## Mapping history

- Resolution remains start-inclusive/end-exclusive at message receipt time.
- Later mapping edits begin at the edit time; delayed processing of an older message keeps the mapping effective at receipt.
- First manual onboarding starts at the sender's earliest recorded message so the operator's explicit mapping can cover an existing waiting queue. Saving still does not run recovery.
- Automatic OCR learning does not replace any existing direct mapping.
- Existing frozen resolution and `HUMAN` provenance remain authoritative on retry/reprocess.

## Exception reasons

Daily-photo operational reasons are standardized and labeled:

`MACHINE_OCR_INVALID`, `MACHINE_NOT_FOUND`, `MACHINE_AMBIGUOUS`, `SENDER_MAPPING_MISSING`, `CAPTURE_TIME_MISSING`, `CAPTURE_DATE_MISSING`, `ASSIGNMENT_AMBIGUOUS`, `DUPLICATE_TIMESTAMP`, `PAIRING_AMBIGUOUS`, `OCR_RETRY_FAILED`, `LOW_CONFIDENCE`, `OTHER`.

Legacy reason strings are mapped at read time. Pairing diagnostics are converted into the same vocabulary in Exception Center without rewriting history.

## Sender dashboard and backlog recovery

The sender settings page shows sender, current mapping, exception count, auto-recoverable count, manual count and mapping time. Creating a mapping only reports the newly eligible count. The operator must press `XỬ LÝ ẢNH ĐANG CHỜ` after reviewing the displayed total/eligible/manual summary.

`DailyPhotoBacklogService` accepts sender, receipt date range, reason, BCH and project filters. It:

- uses only mapping windows effective at receipt;
- never changes reviewed/corrected/rejected/HUMAN evidence;
- directly resolves stored complete OCR fields or queues the one targeted retry;
- processes chunks of 200 and rechecks rows under lock;
- uses canonical membership upsert behavior and one batched pairing recompute;
- syncs open reconciliation periods without changing allocation rules;
- is idempotent because recovered/queued rows leave `EXCEPTION` and are not selected again.

## Read-only report

`php artisan ocr:daily-backlog-report` reports total exceptions, normalized reasons, sender breakdown, mapped/unmapped images, auto-recoverable images and manual images. Options: `--sender`, `--from`, `--to`, `--reason`, `--command-center`, `--project`. Tests compare persisted job attributes before/after the report.

## Performance safeguards

- Machine catalog is loaded once into a normalized-key index per resolver instance.
- Report/recovery eager-loads attachment/message and preloads direct mapping history by sender per 500-row analysis chunk.
- BCH/project assignment scope is loaded in one query per chunk instead of per evidence.
- Recovery chunks 200 rows, recomputes each affected case once and syncs each open period once.
- A 1,000-evidence query-count guard proves the unscoped read-only report avoids N+1 behavior.

## Manual safety

- Fail closed on ambiguity.
- No fuzzy machine choice and no invented date/time.
- No overwrite of manually reviewed/corrected/rejected or HUMAN-resolved OCR.
- Existing reconciliation rows marked manual/reviewed/confirmed remain protected by the Phase 16.10.4 sync boundary.
- No migration backfill and no production data action in this Phase implementation.

## Database / deployment / rollback

Migration `2026_09_11_000001_add_ocr_recovery_provenance_to_ocr_jobs.php` adds nullable/defaulted provenance columns and indexes only; it does not update existing rows.

Authorized rollout order later:

1. Pause the OCR worker and put the Laravel app into a controlled maintenance window.
2. Deploy the Laravel code, then immediately run `php artisan migrate --force` before serving requests or resuming OCR.
3. Update the OCR worker because targeted region scanning requires the new `retry_focus` support.
4. Resume the app, restart only the OCR worker and verify one initial/retry job lifecycle.
5. Run the read-only backlog report.
6. Let operators create/review mappings and explicitly recover selected sender backlogs.

Rollback must stop/revert the new worker before reverting Laravel. Migration rollback removes only provenance columns, but doing so loses retry audit fields; export/audit them first if production retries have occurred. Existing recovered canonical/reconciliation data is not deleted automatically.

## Tests

- [x] Normalization variants and catalog collision.
- [x] Invalid OCR + valid sender fallback.
- [x] Valid OCR different from mapping wins and mapping stays unchanged.
- [x] Missing OCR + mapping fallback; missing OCR + no mapping fail-closed after one retry.
- [x] Targeted time retry success and failure provenance.
- [x] Receipt-time mapping history regression retained.
- [x] Mapping save does not recover; explicit action is idempotent.
- [x] Reviewed/HUMAN evidence protection.
- [x] Read-only report no mutation.
- [x] 1,000-evidence query-count guard.
- [x] Worker targeted region and normalized-collision behavior.
- [x] Full Laravel regression, frontend build and final diff checks.

Latest verified evidence:

- Full Laravel suite: 264 tests, 1,275 assertions PASS.
- OCR worker `unittest`: 38 tests PASS.
- Pint `--test`: 18 changed PHP files PASS.
- PHP `-l`: 18 changed PHP files PASS.
- Python `compileall`: PASS.
- Frontend `npm run build`: PASS, 60 modules transformed; existing Browserslist data-age warning only.
- `git diff --check`: PASS.

Tests used SQLite in-memory databases. The Phase migration was not applied to the application's normal database, and no production report or recovery was run.

## Result / next action

Implementation is complete locally. Inspect the local checkpoint commit and optionally perform a representative local UI check. Wait for explicit authorization before push, merge, deploy, production migration/recovery or runtime restart; do not start a new Phase automatically.
