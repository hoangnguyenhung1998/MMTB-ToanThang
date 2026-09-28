# Phase 16.11.2 — Complete AI Rescue OCR + Manual Dashboard UI

- Status: COMPLETED locally — implementation and required local regression checks PASS
- Date: 2026-09-28
- Branch: `phase16-11-1-ai-rescue-foundation`
- Verified base: `44c785a` — production merge of Phase 16.10.10.1
- Dependency: Phase 16.11.1
- Related: Manual OCR Review dashboard; Journal Vision worker; canonical Daily Photo materialization

## Goal

Complete the operator-facing AI Rescue workflow for unresolved Manual Daily Photos: deliberate single-photo requests, reason-group bulk preview/confirmation, visible attempt history and bounded worker execution, while preserving all human/canonical protections and the original provider configuration from Phase 16.11.1.

## Scope and non-goals

- Add an explicit single-photo action only when the server currently considers the photo eligible.
- Add bulk selection by the existing Manual reason taxonomy, with a mandatory server preview before execution.
- Deduplicate overlapping reason groups by OcrJob/photo and recheck every item at confirmation time.
- Expose safe status, history, resolution and token-usage metrics without exposing API keys or raw provider responses.
- Keep AI Rescue opt-in. No scheduler, automatic backlog burn, real provider call or production enqueue is introduced.
- Do not change Collector, OpenClaw, reconciliation-worker, RapidOCR input, canonical protection rules or human decisions.

## Implemented workflow

### Manual dashboard

- The existing `/ocr-reviews` Daily Photo dashboard shows current Manual reason groups from `DailyPhotoExceptionReason::LABELS`.
- The browser shows the union count for selected groups, but the authoritative count comes from the server preview.
- Preview reports selected reason memberships, unique photos, eligible photos and each skip category.
- Confirmation carries an encrypted, versioned preview token with a 30-minute default TTL. It never trusts browser-supplied counts or job IDs.
- Execution reloads the preview photo set and re-evaluates eligibility. Deleted, protected, resolved, active or otherwise changed photos are safely skipped.

### Single-photo action and detail history

- An eligible Manual Daily Photo exposes one explicit AI Rescue action on its detail page.
- Active, previously resolved/non-daily, human-required, failed, protected, canonical and missing-source photos do not expose a repeat action by default.
- The detail page shows safe attempt history: status, classification, extracted fields, final resolution, provider/model, version, token usage and sanitized error.
- `raw_response`, structured provider payload and API key are never rendered.

### Idempotency and protection

- Database uniqueness still permits only one active rescue attempt for one OcrJob.
- Single and bulk paths both call `DailyPhotoAiRescueService::request()` under the existing transaction/row lock.
- Overlapping reason groups, repeated confirmation, concurrent single/bulk requests and duplicate worker callbacks do not create duplicate active work or reapply terminal results.
- Human resolution, reviewed/approved/corrected/rejected state, canonical evidence/case membership and source SHA are rechecked at apply time.
- Explicit `NON_DAILY_HOUR_METER` and `NON_DAILY_OTHER` outcomes clear Daily fields and remain auditable; UNKNOWN/ambiguous/invalid Daily results remain human-required.

### Queue fairness and provider reuse

- Daily AI Rescue continues through the existing `journal-worker` and its OpenAI-compatible Vision client.
- Base URL, API key, model, provider label and timeout remain environment-owned through `JOURNAL_VISION_API_BASE_URL`, `JOURNAL_VISION_API_KEY`, `JOURNAL_VISION_MODEL`, `JOURNAL_VISION_PROVIDER` and `JOURNAL_VISION_TIMEOUT_SECONDS`.
- Repository defaults label the provider `9router-openai-compatible`; this phase adds no second provider path.
- `DAILY_PHOTO_AI_RESCUE_MAX_CONSECUTIVE` defaults to `3`. After that many rescue jobs, the worker gives weekly journal (when enabled), handover and intake queues a priority check before claiming more rescue work.

## Server-side preview semantics

| Counter | Meaning |
|---|---|
| `matched_records` | Sum of selected reason memberships; one photo may contribute to more than one selected group. |
| `unique_photos` | Distinct OcrJob/photo IDs in the preview. |
| `eligible` / `will_queue` | Unique photos eligible at preview time. |
| `protected` | Human/review/canonical protected photos. |
| `already_resolved` | No longer a Manual Daily exception. |
| `ai_processing` | Already pending/retry/processing in AI Rescue. |
| `ai_already_resolved` | Previous rescue resolved or classified the photo as non-daily. |
| `ai_unresolved_previous` | Previous result requires a human; not automatically burned again. |
| `ai_failed_previous` | Previous terminal provider/worker failure; not automatically burned again. |
| `missing_source` | Original stored image is unavailable. |
| `other_skipped` | Deleted, stale or otherwise ineligible at recheck time. |

Preview is read-only. Confirmation can accept fewer photos than preview because protection and eligibility are deliberately evaluated again under the current database state.

## Configuration

- Laravel: `DAILY_PHOTO_AI_RESCUE_LEASE_SECONDS` (default `600`).
- Laravel: `DAILY_PHOTO_AI_RESCUE_MAX_ATTEMPTS` (default `3`).
- Laravel: `DAILY_PHOTO_AI_RESCUE_PREVIEW_TTL_MINUTES` (default `30`).
- Journal worker: `DAILY_PHOTO_AI_RESCUE_MAX_CONSECUTIVE` (default `3`).
- Existing Vision transport: `JOURNAL_VISION_API_BASE_URL`, `JOURNAL_VISION_API_KEY`, `JOURNAL_VISION_MODEL`, `JOURNAL_VISION_PROVIDER`, `JOURNAL_VISION_TIMEOUT_SECONDS`.

Only variable names and safe defaults are documented. No secret value is stored or displayed.

## Database and migrations

- Phase 16.11.2 adds no second migration. It uses the additive Phase 16.11.1 migration `2026_09_28_000001_create_daily_photo_ai_rescue_attempts_table.php`.
- The migration retains terminal history, optional provider usage/tokens and a nullable unique active key per OcrJob.
- A future approved deployment must run the migration before enabling the worker/UI flow.

## Tests

- `php artisan test --filter='DailyPhotoAiRescue'` → PASS, 19 tests / 199 assertions.
- `php artisan test` → PASS, 339 tests / 1,912 assertions.
- `python -m unittest discover -s tests -v` in `journal-worker` → PASS, 42 tests.
- `python -m unittest discover -s tests` in `ocr-worker` → PASS, 65 tests (unchanged worker regression from Phase 16.11.1).
- Scoped Pint, PHP syntax checks and `git diff --check` → PASS.

## Deployment readiness (not executed)

Prerequisites after explicit approval: publish and identify an approved Git commit; do not deploy the current uncommitted tree. `composer.lock`, `package-lock.json` and both Python requirements/packaging declarations are unchanged, so Composer, npm and Python dependency installation are not required.

The historical verified host convention records checkout `/home/mmzoxgme/repositories/MMTB-ToanThang`, PHP `/opt/alt/php83/usr/bin/php` and branch `production`. Re-verify those three values, then use:

```bash
cd /home/mmzoxgme/repositories/MMTB-ToanThang
git status --short
git fetch origin
git checkout production
git pull --ff-only origin production
git rev-parse HEAD
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

Then verify login, Manual OCR dashboard, preview, single action, private image access and no HTTP 500. No frontend build is required because no frontend dependency or compiled asset changed.

The laptop repository path is not recorded in current continuity. In an elevated PowerShell opened at the already verified runtime checkout, use:

```powershell
git status --short
git fetch origin
git checkout production
git pull --ff-only origin production
git rev-parse HEAD
Set-Location .\journal-worker
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
Stop-ScheduledTask -TaskName 'MMTB-JournalWorker'
Start-ScheduledTask -TaskName 'MMTB-JournalWorker'
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\autostart-status.ps1
```

Retain the existing `JOURNAL_VISION_*` secrets/config and optionally set `DAILY_PHOTO_AI_RESCUE_MAX_CONSECUTIVE`; its safe default is `3`. Restart only `MMTB-JournalWorker`, not Collector or unrelated workers. Only with separate authorization, run one controlled photo end to end before any broader use.

The general repository deployment runbook is still a template. If the host/laptop path, branch, PHP binary or Scheduled Task differs, stop and resolve the actual value rather than adapting these commands by assumption.

## Known limitations and rollback

- Exact deployed base URL/model/credential values remain environment-owned and are not verified by source.
- Token counts depend on provider response usage; monetary cost is not inferred because there is no authoritative pricing source.
- Dashboard aggregation currently evaluates the full Manual report and latest attempts; it is suitable for the current workflow but should be profiled before materially larger backlog scale.
- Rollback can disable operator use and revert worker/code while leaving the additive history table in place. Do not roll back the migration destructively when records may exist.

## Result / next action

Phase 16.11.2 is complete locally. No commit, push, PR, merge, migration, deployment, runtime restart, real AI request or production enqueue was performed. The next authorized action is user review of the diff followed by an explicitly approved Git/deployment sequence and a one-photo controlled smoke test.
