<?php

return [
    'enabled' => (bool) env('DAILY_PHOTOS_ONLY', true),
    // OCR date/time is a naive local wall-clock value printed on the image.
    // It is never derived from the Zalo envelope's sent_at timestamp.
    'capture_timezone' => env('DAILY_PHOTO_CAPTURE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'foundation_version' => 'daily-photo-foundation-v1',
    'pairing_policy_version' => 'daily-photo-pairing-v1',
    'ai_rescue' => [
        'prompt_version' => 'daily_photo_rescue_v1',
        'schema_version' => 'daily_photo_rescue_v1',
        'lease_seconds' => (int) env('DAILY_PHOTO_AI_RESCUE_LEASE_SECONDS', 600),
        'max_attempts' => (int) env('DAILY_PHOTO_AI_RESCUE_MAX_ATTEMPTS', 3),
        'preview_ttl_minutes' => (int) env('DAILY_PHOTO_AI_RESCUE_PREVIEW_TTL_MINUTES', 30),
    ],
];
