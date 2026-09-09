# Phase 16.10.2 — Deterministic Daily Photo Pairing

- Status: VERIFIED
- Created/Implemented: 2026-09-09
- Verified: 2026-09-09
- Branch: `phase16-10-exception-first`
- Git commit: `c8e3796040809afa81840ed1423a3c7149e7aaa5`
- Direct parent: `af417bb1c7ffee88dd25266ae78e5e40aff953cc`

## Objective

Materialize canonical evidence memberships and deterministic capture-time intervals while retaining incomplete evidence and surfacing ambiguity instead of guessing.

## Dependencies

- Phase 16.10.1 — Canonical Daily Photo Foundation.

## Related Phases

- Phase 16.9 — existing daily-photo reconciliation/manual-review flow (`docs/phase-16-9-daily-photos.md`).
- Phase 16.10.3 — planned downstream consumer/integration audit.

## Verified Scope and Invariants

- Evidence is ordered by capture datetime and paired consecutively; arrival/job order does not determine pairs.
- One, two, three, and additional shifts are supported without a fixed interval limit.
- Odd evidence remains active and unmatched, then pairs when the next valid image arrives.
- Duplicate timestamps, active near-duplicate candidates, and ambiguous assignments block automatic pairing with explicit diagnostics rather than job-ID tie-breaking.
- Recomputing unchanged evidence is idempotent and preserves interval identities.
- Human time correction, machine/date changes, and membership moves recompute affected cases.
- Requeue detaches canonical membership and recomputes the old case.
- Pairing remains based on captured date/time when Zalo transmission occurs the next day.

## Evidence

- Commit: `c8e3796` — `feat: add deterministic daily photo pairing`.
- Primary code: `app/Models/DailyPhotoCaseEvidence.php`, `app/Models/DailyPhotoInterval.php`, `app/Services/DailyPhotoPairingService.php`, `app/Services/DailyPhotoCaseService.php`.
- Schema: `database/migrations/2026_09_09_000002_add_canonical_daily_photo_pairing.php`.
- Tests: `tests/Feature/CanonicalDailyPhotoPairingTest.php` — all 13 tests passed in the full regression run on 2026-09-09.

## Changes

- Historical implementation is contained in commit `c8e3796`; this continuity task made no application changes.

## Tests / Findings

- Full suite at current HEAD: `223 passed`, `1002 assertions`, `0 failed`, `17.09s`.
- Production deployment/migration of this commit: NOT VERIFIED.

## Technical Decisions

- Downstream work must consume or deliberately map the canonical interval/ambiguity states without reintroducing arrival-order or fixed-four-photo assumptions.

## Remaining Issues

- Downstream reconciliation/UI/exception consumption is NOT VERIFIED and belongs to Phase 16.10.3.

## Next Action

- Read this as a direct dependency; do not reimplement it. Continue with the Phase 16.10.3 audit described in `docs/PROJECT_STATE.md`.
