# Phase 18.4 — Giao diện và tiến độ Hồ sơ điện tử

## Phạm vi

- Ba tab Máy/xe, Tài xế và Đợt nhập PDF. Dùng bản phác đã duyệt và khung ứng dụng Blade. Hồ sơ máy tách giấy máy/xe và từng tài xế. Cảnh báo thiếu/hết hạn/bản thay đang chờ, xuất nhanh và sắp xếp trong Tùy chọn xuất.
- Bản scan và mục cần xác nhận cạnh nhau trên desktop, xếp dọc trên màn hình hẹp. Giữ quyền hồ sơ đối với định danh từ CCCD, quy tắc hạn và các điều kiện duyệt từ Phase 18.3.
- Thêm PDF từ hồ sơ của máy hoặc tài xế. Route chọn chủ thể, backend kiểm loại và phạm vi. Cùng loại đang có thì đề xuất Thay file. Sửa ở dòng giấy tạo phiên bản và audit, kiểm tra giấy đích, giữ trang và file. Giữ archive, restore và ảnh chụp manifest lúc bấm xuất.

## Tiến độ và tương thích

- XHR dùng số byte upload để báo phần trăm. Chỉ xác nhận Đã nhận PDF sau phản hồi lưu thành công. Dữ liệu sau đó lấy theo mốc thời gian của nguồn và job trên server. Trang đợt nhập, nguồn và hồ sơ lấy tiến độ gộp mỗi 4 giây khi có việc, reload/đổi tab đọc lại trạng thái.
- Worker v1 hiện báo stage và số trang thumbnail qua renew. Khi chưa biết tổng, màn hình hiện bước hoặc số trang đã chuẩn bị. Tổng chuẩn bị được xác nhận khi job hoàn tất; OCR lấy số trang thành công/tổng đã lưu. Giữ số liệu cuối khi worker trễ heartbeat. Lease và claim token cũ vẫn bảo vệ cập nhật đến muộn.
- Migration `2026_11_20_000002_add_dossier_workspace_tracking.php` thêm ba cột nullable: `document_target` trên nguồn, `attempt_history` và `queued_at` trên job. Dữ liệu cũ được đọc với mốc sẵn có; lịch sử lần chạy chỉ có từ thời điểm triển khai. Khi phát hành sau nghiệm thu, chạy migration additive trước khi mở bản Laravel mới. Worker laptop, Journal và Collector dùng bản hiện tại.
- Máy/tài xế và OCR học ánh xạ tiếp tục dùng hành vi Phase 18.3. Không tạo thêm ánh xạ hoặc điều chuyển trong module Hồ sơ.

## Nghiệm thu LOCAL

- Preview duy nhất trên loopback dùng SQLite, tài khoản và dữ liệu thử, nguồn PDF và kết quả lưu riêng. `T-DEMO01` có hai tài xế, PDF mẫu bốn trang và một đề xuất OCR mock. Không kết nối AI thật, R2 production hoặc Telegram.
- Trình duyệt đã mở ba tab và hồ sơ tài xế, chi tiết hai tài xế, mở scan theo trang và kiểm giao diện 390 px. Đã tải PDF bốn trang, thấy Đã nhận PDF, đóng/mở lại khi chờ worker, thấy worker report 2 trang, mô phỏng lỗi và retry trên UI, sau đó dùng công cụ PDF thật để báo hoàn tất 4/4. File thử qua loopback chuyển quá nhanh để quan sát phần trăm từng mốc trong lúc gửi.
- Bộ test có kiểm tra upload gán đúng chủ thể, tiến độ với claim hết hạn/thử lại, chỉnh giấy, xóa/khôi phục, PDF đã chốt phiên bản và hồi quy Phase 18.3. Kiểm thử PDF dùng qpdf/Poppler; luồng OCR dùng mock. Chờ nghiệm thu trước khi merge/deploy.
- `php artisan test --compact --filter 'Dossier|Zalo|OcrJob|DailyPhotoWorkflow|AutomaticDailyPhotoIntegration|ReconciliationGenerator|CheckIn'` — 148 test / 1195 assertion PASS. `git diff --check` PASS.
- Sau phản hồi trên trang xuất PDF, thanh điều hướng dùng bốn nút có biểu tượng, nhãn và trạng thái mục hiện tại. Menu Cài đặt mở hai mục hiện có. Đã thử mở menu trên desktop và xác minh bố cục hai cột, vùng bấm 44 px ở chiều rộng 390 px.
