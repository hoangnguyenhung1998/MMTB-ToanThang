# Phase 16.10.4 — Automatic Daily Photo reconciliation and Zalo machine mapping

- Status: COMPLETED — implementation, tests and documentation verified locally.
- Date: 2026-09-10
- Branch: `phase16-10-exception-first`
- Verified base: `d36cfc1` — Phase 16.10.3 canonical downstream integration.
- Dependencies: [16.10.1](PHASE-16.10.1.md), [16.10.2](PHASE-16.10.2.md), [16.10.3](PHASE-16.10.3.md).
- Related: Phase 16.9 Daily Photo workflow; existing sender/driver historical compatibility.

## Goal / scope

Make Daily Photo auto-first from OCR completion through canonical membership/pairing and reconciliation, including partial evidence. Simplify Zalo sender mapping to a default machine with automatic learning and immutable resolution provenance. Export available photos without completeness/review gates. Implement, test, document and commit locally; no push, PR, merge, deployment, production backfill or runtime restart.

## Verified audit / root cause

- OCR completion already called canonical materialization and targeted reconciliation sync, but materialization required a capture time.
- `DailyPhotoSyncService` only allocated READY cases even though COLLECTING could already contain a certain interval. `caseSources()` still filtered by approved review status.
- Sync iterated existing reconciliation rows; it did not create rows for newly received evidence.
- The period's evidence column used persisted source IDs, so protected rows could not show new evidence without changing their saved provenance.
- Archive rejected any incomplete/ambiguous group and dereferenced missing endpoints.
- The sender settings required driver and effective dates, resolving machines indirectly through driver history.

## Architecture before / after

Before: OCR → canonical case/interval → READY + reviewed-source filter → existing reconciliation row; incomplete archive blocked export; sender → dated driver → dated machine.

After: OCR completion → exact image code or receipt-effective sender mapping → stored machine/date resolution → canonical evidence/intervals → automatic row creation/update → existing time allocator. Period/BCH resync can materialize eligible historical OCR. The active mapping is a direct machine; internal historical windows and stored per-evidence resolution preserve auditability.

The old driver/history tables, manual correction endpoints, legacy non-daily-mode workflow, Collector payload and OCR worker API remain compatible. No new dependency or worker process was added.

## Auto Daily Photo and partial behavior

- Successful OCR with machine/date materializes evidence without human review or confirmation.
- Known capture times keep the capture-date identity and consecutive ordering from Phase 16.10.2; arrival order never determines intervals.
- COLLECTING supplies its certain intervals. A lone point or unmatched final point never creates an invented endpoint.
- Date-only evidence has `capture_datetime = NULL`, stays unmatched and contributes `MISSING_CAPTURE_TIME`; it never receives midnight or Zalo send time as a substitute. Assignment selection requires a unique overlapping assignment for that date; multiple candidates remain ambiguous.
- Duplicate timestamps, active near-duplicates and assignment ambiguity remain fail-closed. Low-confidence OCR does not learn mapping or allocate hours.
- Case/evidence/interval IDs remain stable on unchanged recomputation. The downstream signature is versioned `canonical-v2-auto-first` so existing automatic rows refresh under the new contract.

| Input | Canonical / reconciliation result |
|---|---|
| 06:55, 11:10, 13:28, 17:31 | 2 intervals; rounded 07:00–11:00 and 13:30–17:30; HC 420 minutes, afternoon OT 60 minutes |
| 06:15, 11:10, 13:30 | COLLECTING; first interval rounds to 06:30–11:00 (270 minutes); 13:30 stays visible; afternoon endpoint and unavailable OT remain NULL |
| 06:15 | Row exists; point visible; no interval, no checkout, unavailable hours NULL |
| Machine/date but no time | Date-only membership and row when assignment is unique; no mark or calculated hours invented |
| Duplicate/ambiguous evidence | Evidence retained; no guessed interval or automatic allocation |

## Rounding / seven-hour HC / OT

`App\Services\Reconciliation\DailyTimeAllocator` remains the sole time calculation service:

- `minute()` and `round()` retain existing half-hour rounding (entry moves to next half-hour when offset exceeds ten minutes; exit floors).
- `allocate()` retains the 420-minute regular budget and overflow into afternoon OT; lunch/evening retain their separate buckets.
- `remainingRegularMinutes()` preserves the shared daily machine budget across BCH rows.
- `assertWithinAssignment()` rejects out-of-assignment/overlapping allocations.

Canonical interval classification uses the allocator-rounded entry against the existing shift boundaries. This makes 13:28 an entry at 13:30 in the afternoon bucket. No second payroll calculator was introduced. Unrepresentable disjoint intervals of the same bucket remain a surfaced allocator exception.

## Reconciliation / scoped resync

- Existing OCR completion triggers still synchronize only affected machine/date in open GENERATED/REVIEWING periods.
- Missing rows are created from canonical machine/assignment/date identity under a period lock. No duplicate is created over an orphaned legacy row requiring explicit repair.
- “Cập nhật ảnh hằng ngày” uses the existing authorized POST route and CSRF protection. It reads the open period and optional currently selected BCH, never a global sync.
- Resync locks OCR records and re-materializes only eligible evidence in that period/BCH. Unresolved historical OCR may use its own catalog code or the sender mapping effective at receipt; it does not learn a present mapping during backfill.
- Already resolved historical machines are reused unchanged, including HUMAN corrections. Review-protected OCR is not automatically corrected.
- Rows with manual provenance or REVIEWED/CONFIRMED/REJECTED status keep values, status, saved source IDs and signatures. Changed evidence sets `has_evidence_changes`.
- “Mốc ảnh ngày” reads current canonical evidence independently of saved row provenance, in capture order, including unmatched points and points on protected rows.
- Results report distinct updated machine/days, partial rows, exceptions, and protected rows skipped. Repeated unchanged sync creates no duplicate row/evidence/interval.
- No operator backfill command was added or run; the new resync service is exposed only through this scoped button. Existing scheduled canonical sync remains unchanged in scheduling/worker contract.

## Zalo mapping / exact priority / history

For new evidence:

1. Exact valid image `asset_code` (catalog uniqueness enforced by existing schema) is authority for that evidence: `IMAGE_ASSET` (equivalent to OCR_MACHINE).
2. Otherwise a unique direct sender mapping effective at Laravel message `received_at`: `SENDER_MAPPING` and mapping ID in resolution metadata.
3. Legacy sender/driver historical fallback remains available for pre-existing links: `SENDER_DRIVER_HISTORY` with link/history IDs. This is compatibility within the sender fallback, not a new heuristic.
4. Otherwise unresolved/exception. Overlapping direct mapping candidates fail closed and do not fall through to legacy history.

Existing HUMAN corrections override automatic resolution; existing stored machine resolution is retained on a no-code OCR retry. An image with another valid code belongs to that other machine without a conflict gate or replacing the sender default.

- First confident catalog OCR creates a mapping when none exists. If the sender already has a deterministic legacy mapping, its current machine is carried forward into the direct mapping instead of changing the established default to a machine sent on someone else's behalf.
- Automatic learning never replaces an established direct mapping, including historical entries.
- Manual save closes the prior internal window and starts the new one at server time. Message receipt and mapping times both use the existing server/storage clock; the UI displays Vietnamese local time. Effective windows are start-inclusive/end-exclusive at stored second precision.
- Delayed OCR of an earlier received image uses its earlier mapping. Resync never remaps a resolved historical photo to the current default.
- A sender-row lock plus a unique nullable `active_sender_id` protect concurrent writes. Activity logs preserve the source, previous mapping IDs and author.
- UI requires only sender and machine. Current direct mappings and unambiguous legacy defaults are visible; driver/from/to inputs are removed. Historical driver tables/data are preserved.

## UI removed / retained

- Removed default daily-mode review statistics/filter and bulk approve controls; Daily Photo rows show OCR/evidence state rather than review badges.
- Removed the daily image approve button; explicit “Lưu chỉnh sửa” and rejection remain for actual corrections/exceptions.
- Removed daily-mode “Lưu & xác nhận” and detail navigation from the reconciliation grid. Inline manual editing remains available.
- Archive thumbnails open the original image rather than navigating to review.
- Existing correction/detail routes remain for audit/backward compatibility and explicit correction; they are never a prerequisite for reconciliation. Non-daily-mode review workflows remain intact.

## Export

- In daily mode, READY, COLLECTING and ambiguous cases can export their available evidence; lone images and more than four images are supported.
- Missing original storage files do not block the remaining available photos. Empty canonical cases are excluded.
- Filenames include job IDs to avoid collisions for duplicate times or separate assignments. Unmatched images are named as evidence marks, without guessing start/end roles.
- `GHI-CHU-THIEU-ANH.txt` contains count-only notes, e.g. `09/09/2026 – VT-XX0823: thiếu 2 ảnh`.
- The four-photo standard is only the missing-note baseline: `max(0, 4 - exported image count)` per selected machine/day, not a canonical limit or an export gate.

## Migration / deployment / rollback

Migration: `2026_09_10_000001_create_zalo_sender_machine_mappings.php`.

- Adds direct sender-machine history with machine/user foreign keys, effective lookup index and unique active sender.
- Makes canonical evidence capture datetime nullable for date-only evidence; existing timestamps are preserved.
- No table/history deletion or mass data rewrite in `up()`. No application/local production database migration was run; tests use isolated SQLite in-memory databases.
- A later authorized deployment must apply `php artisan migrate --force` before enabling this code. Collector/OCR worker payloads and processes need no Phase-specific change or restart.
- Do not blindly roll application code back to 16.10.3 after date-only evidence has been created: that older pairing reader assumes non-null capture timestamps. Retain the nullable-aware reader or prepare a separately reviewed rollback preserving date-only evidence. Migration `down()` intentionally does not tighten the nullable column or rewrite those rows.
- Production deploy, data backfill and production verification: NOT PERFORMED.

## Tests / acceptance

- [x] Required OCR 4/3/1-mark cases, row creation and unreviewed sources.
- [x] Canonical collecting, ambiguity, repeated recomputation and existing Phase 16.10 invariants for timed evidence.
- [x] Scoped button, historical materialization, idempotency and manual/reviewed/confirmed preservation.
- [x] 2/4 export with exact note, lone/ambiguous/6-photo export and unavailable-original handling.
- [x] First auto mapping, other-machine OCR authority, missing-code fallback, manual mapping edit and delayed receipt-time resolution.
- [x] HUMAN provenance, legacy mapping compatibility, mapping ambiguity and low-confidence rejection.
- [x] Daily settings, reconciliation grid, OCR list/correction view and JavaScript with removed controls.
- [x] Final full suite, style, PHP syntax, frontend build and diff checks PASS.

Verified on 2026-09-10 with `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`:

| Check | Result |
|---|---|
| Daily Photo / Phase 16.10 / mapping / archive / exception / DailyTimeAllocator filter | 75 passed, 418 assertions, 10.93s |
| Reconciliation features + ReconciliationTimeAllocator unit | 32 passed, 116 assertions, 3.39s |
| Full Laravel suite (`artisan test --compact`) | 250 passed, 1187 assertions, 24.58s |
| Pint (`--test`, changed non-Blade PHP files) | PASS, 16 files |
| PHP syntax (`-l`, same 16 files) | PASS |
| `npm run build` | PASS, 60 modules; existing Browserslist age warning |
| OCR list inline JavaScript with absent bulk controls | PASS in Node VM |
| `git diff --check` | PASS |

New `AutomaticDailyPhotoIntegrationTest` contains 19 scenarios. Existing tests only change the intentionally replaced partial status label; substantive existing assertions remain. Formatting changes in touched PHP files follow the requested Pint check.

## Known limitations / manual checks

- Requires an existing open reconciliation period. Missing or ambiguous assignment/BCH stays an explicit exception; no period/BCH is invented.
- Partial evidence cannot establish the complete day total. Blank values are intentional, and unsupported allocator layouts remain exceptions.
- Resync is synchronous and bounded by the selected period/BCH. No production-scale performance benchmark was performed.
- Browser layout on the user's live dataset and production SQL engine remains NOT VERIFIED. Authenticated HTML rendering, JavaScript guard and frontend build are tested locally.
- Build reports an existing outdated Browserslist dataset warning; no unrelated dependency upgrade was made.

Suggested next step (not executed): user checks the local UI with representative data, then separately authorizes a GitHub/deployment checkpoint. Do not start another Phase automatically.

## Files changed

- `app/Http/Controllers/DailyPhotoController.php`
- `app/Http/Controllers/ReconciliationPeriodController.php`
- `app/Models/ZaloSenderMachineMapping.php`
- `app/Services/DailyImageArchiveService.php`
- `app/Services/DailyImageExceptionService.php`
- `app/Services/DailyPhotoCaseService.php`
- `app/Services/DailyPhotoMachineResolutionService.php`
- `app/Services/DailyPhotoPairingService.php`
- `app/Services/OcrJobService.php`
- `app/Services/Reconciliation/DailyPhotoResyncService.php`
- `app/Services/Reconciliation/DailyPhotoSyncService.php`
- `app/Services/ZaloSenderMachineService.php`
- `database/migrations/2026_09_10_000001_create_zalo_sender_machine_mappings.php`
- `docs/PHASE_INDEX.md`
- `docs/PROJECT_STATE.md`
- `docs/phases/PHASE-16.10.4.md`
- `resources/views/daily-images/index.blade.php`
- `resources/views/daily-photos/settings.blade.php`
- `resources/views/ocr-reviews/index.blade.php`
- `resources/views/ocr-reviews/show.blade.php`
- `resources/views/reconciliation/periods/show.blade.php`
- `tests/Feature/AutomaticDailyPhotoIntegrationTest.php`
- `tests/Feature/CanonicalDailyPhotoDownstreamIntegrationTest.php`
- `tests/Feature/DailyPhotoWorkflowTest.php`
