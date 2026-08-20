# MMTB RapidOCR Worker

External Python worker for daily TimeMark images. Laravel remains the source of truth and owns jobs, original images, machine matching, status, and exceptions.

The worker performs two tasks:

1. Classify `UNKNOWN` images as `DAILY_TIMEMARK`, `WEEKLY_JOURNAL`, or `UNKNOWN`.
2. Process `DAILY_TIMEMARK` images with RapidOCR and return structured data to Laravel.

Weekly handwritten journal OCR is intentionally left for OpenClaw in Phase 13.3.

## Local setup (Windows, Python 3.12)

From the repository root:

```powershell
cd ocr-worker
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip install -e .
Copy-Item .env.example .env
```

Edit `.env` locally. Use the same `OCR_WORKER_API_TOKEN` configured in Laravel and never commit it.

## Tests

```powershell
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
```

Parser and classifier tests do not change Laravel or the development database.

## Start manually

```powershell
.\.venv\Scripts\python.exe -m mmtb_ocr_worker
```

The worker:

- loads machine codes from authenticated Laravel API;
- claims only `DAILY_TIMEMARK` and `UNKNOWN` jobs;
- downloads a temporary copy of the private source image;
- sends results back to Laravel;
- deletes only its temporary copy;
- waits and retries when the office Laravel server is unavailable;
- prevents two worker processes on the same machine.

Logs are stored at `ocr-worker/data/worker.log` and rotate daily for 14 days.

## Autostart on the 24/7 laptop

Run PowerShell as Administrator:

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

Install autostart only on the 24/7 laptop. A development desktop may run the worker manually against a local test environment, but should not claim real office jobs at the same time.
