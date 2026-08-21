# MMTB Journal Worker

External Python worker for handwritten weekly journal images. Laravel remains the source of truth and owns durable jobs, private images, machine matching, journal documents, journal rows, status, and exceptions.

The worker:

1. Claims only `WEEKLY_JOURNAL` jobs from Laravel.
2. Downloads a temporary private image through Tailscale.
3. Calls the existing 9router/OpenAI-compatible vision endpoint.
4. Validates and normalizes structured JSON.
5. Sends one document and multiple rows to Laravel.
6. Deletes its temporary image and retries safely on network/model failures.

RapidOCR continues to handle `DAILY_TIMEMARK`. The existing Telegram PDF bot remains independent and unchanged.

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
