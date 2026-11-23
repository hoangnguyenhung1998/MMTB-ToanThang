# Phase 18.8 — Lưu nhóm giấy vào hồ sơ máy

## Mục tiêu và phụ thuộc

Tiếp nối Phase 18.7, dùng kết quả phân loại đã lưu để tạo giấy đủ căn cứ và đưa thao tác còn lại về hồ sơ máy. LOCAL, không có migration hoặc thay đổi mã/giao thức worker.

## Triển khai

- [x] Xuất bản từng nhóm từ ai_group/complete, giữ giấy cá nhân chưa rõ người bên ngoài bộ in. Dùng lại proposal cũ bằng nút Lưu từ phân loại có sẵn.
- [x] Bản scan cùng loại chờ chọn làm hiện hành; lưu lại cùng nhóm không tạo phiên bản trùng.
- [x] Sửa hạn, đổi loại, sửa/chuyển trang giữa giấy cùng nguồn. Chuyển trang tạo hai phiên bản trong cùng giao dịch và giữ manifest cũ. Nhóm nguồn phải còn ít nhất một trang.
- [x] OCR hạn từng giấy và chặn kết quả đến sau khi sửa. Giữ hạn tháng ở độ chính xác tháng.
- [x] Chọn loại thủ công, thêm vào giấy, đánh dấu/khôi phục nhiều trang trắng.
- [ ] Nghiệm thu dữ liệu thật và xác nhận bố cục trước merge/deploy.

## Preview

Fixture 26 trang tại http://127.0.0.1:8868/dossiers/machines/1 trên SQLite cách ly. Hai giấy máy tự lưu, bốn giấy cá nhân chờ gắn; AI thật tắt. Ảnh preview lưu ngoài repo. Luồng giấy bảo hiểm cá nhân giữ kiểm tra dòng danh sách BIC hiện có.

## An toàn phát hành

Trạng thái bản mới và giấy chưa gắn lưu trong proposal. Chỉ phiên bản đã xác nhận mới vào bộ in. Nút Lưu từ phân loại có sẵn xử lý kết quả cũ không cần OCR lại toàn bộ. Chưa chạy trên hồ sơ 26 trang thật, chưa merge, deploy hoặc cập nhật worker laptop.

## Kết quả kiểm thử

PHPUnit nhóm Dossier, DossierAi, DossierRemote đạt 71 test / 814 assertion. Các ca mới kiểm lưu tự động không trùng, chuyển giữa hai giấy đã xác nhận, xuất PDF cũ/mới, hạn tháng nhập tay, từ chối tài xế ngoài máy, gắn tài xế qua mapping đang hiệu lực, đánh dấu và khôi phục trang trắng. git diff --check đạt.

## Nghiệm thu bố cục LOCAL

T-DEMO188 dùng bảng gọn, nút hạn/tài xế trên dòng, menu Thao tác và hộp riêng cho sửa trang, nhập hạn, đổi loại. Fixture có bản scan chờ kích hoạt. Đã kiểm trình duyệt 1672 × 941 và 390 px, các nút trên dòng giấy máy không tràn, các loại nhập hạn hiển thị đúng. Giữ các route lưu và phiên bản hiện hành của Phase 18.8. Người dùng đã duyệt giao diện LOCAL. Hồi quy nhóm Hồ sơ trên nhánh phát hành đạt 71 test / 814 assertion. Chờ PR và CI.
