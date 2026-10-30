<?php

return [
    'timezone' => 'Asia/Ho_Chi_Minh',
    'max_upload_kb' => 51200,
    'max_pages' => 300,
    'storage_disk' => env('DOSSIERS_STORAGE_DISK', 'r2'),
    'storage_prefix' => env('DOSSIERS_STORAGE_PREFIX', 'dossiers'),
    'worker' => [
        'token' => env('DOSSIERS_WORKER_TOKEN'),
        'lease_seconds' => 180,
        'max_lease_lifetime' => 3600,
        'max_attempts' => 3,
        'output_max_kb' => 262144,
        'input_max_bytes' => 268435456,
        'allow_mock_results' => false,
    ],
    'allowed_user_ids' => array_values(array_filter(array_map('trim', explode(',', env('DOSSIERS_ALLOWED_USER_IDS', ''))), 'ctype_digit')),
    'queue' => 'dossiers',
    'ai' => [
        'enabled' => env('DOSSIERS_AI_ENABLED', false),
        'auto_analyze' => env('DOSSIERS_AI_AUTO_ANALYZE', false),
        'spacing_seconds' => 10,
    ],
    // Separate opt-in: never inherit recipients from operational alerts.
    'telegram_enabled' => env('DOSSIERS_TELEGRAM_ENABLED', false),
    'telegram_recipients' => array_values(array_filter(explode(',', env('DOSSIERS_TELEGRAM_RECIPIENTS', '')))),
];
