<?php

return [
    'worker_token' => env('GMAIL_INTAKE_WORKER_TOKEN'),
    'minimum_confidence' => (float) env('GMAIL_INTAKE_MINIMUM_CONFIDENCE', 0.85),
];
