---
name: journal-ocr
description: Dùng khi sửa OCR ảnh nhật trình, đọc giờ/ngày, nội dung công việc, chuẩn hóa dữ liệu OCR, prompt/schema OCR hoặc OCR tests.
---

# Skill: OCR Nhật Trình

## Phạm vi

Dùng cho:

- OCR ảnh nhật trình hằng ngày.
- Đọc ngày và các mốc giờ.
- Đọc/chuẩn hóa nội dung công việc.
- OCR schema và validation.
- Retry/failure OCR.
- Payload OCR gửi về Laravel.
- Journal OCR tests.

Workflow hiện tại tập trung vào OCR từ ảnh.

Không tự đưa workflow OCR PDF cũ trở lại nếu không được yêu cầu.

---

## Nguyên tắc đọc dữ liệu

OCR phải ưu tiên đọc đúng dữ liệu thực sự xuất hiện trong ảnh.

Không tự tạo nội dung bị thiếu và không copy công việc từ ngày khác để làm dữ liệu có vẻ đầy đủ.

### Kế thừa ngày

Được phép kế thừa ngày xuống các dòng phía dưới khi chúng rõ ràng thuộc cùng ngày.

Không được lấy nội dung công việc của ngày trước copy sang ngày sau.

Kế thừa ngày và kế thừa nội dung là hai việc khác nhau.

### Giờ

Mục tiêu chính là đọc chính xác các mốc giờ thực tế trong ảnh.

Laravel/business layer chịu trách nhiệm tính toán thời lượng và các nghiệp vụ downstream theo kiến trúc hiện tại.

Không đưa thêm phép tính thời lượng vào OCR nếu không được yêu cầu.

### Nội dung công việc

Chuẩn hóa thành nội dung công việc hữu ích.

Không cần giữ các nhãn chung chung như:

- `Sáng`
- `Chiều`

nếu output cần thiết là công việc thực tế.

Có thể loại bỏ lặp lại không cần thiết nhưng không được gộp các công việc khác nhau chỉ vì câu chữ gần giống nhau.

---

## Làm tròn giờ

Không tự chuyển rule làm tròn giờ của application vào OCR.

Giữ ranh giới:

1. OCR đọc giờ nhận diện/raw.
2. Laravel/business layer áp dụng rule làm tròn đã được phê duyệt.

OCR ưu tiên fidelity của dữ liệu nguồn.

---

## Validation và ngoại lệ

OCR output phải đúng schema hiện tại.

Nếu model trả dữ liệu lỗi:

- chỉ normalize khi chắc chắn;
- nếu mơ hồ, đánh dấu/report exception;
- không tự đoán số từ chuỗi không rõ nghĩa.

Không ép một giá trị sai schema thành số hợp lệ chỉ để pipeline chạy tiếp.

---

## Chống copy/duplicate ngày

Không tự sinh thêm nội dung cho một ngày bằng cách copy từ ngày khác.

Nếu cùng một ngày xuất hiện nhiều dòng:

- chỉ aggregate theo business logic hiện hành;
- giữ các time/work entry thực sự khác nhau;
- tránh duplicate nội dung giống hệt không cần thiết.

---

## Test

Cần ưu tiên các case:

- ảnh hợp lệ;
- nhiều dòng cùng ngày;
- kế thừa ngày;
- chuyển sang ngày mới;
- giờ thiếu/mơ hồ;
- nội dung công việc trùng;
- OCR output sai schema;
- regression từ các lỗi OCR đã từng gặp.

Không commit ảnh runtime/sensitive nếu chúng chưa được chủ động chọn làm test fixture.

---

## Hoàn thành khi

- Ranh giới OCR và business transformation vẫn rõ.
- Không copy công việc giữa các ngày.
- Kế thừa ngày thận trọng.
- Giờ được đọc trung thực.
- Schema validation vẫn bắt được malformed output.
- OCR tests liên quan PASS.
- Đã báo nếu payload Laravel bị thay đổi.
