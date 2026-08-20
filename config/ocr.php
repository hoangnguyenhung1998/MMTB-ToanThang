<?php

return [
    'worker_token' => env('OCR_WORKER_API_TOKEN'),
    'lease_seconds' => (int) env('OCR_JOB_LEASE_SECONDS', 300),
    'minimum_confidence' => (float) env('OCR_MINIMUM_CONFIDENCE', 0.80),
];
