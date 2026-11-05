# Phase18/18.1 — release gate R2 / outbound laptop worker

Kiến trúc mới thay thế hoàn toàn phương án Python/qpdf/Poppler trên hosting. Quyền lượt30/10: commit/push/cập nhật PR#71 draft; **chưa merge/deploy**, không sửa code/config/DB/cron live, không chuyển scan local đến R2 production, không gọi AI/Telegram, không sửa/restart Journal/Collector. Chỉ thực hiện các bước release dưới đây khi có quyền mới phù hợp.

## Trước release

1. Xác minh reviewed HEAD/CI/branch và working tree hosting; bảo toàn `.htaccess`, `.env`, runtime/untracked. Không lấy production code làm nguồn chuẩn. Kiểm tra PR đã được duyệt, không tự merge draft.
2. Hosting cần PHP>=8.2/CLI cùng project, pdo_mysql/fileinfo/OpenSSL/curl/mbstring/ZIP/XML và dependencies Composer theo lock. Không cần Python, qpdf, Poppler, exec/proc_open cho hồ sơ. Test xác nhận code Laravel không gọi native binary.
3. Xác minh private R2 hiện có bằng **object thử không chứa scan thật**, prefix riêng `dossiers`; quyền write/read/HEAD/private/no public URL, stream hash/size và download auth. Không đổi default disk, bucket visibility hoặc prefix ảnh Zalo. S3/R2 thật chưa được test trong lượt LOCAL.
4. PHP web/server request limits phải đủ max file nguồn50MB và output worker theo cấu hình tối đa256MB (post/multipart có dư, ví dụ upload256M/post270M), đủ temp disk, RAM/timeout cho stream hash/I/O. Chưa có bằng chứng web/FPM limits hiện tại. Không yêu cầu đủ RAM cho cả200PDF; từng HTTP một file, native xử lý tại laptop.
5. Worker laptop phải có preflight native/config/process riêng PASS, TLS Laravel/token/heartbeat/claim/upload/complete thật bằng file thử. Không mở cổng laptop, không DB từ xa cho worker. [Runbook laptop](DOSSIERS-LAPTOP-WORKER.md).

## Migration và backup

Lần đọc hosting trước kiến trúc mới: production32cfa23, PHP8.3.36, approved email người dùng khớp ID2,3migration Phase18/18.1 thiếu và không có migration ngoài phạm vi chờ. Đây là quan sát lịch sử; **phải kiểm lại migrate:status tại release**, không suy trạng thái hiện tại từ tài liệu.

Danh sách ứng viên chính xác theo thứ tự (không chạy migrate tất cả một cách mù quáng):

1. `database/migrations/2026_10_28_000001_create_electronic_dossiers.php`:10bảng hồ sơ/queue legacy và machines.dossier_group, seed catalog cấu hình; không tự tạo người/tài sản.
2. `database/migrations/2026_10_29_000001_add_dossier_pdf_processing.php`:nullable processed path/hash/metadata.
3. `database/migrations/2026_10_30_000001_create_dossier_ai.php`:run/page/proposal/rule/control, provenance/health/renewal fields, catalog XE/MÁY, AI rule seeds; không xác nhận giấy tự động.
4. **Mới** `database/migrations/2026_10_30_000002_add_dossier_remote_execution.php`:dossier_objects/worker_jobs/workers, nullable object pointers/previews/revision. Additive; không copy/delete file, không sửa phiên bản/manifest cũ. Down từ chối phá lịch sử.

Backup DB hiện tại bằng công cụ hosting phù hợp (InnoDB consistent snapshot), backup private legacy storage và cấu hình liên quan vào nơi riêng ngoài webroot/Git. Kiểm file tồn tại/size/SHA, test integrity và thử restore vào DB cách ly để xác minh. Backup R2 theo chính sách/versioning riêng nếu có dữ liệu trước; không coi SQL dump là backup bytes. Ghi bằng chứng đường dẫn riêng, không in credential hoặc commit dump. Chưa tạo backup hosting và chưa chạy4migration trong lượt này.

Sau backup và reviewed release: áp dụng chỉ các path thực sự pending theo thứ tự với PHP CLI hosting, `php artisan migrate --path=<path> --force`; không reset/fresh, không mang DB local đè hosting. Kiểm migrate:status, schema/index/FK/query, clear config cache theo runbook deployment. Nếu có file local cũ, dry-run và copy theo [runbook storage migration](ELECTRONIC-DOSSIERS.md#chuyển-dữ-liệu-local-cũ) sau khi được phép; kiểm SHA/count trước binding, giữ originals/manifest. Chỉ một process migration file tại một thời điểm.

## Cấu hình hosting

Trong `.env` riêng hosting:

```dotenv
DOSSIERS_STORAGE_DISK=r2
DOSSIERS_STORAGE_PREFIX=dossiers
DOSSIERS_WORKER_TOKEN=
DOSSIERS_ALLOWED_USER_IDS=
DOSSIERS_AI_ENABLED=false
DOSSIERS_AI_AUTO_ANALYZE=false
DOSSIERS_TELEGRAM_ENABLED=false
DOSSIERS_TELEGRAM_RECIPIENTS=
```

Điền token riêng >=32ký tự, không đưa vào chat/Git; cùng token đặt ở dossier-worker/.env. Danh sách ID phải xác minh đúng user đã được phê duyệt; lịch sử lookup tài khoản đã được phê duyệt khớp ID2 nhưng chưa phải bằng chứng Gate thật. Login bằng tài khoản đó, kiểm xem nguồn/thumbnail/AI/Excel và own-export; kiểm guest/tài khoản không có quyền403 và người khác không lấy bộ xuất. Không mở module cho mọi user đã đăng nhập.

Không cần DOSSIERS_AI_ENDPOINT/API_KEY/MODEL/PYTHON hoặc DOSSIERS_QPDF/PDFINFO/PDFTOPPM trên hosting; các giá trị AI chỉ ở laptop. Nếu cấu hình cũ tồn tại, code mới không sử dụng; không tự in/sao chép secret để chuyển môi trường. Venv hosting đã được tạo ở phiên preflight cũ không được dùng kiến trúc mới; không tự xóa nó.

## Điều phối, bật worker và nghiệm thu

- Job mới trực tiếp vào dossier_worker_jobs trong transaction, worker laptop chủ động poll. Không cần cron PDF/AI hoặc long-running Python/Laravel PDF worker trên hosting.
- Nếu còn dossier_jobs legacy, chạy `php artisan dossiers:work` một lần sau object migration để delegate; không lấy queue default/OCR. Không dùng queue:restart chung. Lịch `dossiers:work`/`dossiers:ai-work` cũ đã bỏ trong code mới; scheduler cũ của Laravel giữ nguyên cho nghiệp vụ khác và reminders (Telegram mặc định tắt).
- Operator cài/start duy nhất MMTB-DossierWorker sau review. Xác minh task/process riêng, last_seen tiến lên trên UI, one-job claim/lease renew, output staged→published, file private tải được, temp ACK cleanup. Không restart Journal/Collector.
- Pilot PDF không AI trước: upload→16thumbnail nếu dùng sample tương ứng→nhập tay/duyệt→thay version→snapshot export→mở/render→Excel. Không tuyên bố worker chạy chỉ từ schedule:list/preflight.
- AI giữ tắt tới khi người dùng điền DOSSIERS_AI_ENDPOINT/API_KEY/MODEL tại laptop và chủ động yêu cầu bật. Auto_analyze giữ false. Người dùng bấm Phân tích AI một bộ, đối chiếu evidence/subject/expiry trước xác nhận. Không gọi AI thật trong nghiệm thu LOCAL/CI; **Hoàn thiện tích hợp; chưa kiểm thử kết nối/AI thực tế**.
- Offline thử dừng duy nhất worker hồ sơ: job pending/last_seen cũ, file đã có và Excel còn dùng; khởi động lại thấy claim/complete, không làm thay Journal backlog.

Rollback phải có kế hoạch riêng: giữ schema/objects/history; code cũ yêu cầu local/PDF binaries và không hiểu object pointers nên không rollback mù về bản cũ sau khi đã nhận file mới trên R2. Tạm tắt module/worker mới, giữ dữ liệu và khôi phục reviewed compatible release nếu cần. Chưa thực hiện rollback/deploy/live verification.
