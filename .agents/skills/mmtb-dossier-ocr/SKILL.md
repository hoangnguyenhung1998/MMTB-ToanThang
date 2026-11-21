---
name: mmtb-dossier-ocr
description: Dùng khi sửa nhận diện loại giấy, ghép trang PDF, chỉnh phân loại, OCR thông tin từng giấy hoặc kiểm thử worker Hồ sơ điện tử MMTB.
---

# OCR Hồ sơ điện tử MMTB

- Lần theo render ảnh trong dossier-worker, ai_page, ai_group/complete, đề xuất phân loại và phiên bản dùng để xuất PDF. Các quy tắc chạy thật nằm trong prompt/schema của Laravel và xử lý ảnh của worker riêng.
- Ưu tiên tiêu đề in, bố cục và đối tượng. Phân biệt bảo hiểm con người với bảo hiểm máy dù chung logo. Dùng số trang in, điều khoản, phụ lục và phần ký để ghép. Vị trí trong scan là tín hiệu phụ.
- Giữ ảnh toàn trang cùng vùng chi tiết. Hai mặt CCCD hoặc ATLĐ trên cùng một scan là một giấy. Trang mờ, gần trắng hoặc có nhiều giấy độc lập cần đối chiếu; giữ trang gốc.
- Lưu nhóm rõ theo từng mục, không phụ thuộc CCCD, hạn hoặc chủ thể. Chỉ đưa giấy cá nhân vào hồ sơ tài xế sau khi xác định đúng người. Bảo toàn loại và trang đã sửa tay khi chạy lại.
- Sửa loại giữ thứ tự trang. Chuyển trang cập nhật hai nhóm trong một giao dịch. Ghi audit loại và trang trước/sau; giữ nguyên phiên bản của lần xuất đã chốt.
- OCR thông tin chạy riêng từng giấy, chỉ đọc trường phù hợp. CCCD dùng mặt trước và giữ số 0 đầu. Hạn là mốc kết thúc hiệu lực in, giữ độ chính xác tháng khi cần. Lỗi OCR hoặc kết quả đến muộn không thay đổi phân loại.
- Chạy fixture đảo thứ tự, mục lỗi, trang mờ, sửa và chuyển trang, OCR theo giấy và xuất theo thứ tự mới. Nếu thay xử lý ảnh, chạy test Python worker. Dùng [mẫu đối chiếu](references/T-XL0039.md) cho nghiệm thu thực tế; giữ scan và dữ liệu cá nhân ngoài Git và log.
