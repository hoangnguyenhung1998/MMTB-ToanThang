# Hồ sơ điện tử — kiến trúc R2 / Laravel / worker hồ sơ

Áp dụng cho bản sửa PR #71 ngày30/10/2026. Thay thế hướng dẫn cũ chạy PDF/Python trên hosting. Trạng thái: LOCAL; PR draft; chưa deploy, chưa gọi AI thật.

## Phân chia trách nhiệm

- Laravel nhận từng PDF, resolve bằng AssetCodeResolver hiện có, lưu nguyên bytes vào private disk `r2`, prefix `dossiers`. Registry `dossier_objects` giữ disk/key/SHA256/bytes/MIME và references. Không đổi disk mặc định hoặc prefix Zalo.
- Laravel giữ Machine/Driver/phân công/sender, giấy/phiên bản/audit, tính hạn Asia/Ho_Chi_Minh, đề xuất và duyệt, manifest cố định, Excel. Gate `manage-dossiers` cộng auth; danh sách ID trống từ chối tất cả. Bộ xuất chỉ người yêu cầu được xem/tải/retry.
- `dossier-worker/worker.py` là process outbound riêng. Tải/trả file qua API bearer riêng, không có R2 key hoặc DB credential. Chạy qpdf/Poppler/pypdf và dùng lại JournalVisionClient.request_json; không load Journal .env hoặc chạy Journal worker.
- Hosting không dùng Python/qpdf/Poppler hoặc Storage::path() cho hồ sơ. Cú pháp/render PDF được kiểm tra tại worker tin cậy; Laravel kiểm tra claim, pipeline, input hash, schema, slots, MIME/dung lượng/hash và manifest. Bearer worker là quyền dịch vụ nhạy cảm: giới hạn người đọc file config, dùng HTTPS và rotate riêng.

## Cấu hình

Hosting `.env`: `DOSSIERS_STORAGE_DISK=r2`, `DOSSIERS_STORAGE_PREFIX=dossiers`, `DOSSIERS_WORKER_TOKEN` (ngẫu nhiên tối thiểu32ký tự), `DOSSIERS_ALLOWED_USER_IDS` (xác minh ID thực tế), `DOSSIERS_AI_ENABLED=false`, `DOSSIERS_AI_AUTO_ANALYZE=false`, Telegram false và recipients trống. Tái sử dụng các biến R2 hiện có, bucket private. Không đặt AI endpoint/key/model trên hosting.

Laptop `dossier-worker/.env`: xem [runbook worker](DOSSIERS-LAPTOP-WORKER.md). `DOSSIERS_API_URL` trỏ `/api/dossiers/v1`; token cùng hosting nhưng độc lập OCR/AI. `DOSSIERS_AI_ENDPOINT`, `DOSSIERS_AI_API_KEY`, `DOSSIERS_AI_MODEL` chỉ ở file này. Thiếu cấu hình không nhận việc AI. Không ghi secret vào chat/log/Git.

LOCAL không được dùng R2 production để test. Có thể chọn private local test disk riêng qua cấu hình; test sử dụng disk giống R2 với path() ném exception. Điều này chứng minh stream contract, chưa chứng minh S3/R2 thực tế.

## Vòng đời và vận hành

`upload → dossier_worker_jobs.pending → claim → download → renew → upload staged → complete → published`. Job mới ghi trong transaction nghiệp vụ, không cần cron/queue PDF trên hosting. Các lớp Laravel job cũ chỉ delegate vào remote queue; `php artisan dossiers:work` dùng một lần để drain bản ghi queue cũ khi nâng cấp, không chạy binary. `dossiers:ai-work` chỉ reconcile lịch AI, không gọi API AI.

Global concurrency hồ sơ1; lease180giây, renew mỗi60giây, lifetime tối đa3600giây. Khóa row điều phối serialize claim; worker_id + claim UUID + attempt ràng buộc mọi API file/result. Hết lease nhận lại với token/attempt mới; worker cũ bị409. Retry tối đa3lần, backoff30/120giây. Upload cùng slot/attempt phải cùng bytes/hash; complete lặp phải cùng digest. Không ghi đè object đã công bố. Worker có durable outbox + ACK từng slot, phục hồi crash sau upload và retry complete; dọn bản tải tạm sau ACK. Không dọn nguồn/version/manifest trên R2.

AI đọc từng trang rồi nhóm bằng text; spacing ít nhất10giây sau complete. Rule/schema/expiry vẫn ở Laravel. Raw structured response/usage lưu DB riêng, kể cả schema bị từ chối; request lặp cùng response không cộng usage trùng. Pause dừng xếp tiếp sau job đang chạy. Reread tăng page revision, token cũ không được publish. Đề xuất về muộn không sửa giấy: review kiểm tra source_revision và người dùng phải xác nhận stale. Không tự liên kết tài xế/mapping/phân công.

Offline: UI báo chờ worker và last_seen; queue giữ pending. File đã công bố vẫn tải từ storage, Excel/cảnh báo hạn vẫn chạy. Không giữ tab trình duyệt. PDF mới chờ worker.

Giới hạn pilot:50MB/file nguồn,300trang/nguồn hoặc bộ xuất,256MB tổng input/bộ xuất (gồm cả original/processed),256MB/output. AI tối đa60trang/bộ; tự xếp AI tắt. Không chạy hàng loạt khi chưa pilot và kiểm tra quota.

## PDF và chất lượng

Worker giữ original SHA/bytes; qpdf tạo derivative riêng bằng `--object-streams=disable --stream-data=preserve --deterministic-id`, không raster hóa/downsample/ép1.4. qpdf>=12.3.2 cần cho chẩn đoán đã xác minh. Exit2 bị từ chối; exit3 chỉ chấp nhận đúng warning thiếu xref stream self-entry, không chấp nhận warning lạ. Derivative phải qpdf --check exit0, pypdf đọc mọi trang, Poppler boxes/count/rotation trước/sau giống nhau; xuất thử/mở lại đủ trang. Không tự giải mã.

Thumbnail tối đa1000px; AI ảnh riêng200dpi/tối đa3300px, reread300dpi/tối đa4500px. File xuất pypdf chọn/xoay trang lossless theo manifest đã đóng băng, không chọn lại bản hiện hành. Rotation là phần bổ sung vào rotation gốc. Nguồn có chữ ký điện tử/form tương tác cần kiểm tra riêng; scan gốc luôn giữ, không tuyên bố giữ chữ ký số sau gộp.

## Chuyển dữ liệu local cũ

Sau backup DB + thư mục private đã kiểm tra, chạy dry-run:

```sh
php artisan dossiers:migrate-objects --disk=r2
```

Chỉ khi được phép truyền dữ liệu đến bucket đó:

```sh
php artisan dossiers:migrate-objects --disk=r2 --execute --backup-verified
```

Disk `dossiers` giữ nguyên local legacy `storage/app/dossiers-private`; destination phải chỉ định riêng. Copy theo stream; kiểm SHA/size trước binding, từng chủ thể commit riêng, lưu metadata legacy disk/key để chạy lại sau gián đoạn. Mỗi lần chỉ chạy một migration command. Giữ source/version/export manifest cũ, không đổi bytes/path cũ trong manifest; thêm object pointers, không xóa originals. Download legacy có fallback stream đúng disk cũ; giao worker yêu cầu đã migrate nguồn. Không chạy command này với scan thật/R2 production trong kiểm thử hiện tại.

## Cách dùng

1. Tạo đợt nhập tại `/dossiers`; upload từng PDF mang mã máy. File trùng mở bản gốc; máy không rõ yêu cầu đối chiếu, không tự tạo máy.
2. Đợi worker, xem nguồn/processed/thumbnail; chọn nhiều trang, xoay bổ sung, gán đúng chủ thể. Lưu nháp, xác nhận từng giấy rồi xác nhận hồ sơ hoặc bỏ trang có chủ đích.
3. AI chỉ khi laptop đã cấu hình, heartbeat AI ready và Laravel bật AI. Bấm Phân tích AI một bộ; xem evidence và chọn đúng tài xế/phạm vi, nhập/sửa ngày rồi duyệt. Nhập tay luôn khả dụng.
4. Thay giấy bằng version mới/lý do/hiệu lực; bản tương lai chưa thay bản đang dùng. Thay thông tin giấy đã xác nhận dùng luồng sửa tạo version mới.
5. Chọn giấy máy và tài xế đang phân công, xem cảnh báo, đổi thứ tự rồi yêu cầu xuất; worker xuất snapshot. Excel lấy cùng logic hạn của Laravel, có giấy thiếu.

## Bằng chứng LOCAL30/10 — kiến trúc mới

- Native worker qua HTTP với T-XX0709.pdf thật:14727818bytes,16trang, SHA256 nguồn `f1146e33cc556084822e0221fa4eb2709d2cd2fa65d5c91b63f54f09ae7fb62a` giữ nguyên; qpdf12.3.2 exit3 đúng warning, check derivative exit0;16thumb, geometry và16pixel-render comparisons giống nguồn.17AI jobs MOCK; duyệt/thay giấy trong DB cách ly, xuất4trang thứ tự14,15,16,13 với xoay90/0/270/0; image payload hashes giữ nguyên; PDF mở/render được, Excel2sheet, temp sau ACK đã dọn. Một trang xuất đã xem bằng image viewer để đối chiếu độ rõ.
- MySQL8.4.3 cách ly33319:5cặp nghiệp vụ + cặp legacy job retry sau sửa locking;3cặp remote claim/complete/reclaim, mỗi cặp process và connection độc lập, follower thực sự đợi khoảng1.5giây. Một lease hợp lệ, duplicate complete không nhân outputs, old claimant409,7version/audit và manifest giữ nguyên. Kết quả chi tiết cập nhật ở Phase18.1.
- Hai scan T-XX0769.pdf/T-3C0624.pdf vẫn chưa có: acceptance hai mẫu thật chưa hoàn thành. Không dùng fixture thay bằng chứng đó. AI/kết nối thực tế, bucket R2 thật và laptop runtime chưa xác minh. Không thay app DB hoặc live trong lượt này.
