# Phase18/18.1 — hosting release gate

## Authorization30/10/2026

User authorized commit/push/PR/merge and deployment AFTER review, verified hosting prerequisites and backup. No AI API call, bulk analysis, Telegram, laptop Journal/Collector changes or open ports. Keep hosting-specific files. If a prerequisite is missing, stop deployment and report a concrete remedy.

## Observed hosting preflight30/10

Through user-opened authenticated cPanel Terminal, account mmzoxgme on ghf56-22143:
- Checkout /home/mmzoxgme/repositories/MMTB-ToanThang, production32cfa23 (#70), same tracked base tree as local73ff5e7. Modified public/.htaccess (21insertions) and untracked .env backup/error logs/local ini/audit JSON present; do not add/overwrite/delete them.
- PHP /opt/alt/php83/usr/bin/php8.3.36, CLI1024M memory/max_execution_time0, proc_open and pcntl available. From actual project cwd pdo_mysql/fileinfo/mbstring/zip/gd/zlib/libxml/dom/SimpleXML/xml/xmlreader/xmlwriter/openssl/curl verified. Earlier home-cwd probe showed dom false; only project-cwd check matches cron context. CLI upload1024M/post512M; actual web/FPM limits and external process limits NOT VERIFIED.
- System Python3.12.15 initially lacked httpx/pydantic/requests/pip. Prepared NEW private /home/mmzoxgme/venvs/dossiers-ai from /opt/alt/python312/bin/python3.12 (3.12.16), using ensurepip and official PyPI only, within existing requirement bounds. Verified httpx0.28.1/pydantic2.14.2/requests2.34.2, JournalVisionClient import and pip check (no broken requirements). No API request, no global Python or Journal change. The venv is ready; it is not linked into application .env yet.
- BLOCKING: qpdf/pdfinfo/pdftoppm absent from PATH and checked /bin,/usr/bin,/usr/local/bin locations. Need AZDIGI to install/expose qpdf>=12.3.2 plus Poppler pdfinfo/pdftoppm in this account CageFS, or provide verified executable paths. Do not deploy incompatible code, use silent rasterization or expose laptop services as a workaround.
- storage/app parent writable; dossier-private directory not yet created. Laravel public path /home/mmzoxgme/repositories/MMTB-ToanThang/public. Actual domain DocumentRoot/private HTTP denial not yet verified. cPanel account quota6GB, usage1.26GB: sufficient headroom for pilot only, estimate storage growth before150–200sets.
- Existing cron every minute: cd /home/mmzoxgme/repositories/MMTB-ToanThang && /opt/alt/php83/usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1. Read-only stat observations: log grew53883700→53886241bytes and mtime advanced10:14:03→10:16:02 Asia/Ho_Chi_Minh. This proves existing scheduler activity only, NOT dossier jobs (not deployed).
- Approved account email from user resolves to existing hosting userID2. No account/password or access config changed. manage-dossiers Gate still needs actual post-deploy verification with logged-in account; ID lookup/sign-in is not Gate PASS.

## Exact pending migrations observed

Only these3Phase migrations are missing; no earlier pending migrations in existing deployed tree:
1. 2026_10_28_000001_create_electronic_dossiers:10tables including isolated queue/failure, machines.dossier_group defaultmachine, editable catalog with no mandatory legal defaults.
2. 2026_10_29_000001_add_dossier_pdf_processing: nullable derivative path/hash/method metadata, no source-byte conversion in migration.
3. 2026_10_30_000001_create_dossier_ai:5tables for runs/pages/proposals/versioned rules/global lease, nullable version provenance/health conclusion/renewal, type.ai_key,3distinct catalog types and disabled rule suggestions. Old types/versions retained.

Recheck ledger after prerequisite fix; this inventory is time-specific. Apply each reviewed migration by exact --path --force only after backups. Never migrate:fresh/reset, unqualified migrate of unknown pending changes or restore localDB over hosting. Schema rollback retains data; do not execute destructive down migrations.

## Release procedure after prerequisites PASS

1. Recheck host branch/HEAD/status, tools/exec/private storage, actual web limits, cron/process wall/RAM policy and space/quota. tools/dossier-hosting-preflight.php is a CLI-only sanitized inventory; it does not test endpoint, apply migrations, configure cron, or certify web/backup readiness. From root use actual PHP binary; --python/--qpdf/--pdfinfo/--pdftoppm accept verified paths. Never print .env or credentials.
2. Backup hosting DB (consistent SQL, using existing config without password in argv/output) and relevant storage/config/deployment files outside webroot/Git with restrictive permissions. Verify exit, size, SQL table inventory/trailer/checksum and archive listing/checksum; record whether restore was actually tested. Do not label backup complete from command start. No backup was created in failed preflight30/10; take fresh backup after dependencies are ready.
3. Verify reviewed merge SHA and fast-forward hosting checkout; preserve public/.htaccess and untracked environment files. No reset/clean/stash. Composer install from reviewed lock (no unrelated updates). Dedicated venv /home/mmzoxgme/venvs/dossiers-ai is now prepared and import-checked; recheck its packages before release, never start journal-worker main module.
4. Apply only3reviewed migrations above by exact paths. Keep .env settings specific to this hosting. Do not run tools/prepare-dossier-ai-local.php on hosting: that script intentionally refuses production.
5. Set dossier config below; clear/rebuild config/route/view caches using actual PHP as needed. Avoid global queue:restart or laptop restarts. Keep AI and auto-analysis off, recipients empty.
6. Existing minute cron should discover new dossier schedule entries; do not add duplicate schedule:run. Verify actual invocation/progress from specific dossier job/log evidence after release, not schedule:list alone. AI command returns disabled before dispatch/claim while off. No actual AI call is authorized; verify queue/scheduler wiring without provider requests. CLI bounded commands are dossiers:work and dossiers:ai-work; --watch is for a supported supervised worker only, never assume shared hosting allows a permanent daemon.
7. Verify real approved account Gate, denied account/guest access, private source/thumbnail/AI image/export downloads, synthetic PDF/queue/export and no UI500. Do not insert fake confirmed user dossiers. Only after those checks label DEPLOYED and narrow LIVE VERIFIED items separately; actual AI remains NOT VERIFIED until user explicitly initiates it.

## Hosting configuration (future; not applied during blocked preflight)

File /home/mmzoxgme/repositories/MMTB-ToanThang/.env:

```dotenv
DOSSIERS_ALLOWED_USER_IDS=2
DOSSIERS_AI_ENABLED=false
DOSSIERS_AI_AUTO_ANALYZE=false
DOSSIERS_AI_ENDPOINT=
DOSSIERS_AI_API_KEY=
DOSSIERS_AI_MODEL=
DOSSIERS_AI_PYTHON=/home/mmzoxgme/venvs/dossiers-ai/bin/python
DOSSIERS_QPDF=qpdf
DOSSIERS_PDFINFO=pdfinfo
DOSSIERS_PDFTOPPM=pdftoppm
DOSSIERS_TELEGRAM_ENABLED=false
DOSSIERS_TELEGRAM_RECIPIENTS=
```

Replace tool names with actual absolute hosting paths after installation, not Windows paths. Venv path is prepared/import-verified; PDF tool paths remain blocked. Owner fills endpoint/base /v1 if required, key/model privately, then explicitly enables DOSSIERS_AI_ENABLED=true and clicks Phân tích AI on one chosen dossier. Keep AUTO_ANALYZE=false to prevent uploads starting paid work. Filling credentials alone does not enable AI. No key in chat/log/Git. Endpoint must be reachable from hosting; localhost/laptop-only addresses are not reachable and no port/tunnel will be opened automatically. DNS/TLS/egress/credential/model and actual AI accuracy/usage remain unverified until configured/testing authorized.

Expected module URL after deployment: https://mmtb.congtytoanthang.top/dossiers. Not deployed during blocked preflight; do not call this a working new module URL yet.


## Publication / CI

Implementation b9366e0 was committed/pushed to codex/phase18-electronic-dossiers, draft PR #71 targets production. No merge while host gate blocked. CI original daily-photo job PASS; first new Linux dossier job failed2qpdf warning tests on qpdf11.9.0, while Windows qpdf12.3.2 local module/full suites pass. Follow-up0a0ba15 captures only synthetic-fixture tool diagnostics to investigate exact warning wording; it does not weaken output validation. Check final PR checks rather than assuming Linux PASS.


Linux CI diagnostics confirmed qpdf11.9.0 reports only generic "reported number of objects (7) is not one plus the highest object number (5)" for the minimal missing-self-entry fixture. That warning does not identify a benign self-entry issue and remains rejected by unchanged production whitelist. Tests now assert fail-closed behavior for native11.9, keep actual recovery assertions on12.3.2, and isolate the output-check fault using a mocked clean normalization result. This is not generic warning recovery or reduced validation. Hosting warning-recovery qualification explicitly requires qpdf>=12.3.2; no lower-version actual-scan recovery is claimed. Windows module rerun47/521 PASS25.12s; await latest Linux checks.
