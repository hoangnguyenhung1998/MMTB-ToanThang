# MMTB Automation Health Agent

Agent Windows chạy trên laptop 24/24, gửi một heartbeat mỗi 60 giây về Laravel. Agent theo dõi:

- `MMTB-ZaloCollector`
- `MMTB-RapidOCRWorker`
- `MMTB-JournalWorker`
- `MMTB-OpenClawReconciliationWorker`
- cổng TCP OpenClaw Gateway (mặc định `127.0.0.1:18789`)

Agent đọc trạng thái Scheduled Task và log, đồng thời chỉ thực thi các lệnh vận hành
nằm trong allowlist do Laravel gửi xuống.
Chỉ các lỗi xuất hiện trong 10 phút gần nhất mới được tính là lỗi liên tiếp.

Với Zalo Collector, heartbeat chỉ gửi mã hồ sơ, tên hiển thị, số nhóm và trạng
thái sẵn sàng. Cookie, IMEI, User-Agent và QR luôn nằm trên laptop. Lệnh chuyển
tài khoản từ web chỉ nhận một `account_id` an toàn, gọi script cục bộ rồi khởi
động lại đúng một Scheduled Task Collector.

## Cài đặt

Tạo node trên Laravel trước để nhận token dùng một lần:

```bash
php artisan automation:register-node laptop-24-7 --name="Laptop 24/24" --location="Nhà"
```

Trên laptop:

```powershell
cd "D:\tools\Zalo Collector\MMTB-ToanThang\automation-health-agent"
py -3.12 -m venv .venv
Copy-Item .env.example .env
notepad .env
powershell -ExecutionPolicy Bypass -File .\scripts\install-autostart.ps1
```

Kiểm tra:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\autostart-status.ps1
```

Token không được commit. Việc chạy lại `automation:register-node` sẽ cấp token mới và làm token cũ mất hiệu lực.
