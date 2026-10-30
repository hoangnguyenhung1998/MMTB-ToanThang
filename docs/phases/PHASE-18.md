# Phase 18 — Hồ sơ điện tử máy/xe và tài xế

Status29/10: LOCAL DB/TRIAL READY; actual two scan acceptance INCOMPLETE (files absent). Native MySQL concurrency6pairs PASS; explicit permission Gate + automatic compressed PDF1.6 normalization implemented; targeted25/392 and clean full593/6017 PASS; real T-XX0709 upload recovery16pages PASS. No deployed/live verification. Prior28/10 synthetic PASS below is historical evidence only.
Date: 2026-10-28. Branch: codex/phase18-electronic-dossiers. Base: 73ff5e7.

## 29/10 — Real upload incident T-XX0709 LOCAL PASS

Existing source1 from batch08:02:26 was found, original retained, no reupload. PDF1.6 /16pages /14727818bytes. qpdf12.3.2 actually ran successfully with warnings (exit3): missing xref-stream self entry. Previous generic isSuccessful rejection caused failed0/?; no launch/timeout/permission/encryption failure. Exact arguments/version/stdout/stderr/exit/timing/access recorded in private incident evidence outside Git/webroot. Source SHA256 remains f1146e33cc556084822e0221fa4eb2709d2cd2fa65d5c91b63f54f09ae7fb62a.

Only recognized benign warning3 can proceed to independent derivative qpdf --check exit0, every-page FPDI read, exact source/processed pdfinfo geometry and full trial export/reopen. Exit2, unknown warnings and failed output validation remain errors. UI distinguishes these causes, shows recovery checks; private technical logs filter pdfinfo personal metadata. Separate derivative preserves streams; source untouched. Same record retried through controller and actual dossier queue; ready16/16, one source, no duplicate versions. Reloaded only dossier watch worker.

Actual original/processed renders identical across16thumbnails at1000px. All16decoded image streams/dimensions match through exports; no rasterization/recompression/downsample. Full16page export and classification4page export (14,15,16,13) open/render in exact order/orientation. Very small render difference max mean0.001413/255; inspected affected page differs by1/255only along the rightmost1pixel border, while underlying image streams remain unchanged. Viewed source contact sheet and exported page. Classification drafts and export manifest were tested inside a rolled-back transaction, no invented confirmation/expiry/driver data saved; existing source remains ready for user classification. This incident acceptance does not replace the two absent requested samples or their full confirmation/replacement/Excel acceptance.

Regression failed before fix and passes after; negative exit2/unknown warning/output-check cases reject correctly, repeat source job remains idempotent. Targeted25tests/392assertions PASS; full593tests/6017assertions PASS. Static self-review only. No migration/dependency changes, production/remote/Collector/OCR/Telegram actions. Private evidence directory phase18-xx0709 is excluded from Git.

## Phase18.1 follow-up30/10

AI integration implemented and mock-tested; real API/scan accuracy deferred by user. Separate config/queue and review through existing services, no Journal/Collector runtime changes. See PHASE-18.1.md for45module/613full/Python45tests, pending main local migration and exact handoff. Phase18 source/hash/qpdf recovery remains intact.

## Goal / scope

Batch PDF sources → asset reconciliation → manual multipage classification → confirmation → immutable replacements → shared UI/Excel expiry logic → reproducible PDF manifest. Driver profiles reuse drivers/histories; multiple concurrent operators; explicit sender profile links with no OCR effects. No physical originals, recipient-specific templates, dossier OCR or legal expiry inference.

## 29/10 — Local acceptance follow-up

- [x] Verify physical main LOCAL MySQL identity, cold+SQL backup, restore counts/full-content proof.
- [x] Review and stage9additive migrations, apply on main local, retain every existing-column business value across47tables;65tables after migrations.
- [x] Automatic qpdf compatibility for compressed object/xref streams; separate processed metadata/hash/manifest, geometry checks, unchanged source. Header1.6 alone accepted. Four-page mechanism render comparison PASS; not the real samples.
- [x] Native MySQL separate-process same-subject confirmations/replacements/export races/request retry/job retry, full chain/audit/source preservation.
- [x] Reject auth-only based on actual public registration/no roles; explicit ID allowlist Gate; authorized current user chosen by human, other local account rejected; owner-only exports retained.
- [x] Local web + dedicated watch worker prepared; no global scheduler/Collector/OCR restart, no real Telegram.
- [ ] Actual `T-XX0769.pdf` and `T-3C0624.pdf`: NOT AVAILABLE. Receiving folder `storage/app/private/phase18-samples/`; no substitute acceptance from fixtures.
- [ ] Real file end-to-end classification/replacement/output open+quality/order/rotation/checksum/Excel evidence.
- [x] Fresh full regression after test-storage collision fix:591tests/5998assertions PASS,235.65s. Final targeted23/373PASS; syntax/diff PASS; all25routes auth+explicitGate.

Private SQL/cold backup and proof filenames: `local-cold-backup-manifest.json`, `local-backup-proof.json`, `staged-migration-proof.json`, `local-migration-proof.json`, `local-readiness.json`, `mysql-concurrency-proof.json` under task private `phase18-20261029`; no raw rows/SQL/credentials in Git. SQL SHA2561440599fafe07de45c5e754b12622eee4d8d56d30c897bffb6ba1c2cb28c41c1;3,235,080bytes. All47original-table contents verified after restore; migration ledger expected to grow9rows.

Native concurrency database `p18_concurrency_20261029` at own33318.12distinct process IDs/connection IDs; follower waits confirm1660ms/replace1533ms/stale export1586ms/export-first1537ms/request retry1464ms/job retry1473ms. Equal-effective confirmation rejects loser. Final1record/7versions/1export; immutable metadata/source bytes/audits/replacement chain verified; manifest remains version5 after current becomes version7. PDF reopened after duplicate jobs. Mechanical concurrency fixtures only, not sample-PDF acceptance.

PHP fake storage finding: `Storage::fake(..., ['root'=>...])` overwrites supplied root internally in this Laravel release, so two PHPUnit processes could delete each other's fixtures. Actual PID isolation now uses a named fake disk `dossiers-<PID>` then aliases it to `dossiers`. Overlapping run3fails is excluded; a clean frozen run is required. PDF mechanism tempfile must retain UploadedFile object until bytes are read; fixed without weakening expectations.

## Verified architecture / dependencies

- Laravel12/PHP8.3.30; maatwebsite/excel already present. Private local disk; auth-only was initially reused28/10, but29/10review found public registration/no role model inadequate for sensitive dossiers. Explicit fail-closed allowlist Gate now required for the whole module, with existing owner-only export restriction retained.
- Reuse AssetCodeResolver, Machine, Driver, MachineAssignment, MachineDriverHistory, ActivityLog, ZaloMessage sender IDs, database queue and scheduler.
- Legacy machine_documents/driver_documents are public mutable files with inferred validity periods: retained unchanged; never silently treat as verified Phase18 versions. Profile page links legacy history for review/import.
- Existing ZaloSenderDriverLink participates in OCR fallback. New dossier_sender_profiles stores explicit profile identity separately, does not feed OCR, mutate machine mappings, replay backlog or infer assignments.
- Current primary-driver change uses one open history; additive dossier assignment explicitly targets one history and does not close colleagues. Existing primary-driver workflow preserved.
- Fetch verified HEAD matches origin/codex/daily-backlog-background-recovery, origin/production is one merge ahead with identical tracked tree (git diff HEAD origin/production --stat empty). Documentation17.5 checkpoint was stale relative to Git. Untracked october-consistency.json and `tatus --short` preserved.
- Original two requested PDFs absent; actual T-XX0709 upload recovery is verified separately above. Synthetic fixtures remain regression only. Hosting tools availability NOT VERIFIED.

## Plan / acceptance

- [x] Survey actual Git/schema/auth/storage/queue/resolver/senders.
- [x] Additive schema, private streaming upload, independent results/checksum/retry, thumbnails.
- [x] Persisted classification, explicit subjects/pages/rotation/exclusions/sharing, version/audit.
- [x] Concurrent driver assignment and profile identity links without OCR changes.
- [x] Same status engine for UI/filter/Excel, precision boundaries and missing rows.
- [x] Ordered immutable PDF manifest and isolated queued merge.
- [x] Configurable types/reminders; empty recipient means no send; dedupe/retry.
- [x] Targeted, directly affected regression and full tests; synthetic UI/browser checks.
- [x] Runbook, final diff self-review and checkpoint with actual remaining limitations.

## Decisions / data impact

Separate dossier tables preserve source bytes and all version/export references with restrictive FKs. Add machines.dossier_group (machine default; explicit vehicle selection). Seed document types are editable suggestions, no legal mandatory defaults. Expiry day inclusive through 23:59:59.999999 local; minute exact timestamp inclusive; month only interpreted after explicit end-of-month confirmation; unknown remains review. No automatic expiry from issue/certification. Confirmed metadata immutable; corrections create reasoned replacement. Future replacement retains current until effective, latest expired/archive never falls back.

PDF current29/10: FPDI direct read including compatible1.6; automatic qpdf structural normalization for compressed xref/object streams, separate processed path/hash/options/geometry, no forced1.4/raster/downsample. Original source never rewritten; manifest captures immutable processed hash when used. Poppler thumbnails use original, pdfinfo validates page geometry across normalization. Encrypted/corrupt files rejected rather than silently decrypted/repaired. Separate queue; hosting CLI tools still require preflight. Earlier manual-PDF1.4 guidance is superseded.

## Tests / results / remaining

Targeted21tests/347assertions PASS: independent upload/invalid file/unknown+ambiguous code/dedup, real synthetic4page PDF (landscape/inherent+additional rotation/blank/two-side/multipage), original hash, invalid PDF/Poppler failure/retry, persisted drafts/unclassified/exclusion/intentional sharing, immutable current/future/archive/precision boundaries, concurrent drivers/primary switch/personal reuse, profile+legacy identity conflicts/invariance of OCR/mapping/backlog tables, exact PDF page count/Rotate/order manifest/owner auth/guest auth, UI+Excel missing/expired rows, Telegram empty/dedupe/bounded failure/uncertain delivery, subject correction and protective old delete routes, export filename at Vietnam midnight,200machine report under20queries.

Final full `PHP8.3 artisan test --compact`:589tests/5972assertions PASS again after locking-read review,228.78s. Final targeted `artisan test --filter=DossierTest`:21/347 PASS after PID-isolated storage/default PDF catalog order/seeded assignment decision coverage,12.29s. Earlier full attempt failed because source/migration were edited during run; not acceptance. Final PASS supersedes exploratory attempts. PHP syntax33files PASS plus final3files rechecked; diff check PASS;25dossier routes share auth; schedule:list worker+reminders registration PASS (no schedule:run). Existing47advisories/12packages reported by Composer audit; neither new PDF package is in advisory results. No unrelated dependency upgrades. No independent reviewer claimed.

Native isolated SQLite final schema queue outside repo/webroot:4separate thumbnail jobs and PDF merge ready; queue0/failed0; checksum preserved. Volume fixture:24pages/14,407,632bytes +22pages/13,207,157bytes,46thumbnails and24/22output pages, hashes unchanged,0queued/failed;31.194s/42MB PHP peak. These are explicitly comment-padded vector fixtures, not image-heavy scans or hosting benchmarks. Browser fixture actual login/index/machine/preview/source verified,2drivers and simultaneous missing/expired/expiring states visible, warnings/order shown, blank page excluded and multipage paper retained. Initial fixture ASSET_URL override fixed test CSS only; application .env unchanged. Hosting/MySQL/native concurrent locking/crash harness, actual samples and real Telegram NOT VERIFIED; SQLite sequential queue+idempotence tests do not prove native MySQL concurrency. Private fixtures retained outside Git/webroot; own preview server stopped at handoff.

Final self-review: request-id lookup can establish a MySQL REPEATABLE READ snapshot before waiting for source/subject locks. Record lookup/other page uses/latest confirmed version now use current locking reads, and export report current records/versions/assignments/histories use locking reads during capture. Same-subject record creation is serialized by existing subject row rather than firstOrCreate unique fallback inside an old snapshot. Already archived correction source is rejected. Targeted+full rerun PASS above. No claim that SQLite proves MySQL concurrency. Final browser interaction verifies changing subject machine#1→driver#1→machine#1 selects correct enabled option even with equal numeric IDs; UI selects option index rather than first matching value. No backend fixture changes in this browser check. Seeded Quyết định giao việc supports machine/vehicle/driver groups so assignment-specific classification works without first editing catalog.

No production DB changes. Main LOCAL migrations were explicitly authorized and completed29/10 after verified identity+backup+restore+staging; details above. No push/PR/merge/deploy or Collector/OCR restart authorized. Runbook: `docs/runbooks/ELECTRONIC-DOSSIERS.md`. Correct scope change creates new chain and archives wrong chain with reason/audit; draft changes do not prematurely remove original. Confirmed original metadata/export manifests remain immutable. Restrictive FKs plus friendly delete guards preserve source/history.

NEXT ACTION29/10: receive actual two scans at storage/app/private/phase18-samples, then run real local upload→thumbnail→classification→confirmation→replacement→merged-PDF open/render/order/rotation/clarity/checksum→Excel. Do not close real-scan acceptance from mechanism/concurrency fixtures. Main DB/local web/watch worker are running for user trial, guest redirect/login/CSS verified; own isolated33318 stopped cleanly after guarded shutdown, proof/datadir retained. No production/remote publication/Collector/OCR restart/real Telegram allowed; hosting remains out of this authorized task.
