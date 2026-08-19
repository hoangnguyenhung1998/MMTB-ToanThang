# MMTB Zalo Collector

Node.js service that listens to image messages from explicitly allowed Zalo groups and forwards them to the Laravel collector API.

`zca-js` is an unofficial personal-account API that simulates Zalo Web. Use a dedicated secondary account. The account may be limited or locked by Zalo. Only one Zalo Web listener can run for the account at a time.

## Requirements

- Node.js 20 or newer
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

To discover a group ID during initial setup, temporarily inspect the listener log in development or use `api.getAllGroups()` from a local diagnostic script. Do not enable collection until the intended group IDs have been verified.

## Security

- Never commit `.env`, `credentials.json`, QR images, or downloaded Zalo images.
- Use HTTPS when Laravel runs outside the same computer.
- Rotate the API token if it appears in logs, chat, screenshots, or Git history.
- Start with one test group before enabling work groups.
