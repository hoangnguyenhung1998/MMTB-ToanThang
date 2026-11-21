# Phase 18.7 — Đối chiếu và sửa nhóm trang hồ sơ

## Mục tiêu và phạm vi

Cải thiện nhận diện theo ảnh và dấu hiệu tiếp nối, sửa loại/trang trên giấy và giữ lịch sử xuất. Dựa trên Phase 18.6 và runbook worker laptop. Phạm vi LOCAL.

## Triển khai

- [x] Prompt và dữ liệu ghép dùng loại, bố cục, vai trò trang, số thứ tự in và khóa đối chiếu số chứng nhận. Không dùng tên file hoặc số trang cố định.
- [x] Worker giữ ảnh toàn trang, bổ sung hai vùng chi tiết và dùng góc xoay gợi ý cho OCR từng giấy.
- [x] Sửa trang và chuyển trang giữa đề xuất trong một giao dịch. Giấy đã xác nhận tạo phiên bản sửa riêng. Ghi loại và danh sách trang trước/sau, vô hiệu OCR cũ khi đổi phạm vi.
- [x] Skill OCR hồ sơ và bảng kỳ vọng T-XL0039.
- [ ] Baseline và nghiệm thu AI thật bằng cùng model.

## Kiểm tra và phát hành

Mẫu gốc PDF 1.6 có 22 trang. `PdfEngine.prepare` tạo 22 thumbnail, giữ geometry. Render và tạo vùng chi tiết thành công cho các trang 1, 2, 3, 7, 17, 20. SHA256 nguồn giữ nguyên. Góc xoay thử do kiểm thử cấp; chưa suy ra từ AI.

Không có cấu hình AI riêng ở worker LOCAL. Không có số liệu nhận diện trước/sau của model thật. Thử Laravel dùng fixture giả lập. Không cần migration. Khi phát hành đã duyệt, cập nhật worker Hồ sơ trên laptop để dùng ảnh chi tiết; xem runbook trước khi triển khai. Chưa merge/deploy.

Laravel Hồ sơ/OCR/remote: 69 test, 776 assertion; chạy thêm riêng ca tạo run mới giữ loại/trang sửa tay: 1 test, 11 assertion. Python worker: 11 test. Hai bộ PDF thử từ mẫu thật, mỗi bộ 6 trang, mở lại và đối chiếu nội dung trang theo hai thứ tự đã cấp; nguồn giữ nguyên checksum. Chuyển trang trong cùng giao dịch hiện áp dụng cho hai nhóm chưa xác nhận. Với giấy đã xác nhận, chỉnh trang tạo bản sửa riêng.
