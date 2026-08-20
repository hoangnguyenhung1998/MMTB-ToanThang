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

`GET /api/ocr/v1/machines` returns the current Laravel machine catalog for OCR matching. The worker must not maintain a separate machine database.

## Flow

1. A classifier claims `UNKNOWN` jobs, identifies the document type, then calls the classify endpoint.
2. RapidOCR claims `DAILY_TIMEMARK`; OpenClaw claims `WEEKLY_JOURNAL`.
3. Download the private source image before the five-minute lease expires.
4. Submit one TimeMark result to `/complete` or multiple journal rows to `/complete-journal`.
5. If processing fails, call `/fail` with `retryable=true` to return it to the queue.

Claim only the types supported by that worker:

```json
{
  "worker_id": "rapid-ocr-home-1",
  "document_types": ["DAILY_TIMEMARK"]
}
```

The valid types are `UNKNOWN`, `DAILY_TIMEMARK`, and `WEEKLY_JOURNAL`. Omitting `document_types` remains supported for backward compatibility.

## Classify an unknown image

`POST /api/ocr/v1/jobs/{id}/classify`

```json
{
  "worker_id": "classifier-home-1",
  "document_type": "WEEKLY_JOURNAL",
  "confidence": 0.98
}
```

Classification releases the job back to `PENDING` so the appropriate worker can claim it.
If classification is uncertain, submit `UNKNOWN`; Laravel stores the job as `EXCEPTION` with `UNCLASSIFIED_DOCUMENT` instead of guessing.

## Complete a daily TimeMark image

`POST /api/ocr/v1/jobs/{id}/complete`

```json
{
  "worker_id": "rapid-ocr-home-1",
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

Laravel assigns one deterministic shift: `MORNING`, `MIDDAY`, `AFTERNOON`, `AFTERNOON_OT`, or `EVENING_OT`.

## Complete a weekly journal

`POST /api/ocr/v1/jobs/{id}/complete-journal`

```json
{
  "worker_id": "openclaw-home-1",
  "asset_code": "T-XL0354",
  "confidence": 0.94,
  "raw_text": "full journal OCR text",
  "rows": [
    {
      "row_number": 1,
      "work_date": "2026-08-17",
      "start_time": "07:00:00",
      "end_time": "11:00:00",
      "total_minutes": 240,
      "work_content": "Thi cong dao dat",
      "quantity": 60,
      "unit": "m3",
      "work_location": "Ha Long Xanh",
      "operator_name": "Nguyen Van Cuong",
      "confidence": 0.91,
      "raw_data": {}
    }
  ]
}
```

One journal image creates one `journal_documents` record and one or more immutable `journal_rows`. Results requiring review are stored as `EXCEPTION`; uncertain rows are retained with their own exception list.

## Source-image traceability

The original file is never overwritten. Both daily results and journal rows remain linked through `ocr_job -> zalo_attachment`, including storage disk, relative path, SHA-256, Zalo message, sender, and sent time. This supports later lookup by machine and work date and downloading selected originals.

The claim response returns `image_url` as a relative URL. Workers resolve it against the configured Laravel/Tailscale origin, preventing a server-side `localhost` address from leaking into remote downloads.

Expired `PROCESSING` jobs can be claimed again. A result is accepted only from the worker that currently owns the lease.
