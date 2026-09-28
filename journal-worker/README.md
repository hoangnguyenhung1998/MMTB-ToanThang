# MMTB Journal Worker

Worker dùng chung Vision API cho các hàng đợi tách biệt: AI Rescue Daily Photo được yêu cầu rõ ràng, nhật trình tuần và hồ sơ tiếp nhận/bàn giao máy. Mỗi loại dữ liệu dùng endpoint và bảng phù hợp nhưng dùng chung cấu hình provider/model, image transport, retry, Scheduled Task và Health Agent.

External Python worker for handwritten weekly journal images. Laravel remains the source of truth and owns durable jobs, private images, machine matching, journal documents, journal rows, status, and exceptions.

The worker:

1. Claims only `WEEKLY_JOURNAL` jobs from Laravel.
2. Downloads a temporary private image through Tailscale.
3. Calls the existing 9router/OpenAI-compatible vision endpoint.
4. Validates and normalizes structured JSON.
5. Sends one document and multiple rows to Laravel.
6. Deletes its temporary image and retries safely on network/model failures.

RapidOCR continues to handle `DAILY_TIMEMARK`. The same worker also claims machine-intake documents and `HANDOVER_REPORT` images; handover OCR is advisory and Laravel requires a human confirmation before changing machine status. The existing Telegram PDF bot remains independent and unchanged.

Daily Photo AI Rescue never scans or enqueues the Manual backlog. It only claims durable attempts explicitly created by Laravel, reads the original stored image, and sends the versioned `daily_photo_rescue_v1` structured result back for Laravel-side validation and protected-state recheck.

After at most `DAILY_PHOTO_AI_RESCUE_MAX_CONSECUTIVE` consecutive rescue jobs (default `3`), the worker gives the existing weekly-journal, handover and intake queues a priority check so an operator bulk request cannot starve them.

## Windows setup

From the repository root:

```powershell
cd journal-worker
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip install -e .
Copy-Item .env.example .env
```

Edit `.env` locally. Use the same Laravel OCR token and the same 9router base URL, key, and vision model as the existing Telegram OCR application. Never commit `.env`.

## Tests

```powershell
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
```

Unit tests do not call Laravel, 9router, or the development database.

## Start manually

```powershell
.\.venv\Scripts\python.exe -m mmtb_journal_worker
```

Logs are stored at `journal-worker/data/worker.log` and rotate daily for 14 days. The process lock prevents two journal workers on one machine.

## Autostart on the 24/7 laptop

After a successful real-image test, run PowerShell as Administrator:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install-autostart.ps1
```

Check status:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/autostart-status.ps1
```

Remove the task:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/uninstall-autostart.ps1
```

Install the real journal worker only on the 24/7 laptop. A development desktop must use mocked unit tests and must not claim office jobs.

## Telegram token logging

The existing Telegram project should set the `httpx` and `httpcore` loggers to `WARNING`. Telegram embeds its bot token in request URLs, so INFO request logging must never be enabled or shared.
