<?php

return [
    'worker_token' => env('OCR_WORKER_API_TOKEN'),
    'lease_seconds' => (int) env('OCR_JOB_LEASE_SECONDS', 300),
    'minimum_confidence' => (float) env('OCR_MINIMUM_CONFIDENCE', 0.80),
    'review_sample_percent' => (int) env('OCR_REVIEW_SAMPLE_PERCENT', 3),
    'rate_limit_per_minute' => (int) env('OCR_WORKER_RATE_LIMIT_PER_MINUTE', 240),
];
