# Phase 18.1 — AI phân loại giấy tờ và đọc hạn

Status: LOCAL INTEGRATION COMPLETE / MOCK TESTS PASS; application LOCAL DB migration pending (server unavailable). Hoàn thiện tích hợp; chưa kiểm thử kết nối/AI thực tế. Branch codex/phase18-electronic-dossiers, HEAD73ff5e7. Existing Phase18 dirty/untracked preserved. Related: Phase18 / ELECTRONIC-DOSSIERS runbook; check-in AI transport in journal-worker only.

Goal: upload → queued high-resolution page extraction → cross-page grouping → private proposals/evidence → explicit human review through existing version service → unchanged PDF/Excel.

Architecture decision: reuse JournalVisionClient.request_json via separate Python CLI adapter and dossier-only queue; no journal API, mapping, assignment, backlog, canonical or Collector runtime changes. Immutable originals and qpdf fix retained. Additive run/page/proposal/rule tables and nullable version provenance; existing machine.dossier_group remains machine/vehicle. Keep legacy combined inspection catalog for historical records; add separate registration/vehicle inspection/machine inspection types.

Safety: per-page cache/content+pipeline, bounded retries, persistent pause/resume and global dossier AI lease/rate spacing; raw output only private DB; no sensitive exception strings in general logs. Suggestions never mutate confirmed metadata or select driver IDs automatically. Stale source revision requires explicit human re-review; adopting proposal idempotent under source lock. No crop export initially: multi-document page requires manual handling, never auto-duplicate.

Expiry: direct evidence first; business-rule candidate requires explicit scope acceptance. Separate health conclusion/renewal dates, versioned provenance. Legal registration-cycle inference disabled until official rules and required legal vehicle data verified; missing manufacture year never blocks OCR.

Plan/checklist:
- [x] Verify workspace/Git/current architecture/AI transport.
- [x] Additive schema, bounded pipeline, transport and field validation.
- [x] Review UI, evidence, manual/bulk selected review, retry/pause.
- [x] Mock module45tests/511assertions, full613tests/6136assertions, Python45tests PASS.
- [ ] Main local verified backup+migration: connection refused/unavailable, guard stopped before writes.
- [ ] Real PDFs/AI connection and field accuracy: explicitly deferred by user; no API calls/cost or real-file AI mutation acceptance performed.

## Final decisions / files

User steering30/10: do not call actual AI, use separate dossier endpoint/key/model, never read journal-worker/.env or laptop credentials. Python bridge imports only existing JournalVisionClient.request_json; does not change or start its workers. Configuration is DOSSIERS_AI_ENDPOINT/API_KEY/MODEL/PYTHON/ENABLED in application .env; secret passed through subprocess stdin, not command line/job payload/log/Git. Python3.12.10 with existing httpx0.28.1/pydantic2.13.5 available; no dependency installation/upgrade. New bridge mock test module only; existing Journal source unchanged.

Migration2026_10_30_000001 creates runs/pages/proposals/rules/control, nullable version provenance/health_signed_on/renewal_on and nullable type ai_key. Existing inspection combined type retained for old records; three distinct registration/vehicle inspection/machine inspection types added. Rules seeded disabled and versioned; applicable rules snapshotted at request. Legal vehicle cycle inference disabled (including rule UI); no legal validation claim.

Each unit renders original at200dpi/max3300px (reread300dpi/max4500px), never modifies source/export pixels. Separate dossiers-ai queue on existing dossier connection; one global DB lease420seconds with token fencing and >=10seconds between calls, each page/group max3attempts/backoff30/120. One image call/page then text-only grouping; cache by source content/pipeline/model/endpoint/catalog and reuse unchanged ready pages when rules change. After each call the slot enforces spacing even after a slow render; automatic uploads pause after10pilot runs. Pause retains in-flight work, stops next; retry targets one page or failed work. Durable runs re-enqueued by dossiers:ai-work; duplicate work cannot publish from expired lease. SQLite mocks verify protocol; no new native-MySQL concurrent-process claim.

Private raw responses/attempts/usage saved separately; general logs never get AI body or credentials. Failed schema still retains provided usage; missing API usage explicitly unknown, no cost estimate. Evidence dates validated strictly, asset code/frame/plate mismatch and group/date conflicts surfaced. Multiple independent papers on a page block AI adoption until manual handling (no crop export yet). Blank/duplicate exclusions remain suggestions. Insured BIC list retains page/quote and is snapshotted with scope_text in reviewed provenance, no implicit coverage/identity assignment. Existing drivers only have name/phone; exact-name candidates are hints, never selected automatically; no automatic CCCD match is claimed.

Human review locks source/proposal, checks live revision and stale acknowledgement, explicit subject/evidence/scope, calls existing saveVersion, idempotently links proposal. Unknown/permanent/month/hour precision retained; rule cannot override printed expiry or use a changed anchor. Health conclusion and separate renewal date stored independently. Manual later edits retain evidence and mark user-corrected provenance. Existing PDF/Excel use same reviewed versions/status; manifest includes provenance. Shared pages reuse original bytes and existing manifest dedupe.

Tests actually run:
- artisan test --compact --filter='Dossier(Ai)?Test':45tests/511assertions PASS (18.70s).
- artisan test --compact:613tests/6136assertions PASS (165.34s).
- Python unittest discover journal-worker/tests:45tests PASS (0.215s), including3new bridge mocks; no network.
- Pint18files / PHP syntax / git diff whitespace PASS. Static self-review only; no independent REVIEWED label.
- Initial view compile failure from mixed inline/block Blade @php directives fixed; final source rendering tests PASS. One test called current() with wrong collection argument; corrected harness to actual record contract. No expected business behavior weakened.
- Guarded LOCAL prepare script check: PDOException, stopped before backup/migration; apply branch not executed/NOT VERIFIED. Database3306/web18180 not observed running this turn; do not label user web ready until local services and migration are run. Existing source bytes/data not mutated. No actual AI PDF results/usage/cost to report.

Operational helper tools/prepare-dossier-ai-local.php requires APP_ENV local, mysql127.0.0.1:3306/mmtb, physical datadir+hostname+UUID identity, Phase18 prerequisites. --apply requires writable backup directory outside www, SQL backup complete/table inventory/trailer/size/SHA256 proof, then only this one migration. Refuses reset/fresh/other pending migrations; does not restart services. Because DB was unavailable, no application schema changes were attempted. Preserve schema/data on rollback; down intentionally refuses destructive deletion.

NEXT ACTION: start verified Laragon LOCAL MySQL, use guarded prepare script with private backup directory; fill own dossier AI config, start local web/dossier and AI-only workers, then user tests existing source1 through AI proposals/review/PDF/Excel. No calls were made with real config; accuracy/latency/usage on actual scans remain NOT VERIFIED. See ELECTRONIC-DOSSIERS runbook. No remote/production/Journal/Collector restart/Telegram. No commit/push/merge/deploy.


## Authorized release follow-up30/10

Hosting preflight stopped before deployment: qpdf/Poppler missing, Python3.12 transport deps missing. PHP8.3.36/exec/project-cwd XML+ZIP extensions verified; existing scheduler log advances. Exact3dossier migrations missing, no other earlier pending, hosting approved userID2 found; no Gate verification yet. No host backup/migration/config/code change or AI calls. Release candidate defaults AI=false/auto=false; disabled worker/no-auto upload regressions added; module47/521 and full615/6146 PASS. Linux CI tool paths supported. See hosting runbook. User authorized publication/deployment subject to gates; previous no-push statements are historical. Actual deployment and AI remain NOT VERIFIED.


Publication checkpoint30/10: implementationb9366e0, safety/CI codefa49cd4 pushed to draft PR#71; Linux dossier47/517 and Python45 plus existing regression job PASS on run40998144734. Hosting private venv prepared/import-verified; qpdf>=12.3.2 and Poppler binaries still missing, no deployment/backup/migrations/config/cron changes. Existing hosting32cfa23 retained. See release runbook for precise gate. Actual AI still NOT VERIFIED.
