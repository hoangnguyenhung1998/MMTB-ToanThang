# MMTB OpenClaw Reconciliation Worker

Worker Phase 14.3 chạy riêng trên laptop 24/7. Laravel tự xử lý các trường hợp chắc chắn
bằng rule Phase 14.2; worker chỉ nhận các ca còn thiếu hoặc mâu thuẫn, tải bằng chứng riêng tư,
gọi một lượt OpenClaw và gửi kết quả có cấu trúc về Laravel.

Worker dùng session riêng theo từng job (`mmtb-reconciliation-job-{id}`), không có `--deliver`,
vì vậy không gửi tin nhắn, không dùng lịch sử Telegram bot và không trộn bằng chứng giữa hai job.
OpenClaw Gateway đang chạy sẽ được tái sử dụng.

## Cài đặt Windows

```powershell
cd reconciliation-worker
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip install -e .
Copy-Item .env.example .env
```

Điền URL production và `OPENCLAW_AGENT_API_TOKEN` giống token trong Laravel production.
Nếu muốn dùng model mặc định đang cấu hình trong OpenClaw thì để trống `OPENCLAW_MODEL`.

Kiểm tra OpenClaw và test:

```powershell
openclaw gateway status
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
.\.venv\Scripts\python.exe -m mmtb_reconciliation_worker
```

## Tự khởi động

Mở PowerShell bằng **Run as administrator**:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\install-autostart.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\autostart-status.ps1
```

Worker quét 14 ngày gần nhất để bắt được nhật trình gửi muộn. Ảnh tải về chỉ nằm trong
`data/tmp` trong lúc xử lý và được xóa sau mỗi job; ảnh gốc vẫn ở R2 private.
