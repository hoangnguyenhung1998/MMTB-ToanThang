# MMTB Gmail Intake Worker

Worker độc lập đọc phản hồi Gmail bằng OAuth `gmail.readonly`, nhận mã tài sản trong nội dung hoặc ảnh, rồi gửi một mã đề xuất về Laravel. Worker không tự tạo máy.

## Cài đặt

1. Bật Gmail API trong Google Cloud, tạo OAuth Client loại Desktop app và tải file thành `credentials.json`.
2. Chạy `scripts/setup.ps1`.
3. Điền `.env` và dùng cùng token với `GMAIL_INTAKE_WORKER_TOKEN` trên hosting.
4. Chạy `scripts/authorize.ps1`, đăng nhập Gmail và chấp thuận quyền chỉ đọc.
5. Chạy `scripts/install-autostart.ps1` bằng PowerShell Administrator.

`credentials.json`, `token.json`, `.env` và thư mục `data` không được commit.
