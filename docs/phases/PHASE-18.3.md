# Phase 18.3 — Điều chuyển tài xế, duyệt hồ sơ và PDF in

## Mục tiêu và phạm vi

Hồ sơ giấy của tài xế đi theo ánh xạ máy tại check-in. Giảm việc kiểm tra OCR và cho phép thay, lưu trữ, khôi phục và in bộ giấy hiện hành. Giai đoạn này dùng môi trường phát triển. Phase 18, 18.1 và 18.2 cung cấp danh mục, quyền, audit, OCR, private storage và pipeline PDF.

## Thay đổi

- `/daily-photos/settings` dùng sender ID và ánh xạ theo hiệu lực. Hồ sơ máy, báo cáo và PDF dùng chung `DossierZaloRoster`. Khi tài xế chưa có lịch sử ánh xạ check-in, hồ sơ vẫn hiển thị phân công vận hành cũ nếu không mơ hồ. Check-in liệt kê sender chưa liên kết và xung đột để kiểm tra; nguyên trạng dữ liệu cũ được giữ.
- Ghi ánh xạ trong `ZaloSenderMachineService` từ settings, API và nhánh OCR học ánh xạ. Khóa tài xế và bản ghi ánh xạ trong giao dịch khi chuyển. Kết thúc ánh xạ đang hoạt động, mở ánh xạ đích và ghi `zalo.driver_transferred` với người thao tác, thời điểm, tài xế, máy đi và máy đến. Lặp lại cùng đích không tạo thêm sự kiện. Phân công chính và lịch sử nhật ký vẫn do luồng vận hành hiện hành quản lý.
- Danh bạ tài xế hiển thị sender ID, các tên đã thấy trên Zalo, lịch sử ánh xạ, họ tên CCCD đã xác nhận và giấy riêng. CCCD lưu bằng chứng từ mặt trước và giữ số 0 đầu. Số CCCD đầy đủ chỉ xuất hiện trong chi tiết hồ sơ được phép xem.
- OCR chỉ tìm hạn cho kiểm định máy, bảo hiểm máy/xe, đăng ký, đăng kiểm, bảo hiểm TNLĐ, thẻ ATLĐ và giấy khám sức khỏe. Không suy hạn nếu không có quy tắc đã duyệt. Mục còn thiếu bằng chứng loại, chủ thể, phạm vi hoặc hạn ở bảng duyệt. Chọn nhiều mục bắt đầu với ô trống. Bản đã tự xử lý hoặc đã duyệt chuyển vào Đã xử lý.
- Thay PDF từ dòng giấy tạo nguồn riêng và giữ nguyên bản hiện hành đến khi bản mới được xác nhận và đến hiệu lực. Kiểm tra đúng chủ thể, loại và toàn bộ trang. Có thử lại và hủy; bản lưu trữ có thể khôi phục từ Lịch sử nếu không xung đột. Giữ nguồn và lịch sử xuất.
- Nút Xuất PDF ở hồ sơ máy lấy giấy máy rồi giấy từng tài xế đang gắn. Manifest chốt phiên bản và trang trong giao dịch, sau đó giao PDF cho worker hồ sơ hiện có. Màn hình xuất hiển thị giấy thiếu, hết hạn và đợt thay đang chờ. Dùng lại quyền và private storage hiện có.

## Migration và vận hành

`2026_11_20_000001_add_dossier_replacement_sources` chỉ thêm hai cột nullable trên `dossier_sources` và khóa ngoại tới phiên bản được thay. Đã chạy trên DB kiểm thử. Chưa chạy trên DB ứng dụng hoặc hosting. Worker laptop, qpdf, Poppler và R2 giữ giao thức hiện tại.

## Bằng chứng và giới hạn

- `php artisan test --compact --filter 'Dossier|Zalo|OcrJob|DailyPhotoWorkflow|ReconciliationGenerator|CheckIn'` — 125 test, 988 assertion PASS. OCR dùng mock. Có kiểm tra PDF thực từ dữ liệu thử qua bộ xử lý PDF local. `git diff --check` PASS.
- MySQL 8.4.3 loopback riêng, `tests/Support/DossierSenderTransferConcurrency.php`. Hai process PHP và hai kết nối riêng chuyển đồng thời cùng một tài xế sang hai máy khác nhau. Ghi hai sự kiện nối tiếp cho hai lần chuyển thực tế, còn một ánh xạ hoạt động và không ghi vào lịch sử phân công vận hành. Lặp cùng đích được kiểm tra riêng trong Laravel.
- Trình duyệt LOCAL với dữ liệu giả lập đã hiển thị hai tài xế trên `P183-C` và nút Đổi tài xế mở đường dẫn `/daily-photos/settings?machine_id=3`. Ca chuyển và PDF tải qua HTTP được chạy trong Laravel, không dùng dữ liệu người thật.
- Trường hợp không xác định được tài xế từ bằng chứng hồ sơ vẫn đưa ra duyệt. OCR trên scan thật và pipeline laptop/R2 thật chưa được nghiệm thu trong giai đoạn này.
- Rà phát hành từ `17a7614`: OCR học ánh xạ kiểm tra máy đang gắn của tài xế ở thời điểm xử lý, kể cả máy từ phân công cũ nếu chưa có ánh xạ Zalo. Ca ảnh đến trước hiệu lực phân công và có mã máy khác vẫn ghi nhật trình đúng mã trên ảnh, giữ máy đang gắn, không tạo ánh xạ và hiện chênh lệch trong check-in. Hồi quy mở rộng 146 test / 1139 assertion PASS. Người dùng tự kiểm tra scan thật trên hosting.
- Web và MySQL fixture đã dừng sau khi kiểm tra.

## Nghiệm thu trên ứng dụng LOCAL

1. Sau khi có migration trên database thử, mở `/dossiers` → **Tài xế**. Xem giấy và lịch sử của người có liên kết Zalo. Trong check-in, gắn hai người vào máy A rồi chuyển một người sang máy B bằng **Đổi tài xế**. Kiểm tra hai hồ sơ máy và lịch sử tài xế.
2. Mở đợt nhập thử và xem bản gốc cạnh mục cần kiểm tra. Duyệt một mục, thử tick các mục đủ điều kiện rồi xác nhận theo lô. Kiểm tra giấy đã duyệt trong **Đã xử lý**.
3. Chọn **Thay** ở dòng thẻ ATLĐ, tải bản đủ trang, thử hủy một lượt rồi xác nhận một lượt. So sánh bản hiện hành khi chờ và sau khi xác nhận. Thử **Xóa** và **Khôi phục** từ Lịch sử.
4. Trong hồ sơ máy đã chuyển, chọn **Xuất PDF**. Đọc các cảnh báo, dùng worker hồ sơ hiện có để hoàn tất job, sau đó mở file. Đối chiếu giấy máy rồi giấy từng người hiện gắn và số trang với manifest.

## Checklist LOCAL

- [x] Điều chuyển cùng tài xế trong hai process và một máy có nhiều tài xế.
- [x] CCCD chuỗi / xung đột, duyệt lẻ / lô, thay ATLĐ nhiều trang, lưu trữ / khôi phục.
- [x] PDF sau điều chuyển, cảnh báo thiếu giấy và khóa phiên bản xuất.
- [ ] Nghiệm thu toàn luồng trên ứng dụng LOCAL với bộ scan thử do người dùng chọn.
