# OpenClaw reconciliation API — Phase 14.1

Laravel remains the source of truth. OpenClaw receives reviewed evidence, returns immutable analysis submissions, and never edits OCR, journal, machine, assignment, or history records.

## Configuration

```env
OPENCLAW_RECONCILIATION_API_TOKEN=
OPENCLAW_RECONCILIATION_LEASE_SECONDS=600
```

All requests use:

```http
Authorization: Bearer <OPENCLAW_RECONCILIATION_API_TOKEN>
Accept: application/json
```

## Claim reviewed machine-days

`POST /api/openclaw/v1/reconciliation/jobs/claim`

```json
{
  "worker_id": "openclaw-home-1",
  "work_date": "2026-08-22",
  "limit": 5
}
```

Laravel creates one queue job per eligible machine and date. Daily images must be auto-approved or manually reviewed. Weekly journal rows must be manually approved or corrected. A `204` response means that no job is available.

If approved evidence changes later (for example, the weekly journal arrives after the daily images), Laravel reopens the same machine-day job. Previous OpenClaw submissions remain unchanged for audit history.

Each claimed job contains the machine, assignment context, reviewed daily OCR evidence, approved weekly rows, and private relative image URLs. Image URLs require the same bearer token.

## Complete a job

`POST /api/openclaw/v1/reconciliation/jobs/{job}/complete`

```json
{
  "worker_id": "openclaw-home-1",
  "submission_uuid": "1d341898-80f8-4dc4-b670-bd8d75fc3445",
  "outcome": "WARNING",
  "summary": "Có ảnh hằng ngày nhưng chưa có dòng nhật trình tương ứng.",
  "confidence": 0.91,
  "agent_name": "mmtb-reconciliation-agent",
  "model": "9router-model",
  "metadata": {},
  "findings": [
    {
      "code": "MISSING_JOURNAL_ROW",
      "severity": "WARNING",
      "title": "Thiếu nhật trình tuần",
      "description": "Chưa tìm thấy dòng nhật trình cùng máy và ngày.",
      "evidence": {
        "daily_image_count": 1,
        "journal_row_count": 0
      },
      "suggested_action": "Kiểm tra nhật trình hoặc yêu cầu bổ sung.",
      "confidence": 0.91
    }
  ]
}
```

Allowed outcomes: `MATCHED`, `WARNING`, `EXCEPTION`, `UNRESOLVED`.

Allowed finding severities: `INFO`, `WARNING`, `CRITICAL`.

`submission_uuid` makes retries idempotent. Laravel never overwrites an existing submission or its findings.

## Report a worker failure

`POST /api/openclaw/v1/reconciliation/jobs/{job}/fail`

```json
{
  "worker_id": "openclaw-home-1",
  "error": "9router timeout",
  "retryable": true
}
```

Retryable failures return to `RETRY`. Non-retryable failures become `FAILED`. Expired leases can be claimed by another worker.
# Deterministic reconciliation (rules-v1)

Before a claim is returned to OpenClaw, Laravel evaluates reviewed daily images
against approved or corrected journal rows for the same machine and work date.

- A complete match is stored as `MATCHED` by `mmtb-rules-engine`.
- Differences above 30 minutes are `WARNING`; above 60 minutes are `EXCEPTION`.
- Duplicate images and exact asset-code mismatches are `EXCEPTION`.
- Missing daily or journal evidence, including a missing shift boundary, remains
  pending for OpenClaw instead of being automatically concluded.
- Overnight rows (`end_time <= start_time`) may use an end image from the next
  calendar date while retaining the journal work date.
- The evidence signature and rules version make automatic submissions
  idempotent and preserve prior history when reviewed evidence changes.

The thresholds can be configured with:

```dotenv
OPENCLAW_RULES_VERSION=rules-v1
OPENCLAW_MATCH_WINDOW_MINUTES=180
OPENCLAW_TIME_WARNING_MINUTES=30
OPENCLAW_TIME_CRITICAL_MINUTES=60
```
