# Phase 16.11.1 — AI Rescue OCR Foundation for Manual Daily Photos

- Status: COMPLETED locally — implementation, targeted regressions and full local suites PASS
- Date: 2026-09-28
- Branch: `phase16-11-1-ai-rescue-foundation`
- Verified base: `44c785a` — production merge of Phase 16.10.10.1
- Dependencies: Phase 16.10.10, Phase 16.10.10.1
- Related: Journal Vision worker; OcrJob claim/lease; canonical Daily Photo materialization

## Goal

Add an explicitly requested AI Vision rescue layer for unresolved Manual Daily Photos after RapidOCR, without changing ingestion, replacing RapidOCR, running mass AI OCR, using OpenClaw, or weakening human/canonical protection.

## Scope and non-goals

- Add a durable, versioned AI Rescue attempt lifecycle with one active attempt per Daily Photo.
- Reuse the original stored Zalo attachment and the existing Journal Vision worker transport/provider configuration.
- Let AI classify and extract only; Laravel remains authoritative for exact machine/date/time validation, protection checks and canonical materialization.
- Preserve RapidOCR history separately from AI Rescue history and the VERIFIED OCR Case Library.
- No automatic backlog enqueue, bulk UI, Collector change, OpenClaw dependency, production command, deployment or runtime restart.

## Source audit

| Component | Current implementation | Decision |
|---|---|---|
| Daily Photo ingestion | `ZaloIngestionService` stores the uploaded object by SHA-256 and creates one `OcrJob` for a new STORED attachment | REUSE AS-IS |
| RapidOCR pipeline | `ocr-worker` claims `UNKNOWN`/`DAILY_TIMEMARK`, downloads the private original, runs staged TimeMark OCR and posts structured candidates | REUSE AS-IS |
| Manual workflow/dashboard | `OcrReviewService`, OCR review UI, Daily Image Exception Center and `DailyPhotoBacklogService` expose unresolved/protected state | REUSE WITH EXTENSION (domain entry point only in this phase) |
| Versioned manual re-OCR | `DailyPhotoManualRetryService` requeues one named version under lock and retains historical snapshots | REUSE AS-IS for RapidOCR history; AI attempts remain separate |
| OcrJob attempts/candidates/raw/rotations | `OcrJobService` owns lease/attempt fencing; current worker response contains fresh candidate metadata per run | DO NOT REUSE as AI prompt/input |
| Canonical materialization | `DailyPhotoCaseService::materialize()` creates evidence/case membership idempotently from a validated completed `OcrJob` | REUSE AS-IS |
| Protection rules | HUMAN method, reviewed timestamp, APPROVED/CORRECTED/REJECTED and canonical membership are checked in current Manual paths | REUSE WITH EXTENSION and recheck at AI apply time |
| Reconciliation worker | Separate OpenClaw CLI workflow for multi-source reconciliation | DO NOT REUSE; coupled to a different queue and prohibited for this phase |
| Existing AI Vision | `journal-worker` uses an OpenAI-compatible `/chat/completions` endpoint with base64 original image and Pydantic schema validation | REUSE WITH EXTENSION |
| Provider/model | README identifies 9router/OpenAI-compatible; exact base URL/key/model come from uncommitted `JOURNAL_VISION_*` environment values | REUSE AS-IS; exact deployed model NOT VERIFIED from repository |
| Queue/retry/timeout | Laravel durable job/lease patterns plus journal-worker retry reporting; Vision timeout defaults to 180 seconds | REUSE WITH EXTENSION via a dedicated rescue queue |
| Token/cost tracking | Existing Journal Vision client discards response usage and has no cost tracker | MISSING; persist optional provider usage, do not invent cost |
| Original image | `zalo_attachments.storage_disk/storage_path` points to the unresized uploaded object; API streams it and worker base64-encodes downloaded bytes | REUSE AS-IS |
| Prompt/schema versioning | Weekly Journal prompt is structured but not versioned for Daily Rescue | MISSING; add `daily_photo_rescue_v1` prompt/schema |

## Stale candidate audit

Status: CONFIRMED, limited to persisted machine resolution.

- A full manual re-OCR sends fresh worker raw text/candidate metadata and does not call `mergeTargetedRetryResult()` because `ocr_retry_reason` is cleared.
- Date/time and `daily_metadata.ocr_candidate_summary` are therefore derived from the new response.
- However, `DailyPhotoMachineResolutionService::resolve()` preserves the existing `machine_id` and resolution method whenever a fresh attempt has no image machine. A full manual attempt can consequently finalize with the previous attempt's machine decision.
- Minimal fix: disable persisted non-human machine reuse for a full manual re-OCR decision scope while preserving deliberate targeted-retry semantics and all HUMAN protections; add a regression test.

## Implementation checklist

- [x] Audit source and choose reuse strategy.
- [x] Fix confirmed stale persisted-machine contamination.
- [x] Add additive AI Rescue attempt migration/model.
- [x] Add request/claim/complete/fail service and API endpoints.
- [x] Add versioned Daily Rescue prompt/schema to `journal-worker`.
- [x] Apply deterministic Laravel validation and canonical/non-daily outcomes.
- [x] Add idempotency, lease/retry and protected-after-request tests.
- [x] Run targeted Laravel and worker tests.
- [x] Run broader relevant regression suites and static checks.
- [x] Review final diff and update continuity.

## Current decisions

- AI reads only the original attachment bytes. RapidOCR raw text, guesses, rotations and candidates are not exposed in the rescue claim or prompt.
- One nullable unique active key per OcrJob will enforce at most one active rescue attempt while allowing multiple terminal attempts over time.
- `capture_date` future validation compares only with the current date in `Asia/Ho_Chi_Minh`; message timestamps are not inputs.
- Missing/invalid/ambiguous Daily fields and UNKNOWN remain Manual. Obvious hour-meter and clearly non-daily classifications may be excluded only after Laravel applies the result.
- Provider/model usage is recorded when returned. No cost value is inferred because the existing infrastructure has no pricing/accounting source.

## Tests

- `php artisan test --filter='OcrJobTest|DailyPhoto|Automatic|Canonical'` → PASS, 109 tests / 715 assertions.
- `php artisan test` → PASS, 330 tests / 1,837 assertions.
- `python -m unittest discover -s tests` in `ocr-worker` → PASS, 65 tests.
- `python -m unittest discover -s tests` in `journal-worker` → PASS, 40 tests.
- `vendor/bin/pint --test` for the 13 changed PHP source/test files → PASS.
- PHP syntax checks for the changed PHP files → PASS.
- `git diff --check` → PASS.

## Implemented result

- Added `daily_photo_ai_rescue_attempts` as an additive attempt/provenance table. A nullable unique active key guarantees one active request per OcrJob while retaining terminal history.
- Added locked request, fenced claim/lease, completion and failure transitions with bounded retry. Duplicate requests return the existing active attempt.
- Added OCR-token-protected worker endpoints for claim, original-image download, completion and failure. There is deliberately no bulk/backlog enqueue endpoint or command.
- Added strict `daily_photo_rescue_v1` Pydantic schema and prompt to the existing OpenAI-compatible Journal Vision client. The prompt receives the original image only and explicitly excludes OCR text, rotations, guesses, message/sender/file metadata and fuzzy matching.
- Laravel rechecks eligibility, source SHA, human/review/canonical protection, exact catalog resolution, field ambiguity and local capture date at apply time. UNKNOWN or invalid Daily results remain Manual; explicit non-daily classifications are excluded only by Laravel.
- Valid Daily results retain RapidOCR history, record separate AI provenance/usage, update the OcrJob with `AI_VISION` / `AI_RESCUE`, and reuse canonical materialization/reconciliation sync.
- Full manual RapidOCR reprocessing no longer carries a previous non-human machine resolution into a fresh attempt; targeted retry and human protection semantics remain unchanged.

## Database / runtime / deployment

- Database change is additive and backward-compatible. A future approved deployment must run `php artisan migrate --force` after code publication.
- Journal worker code/config must be updated and restarted on the runtime laptop only in a separately approved deployment step.
- No Collector or reconciliation-worker change exists. No production command, migration, worker restart, AI request or backlog enqueue was performed.
- Provider usage/tokens are persisted when returned. Cost remains absent because no authoritative pricing/accounting source exists.

## Known limitations

- The repository verifies the 9router/OpenAI-compatible provider contract, but the exact deployed `JOURNAL_VISION_MODEL` remains environment-owned and NOT VERIFIED here.
- This foundation provides an internal service entry point and worker lifecycle only. A deliberate single-photo operator trigger, real-provider smoke test and controlled rollout belong to the next phase; mass AI OCR remains prohibited.

## Remaining / next action

Start Phase 16.11.2 only after scope approval: add a deliberate single-photo operator trigger, perform a controlled real-provider smoke test, and define rollout/observability. Do not push, deploy, migrate, run production AI OCR, restart workers, change Collector or add mass enqueue without separate authorization.
