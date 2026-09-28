# Project State

- Updated: 2026-09-28
- Current Phase: 16.11.3 — Tối ưu Hậu kiểm OCR + Bộ lọc thống nhất.
- Status: COMPLETED locally — implementation, BEFORE/AFTER benchmark and all required local checks PASS; not committed, published or deployed.
- Branch: `phase16-11-1-ai-rescue-foundation`.
- Working HEAD: `92b0190` — Phase 16.11.2 commit; Phase 16.11.3 remains uncommitted.
- Verified production baseline: `e3f46d88e135f1aea476ad8b22ba29653a9d2d52` — production merge of Phase 16.11.2.
- Push / PR / merge / deploy / production command / production re-OCR / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- Removed full Manual backlog hydration and per-photo AI eligibility from OCR Review page open.
- AI resolution/token metrics and Manual/reason counts now use database aggregates over latest attempts/persisted read facts.
- Added one SQL-composed, backend-paginated filter model for workflow, AI history/status, exception reason, machine, sender, Zalo sent date and search.
- AI metric/reason cards are clickable and row badges expose the latest AI outcome without loading history.
- Bulk reason selection stays independent from list filters; server Preview and Confirm revalidation remain authoritative and unchanged.
- Representative 500-job/420-Manual benchmark improved from 745.31 ms to 121.19 ms; page hydration fell from 460 to 30 OcrJobs; eligibility/report calls fell from 419/1 to 0/0.
- No migration, provider/model/prompt/worker/Collector/reconciliation or production/runtime change was made.

## Verified checks

- Focused OCR Review + AI Rescue UI: PASS — 29 tests / 170 assertions.
- Full Laravel suite: PASS — 346 tests / 1,967 assertions.
- RapidOCR worker suite: PASS — 65 tests.
- Journal/AI Vision worker suite: PASS — 42 tests.
- PHP syntax, scoped Pint and `git diff --check`: PASS.
- Baseline at `44c785a` is verified by Git. Production runtime, exact environment-owned Vision model and live backlog remain NOT VERIFIED.

## NEXT ACTION

Review the Phase 16.11.3 local diff and benchmark document. With explicit approval only, commit and push this branch through the normal Git flow. Do not merge or deploy yet. If deployment is later approved, deploy the exact reviewed commit, run no new migration for this phase, clear Laravel caches as required, and verify default/combined filters plus clickable card populations before any separately authorized AI operation.

## Still prohibited

Do not push, create/merge a PR, deploy, run production commands, enqueue mass AI OCR, execute production AI Rescue, restart any worker, change Collector or introduce OpenClaw without separate authorization.
