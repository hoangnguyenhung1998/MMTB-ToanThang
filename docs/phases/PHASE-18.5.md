# Phase 18.5 — Ánh xạ Zalo, tài xế và phân trang

## Phạm vi và phụ thuộc

Phase 18.3/18.4, PR #74 merge `4932538`. Đồng bộ từ ánh xạ sender–máy có hiệu lực, giữ đường ghi Settings và luồng OCR nhật trình hiện có. Hồ sơ và PDF tiếp tục đọc `DossierZaloRoster`, giữ manifest bất biến. Dùng Driver và quan hệ sender–tài xế hiện có.

## Thay đổi

- Nhập PDF, tải lại và yêu cầu OCR trên nguồn có mã máy gọi tạo liên kết cho sender đủ điều kiện. Khóa tin nhắn sender ổn định và kiểm lại mapping trong giao dịch. Mỗi sender mới có một hồ sơ và audit, tên trùng vẫn là hai sender. Liên kết đã có lịch sử hoặc ánh xạ mâu thuẫn được giữ cho đối chiếu.
- Đề xuất chủ thể khi bằng chứng khớp duy nhất trong danh sách ứng viên hoặc nguồn đã chọn đích. Chỉ trùng tên thì chờ duyệt. Không ghi đè CCCD hoặc ngày sinh đã xác nhận. Số CCCD và điện thoại giữ dạng chuỗi. Dữ liệu định danh trích từ hồ sơ điện tử nằm sau quyền hiện có.
- `/drivers` lọc trạng thái liên kết, tìm tên/ID Zalo, thông tin CCCD theo quyền và mã máy. Lưu điện thoại trên dòng và trở lại đúng bộ lọc. Phân trang server cho máy, tài xế, file nhập và Settings. Đợt nhập tiếp tục dùng phân trang hiện có. Polling bám đúng trang file.

## Kiểm thử và vận hành

- Hồi quy LOCAL 88 test / 889 assertion tại mốc triển khai. Hai process PHP trên cùng SQLite cách ly tạo đúng một hồ sơ và liên kết cho sender. Browser preview đã xác minh bộ lọc Zalo/mã máy và nút lưu điện thoại.
- Tại mốc trước reset chưa có migration. Prompt/schema do Laravel cấp, giao thức worker giữ nguyên. Chưa chạy AI thật và chưa kiểm tra dữ liệu hosting.

## Mốc reset LOCAL

- Migration additive `2026_11_20_000003_prepare_zalo_driver_reset.php` thêm ID Zalo duy nhất nullable, dấu nguồn ngừng sử dụng và sổ ghi reset. `dossiers:reset-drivers` ghi sổ trong cùng giao dịch với xóa. Seed riêng có thể chạy lại.
- MySQL 8.4.3 `mmtb`, loopback 3306, datadir Laragon. Backup 3.314.060 byte, SHA256 `7a887b3f1041d983e2ddd62408adaa9a8eac86f15101fb6b5b4d30c64f0cfbbd`, restore thử thành công sang schema cách ly ở 33320. LOCAL 310→0 Driver, 7→0 phân công, 269 máy/787 sự kiện/2 nguồn giữ. Máy LOCAL có 0 ánh xạ Zalo–máy và 0 phiên bản trước reset.
- Fixture MySQL giữ 1 nguồn hỗn hợp và 1 phiên bản máy, xóa 1 hồ sơ/phiên bản cá nhân và 1 export. Hai tiến trình seed cùng hồi chạy, cả hai thành công và đếm 2 Driver, 2 sender ID khác nhau trùng tên. Chạy lại reset báo đã áp và giữ Driver mới.
- Preview SQLite được sao lưu và kiểm integrity trước reset. Trình duyệt hiển thị danh mục mới, lưu số điện thoại trong cùng bộ lọc. Tải PDF cũ trả 404 qua HTTP. Laravel 88 test / 892 assertion ở mốc reset. Chưa chạy scan thật. File riêng/đầu ra cũ được chặn khỏi ứng dụng và giữ bytes trong kho riêng đến khi kiểm kê được mọi tham chiếu.
- Runbook thủ công: `docs/runbooks/DOSSIERS-DRIVER-RESET.md`.
- Reset LOCAL đã được duyệt. OCR với scan thật còn chờ nghiệm thu. Số liệu LOCAL là bằng chứng kiểm thử; phạm vi thực tế trên hosting lấy từ preview của chính DB hosting.
