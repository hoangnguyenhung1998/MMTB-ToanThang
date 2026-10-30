# Worker hồ sơ trên laptop — riêng process/config/task

Chưa cài/chạy trên laptop24/24 trong lượt30/10. Chỉ LOCAL có bằng chứng native. Không mở cổng/tunnel, không thay/restart Journal/Collector.

## Chuẩn bị sau khi release được duyệt

- Checkout đúng reviewed commit trong thư mục runtime phù hợp, bảo toàn thay đổi/env hiện có. Không tự pull/overwrite laptop.
- Python3.12 và venv riêng `dossier-worker/.venv`. Không pip vào venv Journal/global. `python -m venv dossier-worker/.venv`, sau đó `.venv/Scripts/python.exe -m pip install -r dossier-worker/requirements.lock` (Linux dùng `.venv/bin/python`). Lock gồm transport Journal-compatible, pydantic/requests/httpx, pypdf/Pillow.
- Cài/xác minh qpdf>=12.3.2 và Poppler pdfinfo/pdftoppm ở laptop được phép; điền absolute executable paths. Không cài trên hosting. Không tự tải/copy binary không rõ nguồn; dùng package chính thức đã duyệt bởi operator.
- Copy `dossier-worker/.env.example` thành `.env`, quyền chỉ tài khoản chạy dịch vụ + quản trị. Không dùng journal-worker/.env.

Các biến trong `dossier-worker/.env`:

| Biến | Giá trị |
|---|---|
| DOSSIERS_API_URL | `https://mmtb.congtytoanthang.top/api/dossiers/v1` |
| DOSSIERS_WORKER_TOKEN | Bearer riêng cùng Laravel, >=32ký tự; không phải OCR token/AI key |
| DOSSIERS_WORKER_ID | ID duy nhất ổn định, ví dụ dossiers-laptop-01 |
| DOSSIERS_QPDF / DOSSIERS_PDFINFO / DOSSIERS_PDFTOPPM | Đường dẫn executable hợp lệ |
| DOSSIERS_AI_ENDPOINT / DOSSIERS_AI_API_KEY / DOSSIERS_AI_MODEL | Chỉ tại laptop; cùng giao thức JournalVisionClient chat/completions |
| DOSSIERS_WORKER_STATE | Absolute private directory riêng trên ổ còn đủ chỗ; mặc định data |
| DOSSIERS_POLL_SECONDS |10; code giữ tối thiểu5giây |
| DOSSIERS_MAX_DOWNLOAD_MB |256; tổng input mỗi job cũng bị chặn |

Không đưa R2 key/DB password vào worker. Env hệ điều hành có ưu tiên hơn file; kiểm tra đúng config riêng khi thay cấu hình, restart duy nhất task hồ sơ. Chưa cấu hình AI vẫn làm PDF/thumbnail/export, heartbeat ai_ready=false; không nhận job AI.

## Preflight và chạy tay

```powershell
& .\dossier-worker\.venv\Scripts\python.exe .\dossier-worker\worker.py --preflight
& .\dossier-worker\.venv\Scripts\python.exe .\dossier-worker\worker.py
```

Preflight chỉ kiểm tra tools/dependencies/config presence, không gọi AI hoặc chứng minh endpoint/token đã kết nối. Tiến trình thật heartbeat rồi poll; kiểm tra UI last_seen và log status. `--once` nhận tối đa một job; `--env <absolute file>` chọn config riêng. `--mock-ai` CHỈ test loopback và phải dùng DB/storage cách ly; tuyệt đối không dùng cho hosting/hồ sơ thật. Hosting không chấp nhận mock result trong production.

## Tự khởi động Windows

Script được chuẩn bị, chưa đăng ký task thật. Operator cấp tài khoản dịch vụ có quyền đọc code/env và ghi state; credential nhập bằng Get-Credential tại máy, không vào chat/Git:

```powershell
.\dossier-worker\Install-Task.ps1 -Python '<absolute venv python.exe>' -Credential (Get-Credential)
Start-ScheduledTask -TaskName 'MMTB-DossierWorker'
Get-ScheduledTaskInfo -TaskName 'MMTB-DossierWorker'
```

Task duy nhất `MMTB-DossierWorker`, AtStartup chạy không cần login bằng tài khoản đã cấp; Windows lưu credential task. Script từ chối nếu tên task đã tồn tại; MultipleInstances IgnoreNew và process lock chặn worker trùng. Run-Worker.ps1 phục hồi sau exit với backoff60giây; Task Scheduler restart3lần/1phút, StartWhenAvailable, không giới hạn runtime. Không tạo task khác, không restart task cũ. Khi cần đổi cấu hình: dừng và khởi động chỉ task này sau khi job an toàn; token cũ bị lease fencing nếu process chết.

Giới hạn1job toàn hệ thống hồ sơ; CPU process below normal/nice+5, subprocess tuần tự, không CPU fan-out. Theo dõi RAM/ổ đĩa với scan pilot trước khi tăng tải; giới hạn300trang và256MB input/output bảo vệ laptop nhưng không thay quota/OS monitoring.

## Log, cache, lỗi và phục hồi

- `DOSSIERS_WORKER_STATE/worker.log`: quay vòng2MB x5; chỉ job/attempt/stage/exit/safe code, không prompt, scan text, raw AI, URL hoặc key. Không log exception provider có thể chứa response body.
- Mỗi job có UUID/attempt sandbox. Original tải về được kiểm size/SHA theo chunk; `.partial` bị dọn nếu tải ngắt. Không xử lý file hash sai.
- Output private staged trên Laravel; receipt/outbox ghi atomically tại máy. ACK từng slot được ghi lại, tránh gửi lại toàn bộ300thumbnail khi rate-limit. Crash sau upload/before complete: khởi động lại replay cùng payload; server idempotent. Chỉ ACK thành công hoặc xác nhận stale409 mới dọn sandbox đã hoàn tất. Fail ACK dọn copies; orphan chưa có outbox quá24giờ được dọn trong thư mục riêng, không chạm R2/legacy nguồn. Pending outbox mất mạng được giữ để phục hồi.
- Không sửa/delete outbox khi job còn hiệu lực. Lỗi lặp: dừng riêng worker, giữ evidence an toàn, xem safe code, giải quyết cấu hình/tool/network, retry bằng UI. Job max3attempt/backoff30/120; lease180s/renew60s/max1h. Worker cũ409 không được hoàn tất công việc đã nhận lại.
- Laptop offline: web hiển thị last_seen cũ, pending chờ; không lỗi/thành công giả. PDF có sẵn/Excel/hạn vẫn dùng được. Bật máy, kiểm heartbeat/claim/completion thật; không replay Journal/backlog.
- Tự test AI: bật Laravel AI sau khi operator điền3biến AI tại laptop và worker ai_ready, auto_analyze giữ false, bấm Phân tích AI một bộ rồi đối chiếu evidence/subject/expiry trước duyệt. Chưa có lời gọi thật trong bàn giao này: **Hoàn thiện tích hợp; chưa kiểm thử kết nối/AI thực tế**.
