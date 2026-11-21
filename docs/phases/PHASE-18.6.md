# Phase 18.6 — Phân loại trước, OCR thông tin sau

## Phạm vi

- [x] Mở kết quả phân loại từng mục qua worker hồ sơ, giữ trang chưa đủ căn cứ cho xử lý thủ công.
- [x] Tách OCR thông tin theo giấy và chỉ xác nhận vào hồ sơ sau khi chọn đúng chủ thể.
- [x] Tái sử dụng quyền hồ sơ, bản gốc, proposal và giao thức worker hiện tại.
- [ ] Nghiệm thu với scan thật và phê duyệt phát hành.

Liên quan Phase 18.1, 18.2, 18.4 và 18.5. Lượt này triển khai LOCAL.

## Quyết định

Phân loại lưu loại và trang dưới dạng proposal, không cần định danh. Mục không hợp lệ hoặc giao trang mơ hồ nằm ở Chưa phân loại. Đổi loại giữ phạm vi trang và vô hiệu hóa lượt OCR thuộc loại cũ. OCR riêng dùng job ảnh hiện có, giới hạn trường theo loại; khi fail cập nhật trạng thái giấy, không làm mất phân loại. Giấy cá nhân vẫn qua kiểm tra chủ thể ở luồng xác nhận. Không thêm migration.

## Sự cố hosting và kiểm thử

Access log trước đó xác nhận POST complete trả 422 cho bốn job ai_group. Bản ghi lỗi chỉ giữ mã tổng quát; chưa xác minh nhánh validation cụ thể của scan thật. Fixture LOCAL chứa giấy rõ, trang mờ và nhóm lỗi; mô phỏng kết quả worker qua API complete, phân loại tay, OCR CCCD và lỗi OCR riêng. Không chạy lại các job hosting.
Hồi quy LOCAL: PHPUnit --filter Dossier(Ai|Remote|)Test đạt 67 test / 761 assertion. AI dùng phản hồi mock. Không đổi mã worker Python hoặc giao thức.
