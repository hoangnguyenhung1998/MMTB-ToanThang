# Phase 16.10.8 — Daily Photo OCR Hardening

- Status: COMPLETED locally — implementation, required tests, documentation and final checks PASS
- Date: 2026-09-22
- Branch: `fix/phase-16-10-8-daily-photo-ocr-hardening`
- Verified base: `68bf7d8` — production merge of Phase 16.10.7
- Dependency: Phase 16.10.7
- Related: OCR worker classification/TimeMark parser; canonical Daily Photo; backlog recovery

## Goal and scope

Recover deterministic Daily Photo time/date/machine evidence across crop and rotation outputs, prevent work intervals and invalid tokens from creating false capture-time conflicts, bound capture dates by message receipt date, and remove confidently identified hour-meter or known non-daily images from the Daily Photo pipeline without creating canonical evidence, exceptions or reconciliation rows. Existing true ambiguity, protected human state and canonical business rules remain fail-closed.

## Root causes proved before implementation

1. TimeMark OCR flattened every crop/rotation value into one set. Source, region and semantic context were lost, so work intervals could compete with a real `Tan ca`/TimeMark capture time.
2. The parser supported normal `HH:MM` and trusted-region dash time, but not the bounded single trailing `1` artifact. Vietnamese date parsing required spaces and did not support the observed controlled `Thang`/`Thanig`/`Tharig` variants.
3. Neither worker nor stored recovery removed dates later than the message's local `received_at` date.
4. The existing `UNKNOWN` classifier is already before the expensive Daily TimeMark crop pass, but it is itself a four-rotation full-image OCR pass. Therefore the new gate saves the later crop OCR work but is not a fully pre-OCR gate.
5. Known non-daily text previously returned `UNKNOWN` and became an exception. No hour-meter state existed.
6. Worker catalog matching could find compact catalog codes in whole OCR text; stored Laravel recovery could miss forms such as `MTS:VTXX0880`.

## Implemented behavior

### Image gate

- New terminal document states: `IGNORED_HOUR_METER` and `IGNORED_NON_DAILY_PHOTO`.
- Hour-meter classification requires multiple semantic markers, a counter token and an image structure score. `QUARTZ` or `HOURS` alone is insufficient.
- Known strong non-daily phrases are ignored. Uncertain images remain `UNKNOWN` and continue through the existing review path.
- Ignored images finish as `COMPLETED`, retain source attachment/raw OCR and classification provenance, and are never claimed as `DAILY_TIMEMARK`.
- Backlog cleanup reuses the same terminal states and reason vocabulary against stored OCR. It detaches any automatic canonical membership, clears automatic exception state and leaves reviewed/corrected/approved/rejected/HUMAN rows untouched.

### Time/date extraction

- Candidate evidence records rotation, crop region, accepted/discarded state and reason.
- Standard `HH:MM` remains supported. Dash and single trailing `1` repair are limited to trusted `time_date` regions.
- Work intervals and durations are excluded before capture-time aggregation; an independent `Tan ca 19:02` remains valid.
- Invalid tokens such as `54:62` are evidence-only discards and do not create conflicts.
- English month names, Vietnamese `Tháng`, compact `Thang` and bounded `Thanig`/`Tharig` variants are supported.
- Numeric dates resolve only when day/month orientation is deterministic; ambiguous values fail closed.
- Candidate dates after local `received_at` are discarded. A unique accepted past candidate may win; no missing date is invented.
- Distinct accepted capture candidates remain true conflicts. Repeated equivalent normalized candidates collapse to one value.

### Machine and stored recovery

- Exact catalog normalization remains primary and no fuzzy machine guessing was added.
- Stored recovery now scans compact whole text against the exact catalog, preserving ambiguity when multiple codes match.
- Dry-run adds ignored/recovered/mapping/materialization/conflict metrics and bounded representative samples (`--limit`, default 20).
- Execute remains explicit, uses the same analysis, 200-row locked chunks and state fingerprints, and is idempotent.

## Safety and compatibility

- Migration: none. Existing string columns hold the additive document types.
- API changes are additive: claim responses add `message.received_at`; classification accepts ignored types plus provenance; completion accepts evidence metadata.
- Deployment order must be Laravel/API first, then OCR worker update/restart. The old worker remains compatible with the new API; the new worker must not run against an old API that rejects the new types/payload.
- Collector code and restart are not affected.
- Rollback is code-only. Already ignored rows remain auditable through `daily_metadata.image_classification.previous_state`; restoring them requires a separately reviewed repair action, not an automatic rollback mutation.

## Required production-shaped cases

| Case | Expected result | Verification |
|---|---|---|
| 1 — valid Daily `06:24` | Recover capture time | Targeted parser/TimeMark coverage |
| 2 — `15:04 - 19:02`, `Tan ca 19:02` | Select `19:02`; interval/duration excluded | Python and stored-recovery feature coverage |
| 3 — `MTS:VTXX0880`, `17:011`, `20Thang9,2026` | Exact machine, `17:01`, `2026-09-20` | Stored-recovery feature coverage |
| 4 — `22:311` | Trusted repair to `22:31` | Parser coverage |
| 5 — `06:311` | Trusted repair to `06:31` | Parser coverage |
| 6 — `54:62` | Discard, never conflict/winner | Parser and TimeMark evidence coverage |
| 7 — future and past date candidates | Discard future; accepted past candidate wins | Worker and API coverage |
| 8 — ambiguous numeric date | Fail closed | Parser coverage |
| 9 — machine/date/time in separate crops | Aggregate by normalized evidence | Existing and extended TimeMark coverage |
| 10 — two real TimeMark times | Preserve true conflict | Existing regression coverage |
| 11 — confidently detected hour meter | Ignored; no canonical/reconciliation | Classifier/API/backlog coverage |
| 12 — uncertain image | Continue normal pipeline | Classifier coverage |
| 13 — protected hour-meter row | Untouched | Backlog feature coverage |
| 14 — known non-daily image | Ignored; no exception/canonical | Classifier/API/backlog coverage |

## Verification checkpoint

- Worker targeted parser/classifier/TimeMark/API/lease set: PASS — 51 tests.
- Laravel API + backlog + diagnostic targeted set: PASS — 57 tests / 432 assertions.
- Full Python suite: PASS — 56 tests in 1.93s.
- Python compile: PASS using an isolated pycache path. The default project pycache contained two Windows-locked `.pyc` targets, so compilation was rerun without modifying/deleting those runtime cache files.
- Existing 1,000-row report and recovery guards: PASS; final full-suite timings 2.66s and 1.55s, with the existing query-count assertions intact.
- Full Laravel suite: PASS — 291 tests / 1,537 assertions in 57.16s.
- Pint `--test`: PASS — 12 changed PHP files.
- PHP syntax: PASS — 12 changed PHP files.
- `git diff --check`: PASS.

## Production operations

No production command has been run. Production distribution and live-image accuracy are NOT VERIFIED. After separately authorized deployment:

1. Stop/pause or otherwise prevent OCR claims during the worker cutover.
2. Deploy Laravel/API code first; no migration is required.
3. Update and restart the OCR worker, then verify health and one controlled claim/classification/completion flow.
4. Run `php artisan ocr:daily-backlog-recover --dry-run --limit=20` with the approved filters.
5. Review ignored counts/samples, recovered date/time/mapping, true conflicts, retries and protected rows.
6. Run `--execute` only with separate production-write authorization and the same scope.

## Remaining work

- Local implementation scope is complete. Create the requested local checkpoint commit.
- Do not push, create/merge a PR, deploy, execute production recovery or restart the runtime worker without separate authorization.
