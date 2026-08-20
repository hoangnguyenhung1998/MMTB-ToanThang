# OCR Worker API

The OCR application remains an external process. Laravel owns the durable job state, private image files, extracted fields, machine match, shift classification, and exceptions.

## Configuration

Set a separate long random token in Laravel `.env`:

```env
OCR_WORKER_API_TOKEN=
OCR_JOB_LEASE_SECONDS=300
OCR_MINIMUM_CONFIDENCE=0.80
```

Send the token as `Authorization: Bearer ...` on every OCR request. Never commit it.

## Flow

1. `POST /api/ocr/v1/jobs/claim` with `{"worker_id":"ocr-home-1"}`.
2. A `200` response contains one job and its authenticated `image_url`; `204` means the queue is empty.
3. Download the image before the five-minute lease expires.
4. Submit OCR data to `POST /api/ocr/v1/jobs/{id}/complete`.
5. If processing fails, call `POST /api/ocr/v1/jobs/{id}/fail` with `retryable=true` to return it to the queue.

Expired `PROCESSING` jobs can be claimed again. A result is accepted only from the worker that currently owns the lease.

## Complete payload

```json
{
  "worker_id": "ocr-home-1",
  "date": "2026-08-20",
  "time": "16:45:00",
  "asset_code": "T-XL0354",
  "operator_name": "Le The Vy",
  "phone": "0367756204",
  "work_location": "Ha Long Xanh",
  "confidence": 0.96,
  "raw_text": "full OCR text"
}
```

Laravel assigns one deterministic shift: `MORNING`, `MIDDAY`, `AFTERNOON`, `AFTERNOON_OT`, or `EVENING_OT`. Results requiring review are stored as `EXCEPTION` with codes such as `LOW_CONFIDENCE`, `MISSING_DATE`, `MISSING_TIME`, `UNCLASSIFIED_TIME`, `MISSING_ASSET_CODE`, `UNKNOWN_ASSET_CODE`, and `WRONG_DATE`.
