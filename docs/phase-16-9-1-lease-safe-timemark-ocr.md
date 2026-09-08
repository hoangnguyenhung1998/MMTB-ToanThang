# Phase 16.9.1 — Lease-Safe TimeMark OCR Hotfix

## Protocol

Laravel remains the durable owner of OCR jobs. Every claim increments `ocr_jobs.attempts` atomically; the returned `attempt` is the fencing identity for that claim. Renew, classify, complete, complete-journal, and fail must refer to the same worker and attempt while the lease remains valid.

The lease proves worker liveness and ownership. It is not a processing timeout. The worker renews in an independent background thread so a long RapidOCR engine call does not prevent renewal. Before any terminal API call, the worker stops the renewal thread, performs one final synchronous renewal, then sends the result. If ownership cannot be proven, it discards the result without a terminal side effect.

## Bounds

- `OCR_JOB_LEASE_SECONDS=300`: server-side lease window.
- `OCR_JOB_MAX_ATTEMPTS=3`: server-authoritative claim/retry limit.
- `OCR_LEASE_RENEW_INTERVAL_SECONDS=60`: worker renewal interval, capped at one-third of the lease returned by Laravel.
- `OCR_PROCESSING_BUDGET_SECONDS=900`: independent wall-clock budget for one local attempt.

Retryable worker failures become `RETRY` only below the server max-attempt limit. An expired final attempt becomes terminal `FAILED`, while its last processing run is recorded as `TIMED_OUT`. Jobs are not silently dropped or reclaimed forever.

Laravel materializes expired leases every minute: attempts below the limit become `RETRY`, and the final expired attempt becomes `FAILED`. Claim also performs the same bounded sweep before selecting work, so recovery does not depend solely on scheduler timing.

Processing budget cancellation is cooperative. A native RapidOCR call is not killed forcibly; the independent heartbeat preserves ownership during the call, and the budget is enforced at the next safe boundary.

## Telemetry and monitoring

Worker logs correlate `job_id`, `attempt`, and `worker_id` with processing start/end, download duration, TimeMark rotation/region engine duration, renewal success/failure, budget expiry, completion request/result, and ownership loss. Logs do not include images, tokens, or authorization headers.

The OCR monitoring table separately labels queue wait, current-attempt runtime, cumulative processing time, and total job age. A timed-out prior attempt can therefore be distinguished from a newly running retry.

## Backward-compatible rollout

1. Deploy Laravel/API with `OCR_ENFORCE_ATTEMPT_FENCING=false`.
2. Verify an old worker can still claim, download, classify, complete, and fail without an attempt field.
3. Update and restart only the RapidOCR worker with the renewal interval and processing budget configured.
4. Verify renew responses, current attempt completion, bounded retry, and monitoring telemetry on controlled jobs.
5. After all OCR workers send attempt identity, set `OCR_ENFORCE_ATTEMPT_FENCING=true` and clear Laravel configuration cache.

The compatibility window intentionally accepts missing attempt values for old workers. Requests that include an attempt are always fenced immediately. Renew always requires an attempt.

## Rollback

Rollback the worker first; Laravel with enforcement disabled still accepts the old worker protocol. If strict enforcement has already been enabled, disable it and clear configuration cache before starting an old worker. The additive API can remain deployed. Rolling Laravel back while the new worker is still running makes renew return an error; the worker safely abandons results but processing pauses until the worker is also rolled back.

No migration is required. Existing attempts and processing-run history remain valid.
