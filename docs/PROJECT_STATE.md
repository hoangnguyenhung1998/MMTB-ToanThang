# Project State

- Updated: 2026-10-08.
- Current Phase: 17.3 — Unassigned Relationship Completion & Production Residual Stabilization (continuation; no new Phase).
- Status: CODE COMPLETE — local only; mandatory local checks PASS; stopped for review. Production NOT independently VERIFIED.
- Verified Git at start: clean branch `phase17-3-unassigned-gap-reconciliation`, HEAD `f690ecf`, upstream `origin/phase17-3-unassigned-gap-reconciliation`, ahead/behind 0/0. Current changes are local/uncommitted; no Git write/fetch/pull.
- Production merge `6b6a4cc68c4b6a622ba13e15b883fb323d2fceac` and 6046/5825/221 residual/317 blockers/3 warnings are user-reported. Commit object unavailable locally; merge equivalence and live data NOT VERIFIED.
- Confirmed: prior gap/return rich-data policy retained stale relationships although the existing schema permits NULL; machine-global invalid-history flag poisoned unrelated periods; NULL wildcard append and automatic sync/export needed safe downstream handling.
- Implemented: stable-ID nullable UNASSIGNED row/canonical representation, protected/conflict fail-safe, scoped invalid/lifecycle diagnostics, evidence-proven boundary narrowing, aligned validator, NULL payload preservation, disjoint append, Không BCH views/export, capture-time guard for preloaded Daily Photo materialization candidates. No migration, transfer redesign or workers/provider changes.
- Final validation: full suite PASS 451 tests / 3060 assertions (29.09 s); reconciliation PASS 134 / 1194; targeted gap/17.2/performance PASS 63 / 552. Pint --test / PHP syntax 19 changed non-Blade PHP files PASS; both changed Blade pages render in authenticated tests; git diff --check PASS.
- Performance: assigned and unassigned 2400-row/1200-case/2400-photo repair preserve 147 queries / 0 row/case/OCR model hydration; long gap cleanup 23 queries. Final full run: assigned 232.89 ms, unassigned 218.89 ms, long gap 71.95 ms; timing variation and earlier paired samples are reported in Phase 17.3; production 6k+/MySQL contention NOT VERIFIED; timeout unchanged.
- Residuals: actual histories of machines 79/261, the five ambiguous rows and SGC/VT warnings absent; no fabricated data conclusions. T-XL0345 supplied source does not overlap, its mixed evidence remains manual. Protected rows/shared cases stay protected.

## NEXT ACTION

STOP for human review of the 24-file local Phase 17.3 continuation diff; implementation and mandatory local validation are complete. Reviewer should check nullable canonical scope/content invariants, boundary narrowing proof and production verification plan. Do not run any production step without a new explicit authorization. Do not start another Phase.

## Not authorized

No commit, push, PR, merge, deploy, production migration/Repair Links/batch/data changes, worker/service restart or next Phase. No provider/model/prompt/worker-contract/schema changes.
