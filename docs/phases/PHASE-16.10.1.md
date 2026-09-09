# Phase 16.10.1 — Canonical Daily Photo Foundation

- Status: VERIFIED
- Created/Implemented: 2026-09-09
- Verified: 2026-09-09
- Branch: `phase16-10-exception-first`
- Git commit: `af417bb1c7ffee88dd25266ae78e5e40aff953cc`
- Direct parent: `9f8fe11a8682229cfa9e5e9167857d2c57d14c03`

## Objective

Establish a canonical daily-photo case and deterministic machine/assignment/work-date materialization foundation for completed daily TimeMark OCR evidence.

## Dependencies

- Repository production base commit `9f8fe11`.

## Related Phases

- Phase 16.9 — existing daily-photo reconciliation/manual-review flow (`docs/phase-16-9-daily-photos.md`).
- Phase 16.10.2 — direct consumer/extension of this foundation.

## Verified Scope and Invariants

- `DailyPhotoCase` is the canonical case model with a unique scope key.
- Image asset resolution is authoritative; sender/driver history is a deterministic historical fallback, while observed asset data and resolution provenance are retained.
- Capture date/time is the source for work date and effective historical resolution; Zalo transmission time is not substituted.
- Evidence missing a resolved machine/date/time does not materialize a canonical case.
- Exactly one effective assignment produces assignment + work-date identity. Zero or multiple assignments use an explicit unresolved-assignment scope, and ambiguity is not resolved randomly.
- Materialization is idempotent; two evidence rows in the same scope share one case, while distinct assignments can produce distinct same-day cases.
- Human correction records provenance and can materialize a previously unresolved case.

## Evidence

- Commit: `af417bb` — `feat: add canonical daily photo foundation`.
- Primary code: `app/Models/DailyPhotoCase.php`, `app/Services/DailyPhotoCaseService.php`, `app/Services/DailyPhotoMachineResolutionService.php`, `app/Services/ZaloSenderDriverService.php`.
- Schema: `database/migrations/2026_09_09_000001_add_canonical_daily_photo_foundation.php`.
- Tests: `tests/Feature/CanonicalDailyPhotoFoundationTest.php` — all 10 tests passed in the full regression run on 2026-09-09.

## Changes

- Historical implementation is contained in commit `af417bb`; this continuity task made no application changes.

## Tests / Findings

- Full suite at current HEAD: `223 passed`, `1002 assertions`, `0 failed`, `17.09s`.
- Production deployment/migration of this commit: NOT VERIFIED.

## Technical Decisions

- Preserve the canonical identity, capture-date, ambiguity, provenance, and idempotency semantics in downstream work.

## Remaining Issues

- None within this verified foundation record. Downstream consumption is Phase 16.10.3 scope.

## Next Action

- Read this as a direct dependency; do not reimplement it. Continue with the Phase 16.10.3 audit described in `docs/PROJECT_STATE.md`.
