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

## Bổ sung tự lưu theo máy và tài xế Zalo

- Giấy cá nhân tự lưu cho tài xế Zalo duy nhất đang ánh xạ với máy. Khi có nhiều tài xế, dùng chủ thể đã chọn hoặc bằng chứng khớp duy nhất nếu có; trường hợp còn mơ hồ đưa ra chọn người. Giấy đã lưu thuộc hồ sơ tài xế và hiển thị theo máy hiện hành khi điều chuyển ở Cài đặt check-in.
- Đăng ký và Đăng kiểm được lưu cho máy nguồn dù nguồn thuộc nhóm Máy. Thiếu định danh hay hạn vẫn lưu; trường định danh có dữ liệu thực sự mâu thuẫn ở đề xuất cũ giữ trạng thái cần đối chiếu. Giữ nhóm máy và trạng thái bắt buộc của loại giấy.
- Bảo hiểm tai nạn có thể lưu theo phân loại và chủ thể đã chọn hoặc xác định qua Zalo. Phiên bản này ghi nguồn là phân loại, chưa có bằng chứng dòng danh sách. CCCD lưu giấy trước; thông tin định danh xác nhận riêng.
- Dùng nút **Lưu từ phân loại có sẵn** ở hồ sơ máy để áp dụng quy tắc mới cho các đề xuất đang chờ. Các lượt lặp dùng lại phiên bản đã lưu.
- Hồi quy LOCAL 74 test / 846 assertion, gồm tự lưu qua kết quả nhóm, nhiều tài xế, chuyển máy, sửa hạn, dùng lại phân loại và số khung mâu thuẫn. AI mock, qpdf/Poppler thật, DB SQLite. Chưa phát hành bản bổ sung hoặc kiểm tra dữ liệu hosting. Không cần migration hay cập nhật worker.
