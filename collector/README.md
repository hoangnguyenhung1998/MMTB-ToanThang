# MMTB Zalo Collector

Node.js service that listens to image messages from explicitly allowed Zalo groups, stores them in a durable local queue, and forwards them to the Laravel collector API.

`zca-js` is an unofficial personal-account API that simulates Zalo Web. Use a dedicated secondary account. The account may be limited or locked by Zalo. Only one Zalo Web listener can run for the account at a time.

## Requirements

- Node.js 22.13 or newer (uses the built-in SQLite module)
- The Laravel API and its `COLLECTOR_API_TOKEN`
- A secondary Zalo account already added to the required work groups

## Install

From the Laravel project directory:

```cmd
cd collector
copy .env.example .env
npm install
```

Edit `.env`:

- `LARAVEL_API_URL`: full Laravel ingestion endpoint. Keep `/public` only when that is part of the current Laragon URL.
- `COLLECTOR_API_TOKEN`: must equal the Laravel `.env` value.
- `ZALO_ALLOWED_GROUP_IDS`: comma-separated group IDs. Empty stops the collector safely.

Also set the Laravel project `.env`:

```env
COLLECTOR_API_TOKEN=the-same-long-random-token
```

## Run

```cmd
npm run check
npm start
```

On first run, scan `collector/data/qr.png` with the secondary Zalo account. Credentials are saved locally under `collector/data/` and never committed. Do not open Zalo Web with the same account while the collector is running.

## Multiple local Zalo accounts

The legacy `data/credentials.json` and `ZALO_ALLOWED_GROUP_IDS` continue to work
until a managed account is explicitly activated. Account credentials always stay
on the laptop under `data/accounts/<account-id>/credentials.json`.

Stop the scheduled Collector before login or account maintenance. Import the
current legacy session without deleting the original:

```powershell
Stop-ScheduledTask -TaskName "MMTB-ZaloCollector"
npm run accounts -- import-legacy --id zalo-test --name "Zalo kiểm thử"
npm run accounts -- activate --id zalo-test
Start-ScheduledTask -TaskName "MMTB-ZaloCollector"
```

Create and log in to another account. Group IDs are specific to that account:

```powershell
npm run accounts -- add --id zalo-company --name "Zalo công ty" --groups "GROUP_ID_1,GROUP_ID_2"
npm run accounts -- login --id zalo-company
```

Login and Collector startup cache only safe group names/IDs under
`data/accounts/<account-id>/groups.json`. Cookies, IMEI, user-agent and QR data
are never included in this catalog or sent to Laravel. Refresh a specific
account's catalog while the scheduled Collector is stopped:

```powershell
npm run groups -- --id zalo-company
```

Update the name or allowed groups without changing the saved session:

```powershell
npm run accounts -- update --id zalo-company --groups "GROUP_ID_1,GROUP_ID_2,GROUP_ID_3"
```

The login command saves the QR image at
`data/accounts/zalo-company/qr.png`. It never prints cookies or session values.
List profiles and switch the scheduled Collector safely:

```powershell
npm run accounts -- list
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/switch-account.ps1 -AccountId zalo-company
```

Only one profile can be active at a time. The existing shared durable image
queue is preserved when accounts are switched. Phase 16.7.2A is laptop-local;
it does not upload Zalo cookies, IMEI, or user-agent values to Laravel.

Phase 16.7.3 adds a dedicated Laravel account page. It receives only safe
account/group metadata and sends allowlisted account-switch or group-update
commands back to the Health Agent. Updating the active account's groups restarts
the Collector so the new allowlist takes effect immediately.

## Durable queue

Every accepted Zalo image becomes a SQLite queue job under `collector/data/queue.sqlite`. The worker downloads the original image to `collector/data/queue-images/` before it depends on Laravel being online.

Queue flow:

```text
QUEUED -> DOWNLOADED -> SENDING -> SENT
                         |          |
                         v          v
                       RETRY    deleted after retention
                         |
                         v
                       FAILED
```

- If Laravel is offline, downloaded images remain on disk and retry with exponential backoff up to 15 minutes.
- Restarting Windows or the Collector resumes unfinished queue jobs.
- New Zalo images are downloaded before old API retries, preventing an offline Laravel server from blocking new image capture.
- A message ID and attachment index can only enter the queue once.
- `SENT` images are retained for 7 days by default, then both queue record and unreferenced local file are removed.
- Permanent validation failures and jobs exceeding the retry limit remain as `FAILED` for inspection; they are not silently deleted.

Useful `.env` controls:

```env
COLLECTOR_RETRY_BASE_DELAY_MS=1000
COLLECTOR_RETRY_MAX_DELAY_MS=900000
COLLECTOR_QUEUE_POLL_MS=5000
COLLECTOR_QUEUE_MAX_ATTEMPTS=100
COLLECTOR_SENT_RETENTION_DAYS=7
```

The queue protects images already received from Zalo when Laravel or the office computer is offline. It cannot capture messages while the Collector computer itself is powered off or disconnected from Zalo.

## Windows auto-start

After `.env` is configured, QR login succeeds, and the durable queue is tested, stop the foreground `npm start` process and install the scheduled task:

```cmd
npm run autostart:install
```

The task starts at Windows logon in a hidden PowerShell window. `run-forever.ps1` restarts the Node process 10 seconds after an unexpected exit. A process lock prevents a foreground Collector and the scheduled Collector from listening to the same Zalo account simultaneously.

Check task state and the latest log lines:

```cmd
npm run autostart:status
```

The log is stored at `collector/data/collector.log` and rotates at 10 MB. Remove only the scheduled task with:

```cmd
npm run autostart:remove
```

Removing auto-start preserves `.env`, Zalo credentials, the SQLite queue, and all queued images.

To discover a group ID during initial setup, temporarily inspect the listener log in development or use `api.getAllGroups()` from a local diagnostic script. Do not enable collection until the intended group IDs have been verified.

## Security

- Never commit `.env`, `credentials.json`, QR images, or downloaded Zalo images.
- Use HTTPS when Laravel runs outside the same computer.
- Rotate the API token if it appears in logs, chat, screenshots, or Git history.
- Start with one test group before enabling work groups.
