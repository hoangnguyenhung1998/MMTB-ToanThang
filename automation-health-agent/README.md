# MMTB Automation Health Agent

Agent Windows chạy trên laptop 24/24, gửi một heartbeat mỗi 60 giây về Laravel. Agent theo dõi:

- `MMTB-ZaloCollector`
- `MMTB-RapidOCRWorker`
- `MMTB-JournalWorker`
- `MMTB-OpenClawReconciliationWorker`
- `openclaw gateway status`

Agent chỉ đọc trạng thái Scheduled Task và log; không khởi động, dừng hoặc sửa dữ liệu worker.

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
