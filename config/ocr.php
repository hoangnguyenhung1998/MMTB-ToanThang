<?php

return [
    'worker_token' => env('OCR_WORKER_API_TOKEN'),
    'lease_seconds' => (int) env('OCR_JOB_LEASE_SECONDS', 300),
    'minimum_confidence' => (float) env('OCR_MINIMUM_CONFIDENCE', 0.80),
    'review_sample_percent' => (int) env('OCR_REVIEW_SAMPLE_PERCENT', 3),
    'rate_limit_per_minute' => (int) env('OCR_WORKER_RATE_LIMIT_PER_MINUTE', 240),
    'monitoring' => [
        'timezone' => env('OCR_MONITORING_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'deadline' => env('OCR_DAILY_DEADLINE', '23:59'),
        'stalled_minutes' => (int) env('OCR_STALLED_MINUTES', 15),
        'warning_buffer_minutes' => (int) env('OCR_WARNING_BUFFER_MINUTES', 60),
        'recent_limit' => (int) env('OCR_MONITORING_RECENT_LIMIT', 50),
    ],
];
