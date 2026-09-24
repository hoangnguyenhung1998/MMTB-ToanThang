# Project State

- Updated: 2026-09-24
- Current Phase: 16.10.9 — Daily Photo TimeMark-First OCR + Source-Aware Fallback.
- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS.
- Branch: `fix/phase-16-10-9-timemark-first-ocr`.
- Verified production base: `c53c6c8` (Phase 16.10.8 production merge).
- Current HEAD: final local checkpoint commit containing this document; resolve with `git rev-parse HEAD`.
- Push / PR / merge / deploy / production recovery / mass re-OCR / runtime restart: NOT PERFORMED and NOT AUTHORIZED.

## Current result

- Daily TimeMark recognition now starts with one 0° bottom-left relative ROI and stops immediately when machine/date/time are deterministic.
- Missing fields progress through 0° TimeMark crops, wider 0° crops and only then 180°/90°/270° fallback.
- Machine/date/time candidates retain orientation, crop, preprocessing and priority. Lower tiers supplement missing fields but cannot create conflict against resolved higher-tier evidence; true same-tier ambiguity still fails closed.
- Stored OCR raw sections and candidate metadata use the same hierarchy, allowing deterministic dry-run recovery without blind re-OCR.
- Compact English month dates such as `21Sep,2026` are supported. Time artifacts remain trusted-region-only, ambiguous artifacts are not guessed, and fuzzy machine substitution was removed.
- Protected/manual/HUMAN and hour-meter/non-daily safety rules are unchanged.

## Proven root causes

- The Phase 16.10.8 recognizer always executed 20 passes: five crops across 0°/180°/90°/270°.
- Online and stored aggregation treated every accepted candidate as equal regardless of source, allowing rotated/wide OCR noise to manufacture false conflicts.
- Compact day/month English dates were outside the parser grammar.
- EXIF transpose already exists; no OCR engine or orientation-detector replacement was needed.

## Verified checks

| Check | Result |
|---|---|
| Worker targeted parser/TimeMark/classifier/worker | PASS: 46 tests |
| Laravel API/backlog/diagnostic/asset targeted | PASS: 61 tests, 479 assertions |
| Full Python worker suite | PASS: 58 tests, 1.481s |
| Full Laravel suite | PASS: 293 tests, 1,561 assertions, 92.29s |
| 1,000-row backlog performance/query guards | PASS inside full Laravel suite |
| Python compile | PASS with isolated temporary pycache |
| PHP syntax | PASS: 5 changed PHP files |
| Pint `--test` | PASS: 5 changed PHP files |
| `git diff --check` | PASS |

Tests use mocked OCR output and SQLite in-memory databases. Live production images, production MySQL distribution, reported backlog counts and runtime CPU are NOT VERIFIED.

## Deployment impact

- Migration/backfill: NO.
- Laravel/API update: YES, before worker rollout.
- OCR worker update/restart: YES, only after approved merged code is available on the runtime laptop.
- Collector update/restart: NO.
- Production recovery: dry-run first; any `--execute` remains separately gated.

## NEXT ACTION

1. Review the local checkpoint on `fix/phase-16-10-9-timemark-first-ocr` with `git show --stat --oneline HEAD` and inspect the TimeMark/source-aware diff.
2. If review passes, explicitly authorize push/PR. Do not push, merge or deploy automatically.
3. After a separately approved deployment, update Laravel/API first, then update/restart only the OCR worker and verify health plus one clean primary-ROI Daily Photo flow.
4. Run a scoped production dry-run of `php artisan ocr:daily-backlog-recover --dry-run --limit=20`; verify the 18 protected rows remain protected and inspect recovered/true-conflict samples.
5. Authorize any production `--execute` only after accepting the same-scope dry-run. Do not mass re-OCR.
