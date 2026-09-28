# Phase 16.11.3 — Tối ưu Hậu kiểm OCR + Bộ lọc thống nhất

- Status: COMPLETED locally — implementation, benchmark và required local checks PASS
- Date: 2026-09-28
- Branch: `phase16-11-1-ai-rescue-foundation`
- Working HEAD: `92b0190` (`feat: complete daily photo AI rescue workflow`)
- Production baseline verified from Git: `e3f46d88e135f1aea476ad8b22ba29653a9d2d52`
- Dependencies: Phase 16.11.1; Phase 16.11.2
- Related: OCR Review; Manual Daily Photo backlog; AI Rescue attempt history

## Goal and boundaries

Make `/ocr-reviews` a fast, server-filtered lookup screen for RapidOCR/Manual/AI Rescue state without changing OCR extraction, AI provider/model/prompt/schema, eligibility, canonical materialization, human protection, worker retry/fairness, Bulk Preview or confirmation revalidation.

No real provider request, production data operation, migration, deployment, runtime update or worker restart was performed.

## Performance audit and root cause

`OcrReviewController::index()` previously called both the paginated list and `DailyPhotoAiRescueBatchService::dashboard()`. The list itself already used SQL pagination (`30` rows) and eager-loaded its page relations. The regression came from the new dashboard read path:

1. `dashboard()` called `DailyPhotoBacklogService::report()` and therefore hydrated/analyzed every Manual `DAILY_TIMEMARK` exception, not just the visible page.
2. It then evaluated `category()` for every never-attempted Manual photo. `category()` called the full `DailyPhotoAiRescueService::eligibilityReason()`, including source-storage existence checks.
3. Latest AI attempts were loaded as the entire attempt history, sorted and deduplicated in PHP.
4. Token totals and resolution counts were accumulated in PHP.
5. Exception groups were built by repeatedly filtering the fully analyzed Manual collection.

Audit answers:

| Question | Finding before this phase |
|---|---|
| Full Manual hydration for AI metrics | Yes. |
| Full eligibility when opening page | Yes, once for each never-attempted Manual candidate. |
| Per-photo eligibility work | Yes; not a classic SQL relation N+1, but repeated domain/storage work per photo. |
| Latest AI attempt SQL N+1 | No on the list; it was eager-loaded. Dashboard still loaded all history. |
| Canonical/protection/review/source N+1 | No SQL N+1 in the report because relations were eager-loaded, but all backlog relations were hydrated. |
| Manual report repeated/request | One full report, then its large collection was traversed repeatedly. |
| Large repeated collection scans | Yes, for groups, metrics and categorization. |
| Blade lazy-loading loop | No new relation query loop found. |
| Full AI history when latest was enough | Yes in dashboard aggregation. |
| Token aggregate in DB | No; PHP summed hydrated models. |
| Exception groups in DB | No; PHP filtered the full report. |
| More OcrJobs hydrated than displayed | Yes: 460 OcrJobs for a 30-row page in the representative run. |

The primary cause was the Phase 16.11.2 AI Rescue panel read path. Database time was small; PHP/domain/storage work over the full backlog dominated.

## BEFORE benchmark

The local MySQL schema did not contain the Phase 16.11.1 attempt table, so it was not mutated. Measurement used an isolated migrated SQLite file under `%TEMP%`, seeded once and reused unchanged for AFTER:

- 500 OcrJobs, 420 Manual exceptions, 500 Zalo messages/attachments, 10 machines.
- 8 AI attempts: 7 resolved and 1 human-required.
- Page size: 30.

| Metric | BEFORE |
|---|---:|
| End-to-end controller/view execution | 745.31 ms |
| Service/controller computation | 665.85 ms |
| Blade render | 79.46 ms |
| DB query count | 21 |
| DB query time | 15.63 ms |
| OcrJobs hydrated | 460 |
| AI attempts hydrated | 8 |
| Eligibility evaluations | 419 |
| Full Manual report calls | 1 |
| SQL relation N+1 | None observed |

This is a repeatable development benchmark, not a production latency claim.

## New read architecture

- `OcrReviewService::filteredQuery()` composes all query parameters in SQL before `paginate(30)`; only the current page is hydrated.
- The list eager-loads only page machine, source message and `latestOfMany` AI attempt. Detail/history behavior is unchanged.
- `DailyPhotoAiRescueBatchService::dashboard()` uses `COUNT`, `NOT EXISTS`, conditional `SUM` and a grouped latest-attempt subquery. It no longer calls AI eligibility or the full Manual report on page open.
- Resolution/token cards aggregate latest attempts in the database; queued/processing follows the existing `active_key = ACTIVE` lifecycle guard.
- Manual and reason card counts are database aggregates. Queryable read reasons reuse `DailyPhotoExceptionReason::LABELS` and its stored-reason normalization aliases; missing date/time also follows the persisted null fields.
- Full dynamic Manual analysis remains at the action boundary. Selecting Bulk reason checkboxes asks the server for a unique-photo count, and authoritative Preview/Confirm still run `DailyPhotoBacklogService` plus exact eligibility/revalidation.
- The daily overview is a grouped database aggregate instead of hydrating the whole selected day.
- Hidden global status counters are no longer queried in Daily Photo mode.

The filter read model and Bulk reason selection are deliberately separate. A list filter changes only what the operator is viewing. It never changes the Bulk Preview population or bypasses Preview/Confirm.

## Unified filters and semantics

All filters are query parameters, compose in one Eloquent query, survive refresh/bookmark/back-forward and pagination, and can be reset with **Xóa bộ lọc**.

| Parameter | Semantics |
|---|---|
| `workflow=manual` | `DAILY_TIMEMARK` + `EXCEPTION`. |
| `workflow=canonical` | Has `daily_photo_case_id` or canonical evidence relation. |
| `workflow=protected` | Reviewed/terminal review state or HUMAN machine resolution. |
| `workflow=reviewed` | `reviewed_at` or approved/corrected/rejected review state. |
| `ocr_source=rapidocr` / `never_ai` | No AI Rescue attempt exists; UI explicitly labels the RapidOCR choice as “OCR chính / chưa AI”. |
| `ocr_source=ai_attempted` | At least one AI Rescue attempt exists. |
| `ai_status` | Never, queued, processing, active, resolved, human-required, non-daily, failed, skipped, or failed/skipped, always against the latest attempt. |
| `reason` | Queryable normalized persisted exception reason; labels come only from `DailyPhotoExceptionReason::LABELS`. |
| `machine_id` | Exact machine foreign key. |
| `sender` | Exact sender ID or sender-name substring. |
| `date_from` / `date_to` | Zalo message `sent_at`, shown explicitly as “Ngày gửi Zalo”. |
| `q` | Numeric OcrJob ID, asset/observed asset, message ID, sender ID or sender name. |
| `document_type` | Existing document types including safe ignored outcomes. |

No separate exact “Confirmed” OcrJob state exists, so this phase does not invent one. Confirmed reconciliation-period semantics remain outside the OCR Review read model.

The default Daily Photo list retains its previous `UNKNOWN` + `DAILY_TIMEMARK` scope. AI-history/status filters include the ignored Daily outcome types as needed so non-daily card counts and clicked populations remain identical.

## Clickable metrics, reasons and observability

- Clickable AI cards: never attempted (scoped to Manual), active, resolved, non-daily, human-required and failed/skipped.
- Every visible exception reason count links to `workflow=manual&reason=...`.
- Card count and linked filtered count use the same SQL semantics and count unique OcrJobs.
- A photo can belong to more than one reason; reason counts intentionally overlap and are not summed into Manual total.
- Each row shows “Chưa AI” or the latest AI outcome badge and attempt time, and retains its detail link.
- Existing detail/history continues to expose safe status, classification, extracted fields, final resolution and time without API key, credential or raw provider payload.

## Pagination and index audit

- Backend pagination remains 30 rows and `withQueryString()` preserves all filters.
- Query plans on the representative database use existing document-type/status indexes and the AI attempt `ocr_job_id`/active uniqueness prefix. The current CASE sort uses a temporary ordering structure, but only after the filtered scope.
- Exception JSON predicates scan the bounded Manual population without hydrating it. At the current roughly 400-row backlog, measurement did not justify a generated-column/index migration.
- Existing machine/date, message sender/sent time, canonical relation and review columns were audited. No additive index demonstrated a material gain on this workload, so this phase adds no migration.

## AFTER benchmark

Same isolated database, seed, page size and measurement path as BEFORE:

| Metric | BEFORE | AFTER default |
|---|---:|---:|
| End-to-end execution | 745.31 ms | 121.19 ms |
| Service/controller computation | 665.85 ms | 59.44 ms |
| Blade render | 79.46 ms | 61.76 ms |
| DB query count | 21 | 12 |
| DB query time | 15.63 ms | 14.21 ms |
| OcrJobs hydrated | 460 | 30 |
| AI attempts hydrated | 8 | 0 on that page |
| Eligibility evaluations | 419 | 0 |
| Full Manual report calls | 1 | 0 |

Default end-to-end time decreased about 83.7%. Additional measurements:

| Scenario | Result rows/page | Execution | Queries / DB time | OcrJobs / attempts hydrated | Eligibility / report |
|---|---:|---:|---:|---:|---:|
| AI resolved | 7 / 7 | 106.35 ms | 12 / 12.85 ms | 7 / 7 | 0 / 0 |
| Missing capture time | 84 / 30 | 121.66 ms | 12 / 14.60 ms | 30 / 0 | 0 / 0 |
| Never AI + missing time + Zalo date range | 56 / 30 | 124.80 ms | 12 / 14.90 ms | 30 / 0 | 0 / 0 |

The architectural result is the important one: page-open work scales with the page plus bounded aggregate queries, not per-photo AI eligibility across the Manual backlog.

## Tests and verification

- Focused OCR Review + AI Rescue UI: PASS — 29 tests / 170 assertions.
- Full Laravel suite: PASS — 346 tests / 1,967 assertions.
- RapidOCR worker: PASS — 65 tests.
- Journal/AI Vision worker: PASS — 42 tests.
- PHP syntax for every changed PHP file: PASS.
- Scoped Pint: PASS — 8 files.
- `git diff --check`: PASS.
- No test called a real AI provider.

Coverage includes page-bounded hydration, zero dashboard eligibility/report calls, latest-only AI state, database token aggregation, lazy-loading prevention, all supported workflow/source/AI/reason/date/machine/sender/search filters, mandatory composition cases, URL pagination/reset/invalid input, card/count alignment, server-side unique Bulk selection count and read-only behavior. Existing AI Rescue tests cover single request, Preview, Confirm recheck, duplicate/overlap protection, HUMAN/canonical/non-daily behavior and history secrecy.

## Limitations and next action

- `reason` filtering is intentionally a queryable read classification based on persisted exception facts. Bulk Preview remains the authoritative dynamic diagnosis and may exclude stale/ineligible photos after full analysis; the UI does not claim a filter is an eligibility set.
- JSON exception predicates are not index-backed. Re-profile before backlog scale grows by orders of magnitude; do not add cache/generated columns without query evidence.
- Measurements are local SQLite development evidence, not production timing or production data inspection.

Phase 16.11.3 is complete locally. No commit, push, PR, merge, migration, deploy, laptop update, worker restart, real AI request or production batch was performed. Next action requires explicit approval to commit/push the reviewed branch; deployment remains a separate approval and verification step.
