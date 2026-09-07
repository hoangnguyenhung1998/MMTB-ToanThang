# Phase 16.9 — ảnh hằng ngày

## Phạm vi

`DAILY_PHOTOS_ONLY=true` (mặc định khi chưa cấu hình) ngắt claim weekly, đối soát AI,
lệnh OpenClaw và lịch cảnh báo AI. Job cũ giữ nguyên, không xóa hoặc retry.
JournalWorker vẫn phục vụ OCR tiếp nhận/bàn giao máy. Không tắt gateway dùng chung.
Khôi phục thử nghiệm luồng cũ phải đặt cùng biến thành false ở hosting và các worker.

Ảnh trùng byte đã được ingestion xử lý. Fingerprint gần trùng chỉ là gợi ý (cùng máy/ngày),
không xóa ảnh hoặc coi là chắc chắn trùng. UNKNOWN cần kiểm tra, weekly được tạm giữ.

## Giờ và nguồn dữ liệu

- Giữ giờ OCR gốc, hiển thị HH:mm, chuẩn hóa AM/PM nếu có trong nguồn.
- Giờ vào: phút dư sau mốc nửa giờ <=10 thì xuống mốc; >10 thì lên mốc kế tiếp.
- Giờ ra luôn xuống mốc nửa giờ. Ca rỗng/âm sau làm tròn phải kiểm tra.
- HC tối đa 420 phút trên tổng các dòng BCH của một máy/ngày. TC trưa và tối không được bù vào HC. Ca tối qua đêm thuộc ngày bắt đầu.
- Không nối khoảng nghỉ, không chồng giờ, không vượt lịch phân công nguồn.
- Tự phân bổ trường hợp bốn mốc phân biệt, gồm ca sáng và chiều theo phân loại giờ hiện có.
  Hai ảnh, số ảnh lẻ, nhiều ca, ca đêm hoặc gần trùng cần xác nhận ghép ca trên trang chi tiết.
  Không suy diễn ngày gửi Zalo là ngày chụp; không tạo mặc định giờ làm khi thiếu ảnh.
- Người dùng chọn ảnh hoặc nhập mốc bổ sung kèm lý do và xác nhận. Một dòng tổng hợp
  giữ loại ca, các ảnh nguồn và lịch sử chỉnh sửa. Không cần weekly hoặc kết quả AI.
- Dòng sửa tay, đã duyệt/từ chối/xác nhận được bảo vệ. Kỳ đã chốt không được đồng bộ.
- Gợi ý công việc/vị trí lấy từ các nội dung đã nhập tay; luôn được nhập tự do.
  Chưa nhập danh mục từ file vì người dùng sẽ cung cấp file riêng sau.

## Kiểm thử trên PC công ty

Thư mục `C:\laragon\www\mmtbver2`. Sau khi lấy nhánh, dùng PHP 8.3 và dependency
của dự án. Kiểm tra `phpunit.xml`: SQLite `:memory:` và APP_ENV=testing, không dùng DB phát triển.

```bat
cd /d C:\laragon\www\mmtbver2
git fetch origin
git switch -c phase16-9-test --track origin/feature/phase-16-9-daily-photo-reconciliation
php vendor/bin/phpunit --filter="DailyTimeAllocatorTest|DailyPhotoWorkflowTest|Reconciliation|OcrJobTest|ZaloIngestionTest|AutomationHealthTest"
```

Các test cũ giữ cấu hình luồng lịch sử `DAILY_PHOTOS_ONLY=false` trong phpunit.xml.
DailyPhotoWorkflowTest bật chế độ mới riêng; production mặc định bật chế độ mới.
Workflow GitHub Actions đã được chuẩn bị để chạy cùng bộ test trên SQLite trong bộ nhớ sau khi đẩy nhánh.

Kiểm tra web sau migration trên DB test đã sao lưu: bảo trì AI, ánh xạ người gửi,
OCR lại ảnh chưa duyệt, bốn mốc giờ, thiếu ảnh, ghép ca đêm, nhập gợi ý, sửa tay rồi
đồng bộ lại. Không chạy test trên hosting hoặc DB production.

## Phát hành sau kiểm thử

Trạng thái bản làm việc ngày 07/09/2026: 105 test Python pass (OCR 30,
Health Agent 22, JournalWorker 36, ReconciliationWorker 17). Kiểm tra cú pháp
342 file PHP bằng tree-sitter không báo lỗi; đây không thay thế PHPUnit.
Đã thêm 18 test PHP cho luồng mới nhưng chưa chạy vì môi trường không có PHP.
Người dùng đã xác nhận đẩy Phase 16.9; nhánh đã được đăng tại PR #39.
Lần chạy Actions đầu phát hiện thiếu build Vite và quy tắc giờ trước 7h ảnh hưởng
chế độ cũ; đã bổ sung build và giới hạn quy tắc mới theo cờ 16.9 để chạy lại.
Chưa merge hoặc triển khai.

Sao lưu SQL trước migration. Migration mới chỉ thêm bảng ánh xạ, metadata OCR và
chi tiết ca; không sửa dữ liệu lịch sử. Chưa chạy migration trong workspace này.

Hosting: `/home/mmzoxgme/repositories/MMTB-ToanThang`, PHP `/opt/alt/php83/usr/bin/php`.
Sau merge production và pull, chạy `artisan migrate --force` rồi `artisan optimize:clear`
bằng PHP trên. Giữ cấu hình cPanel public/.htaccess và các tệp riêng của hosting.

Laptop: `D:\tools\Zalo Collector\MMTB-ToanThang`. Pull production rồi chạy
`scripts/phase-16-9-stop-reconciliation.ps1`; khởi động lại RapidOCRWorker,
JournalWorker và AutomationHealthAgent để nạp mã mới. Không restart Collector nếu
không có thay đổi Collector. Xác nhận không còn claim/gọi model weekly/AI.

Không áp dụng lại giờ hàng loạt cho các dòng đã sửa/duyệt. Bắt đầu kiểm tra trên
một máy/ngày chưa chốt, sau đó mới đồng bộ cả kỳ.
