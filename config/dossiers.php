<?php

return [
    'timezone' => 'Asia/Ho_Chi_Minh',
    'max_upload_kb' => 51200,
    'max_pages' => 300,
    'pdftoppm' => env('DOSSIERS_PDFTOPPM', 'pdftoppm'),
    'pdfinfo' => env('DOSSIERS_PDFINFO', 'pdfinfo'),
    'qpdf' => env('DOSSIERS_QPDF', 'qpdf'),
    'allowed_user_ids' => array_values(array_filter(array_map('trim', explode(',', env('DOSSIERS_ALLOWED_USER_IDS', ''))), 'ctype_digit')),
    'queue' => 'dossiers',
    'ai' => [
        'enabled' => env('DOSSIERS_AI_ENABLED', false),
        'auto_analyze' => env('DOSSIERS_AI_AUTO_ANALYZE', false),
        'python' => env('DOSSIERS_AI_PYTHON', 'python'),
        'endpoint' => env('DOSSIERS_AI_ENDPOINT'),
        'key' => env('DOSSIERS_AI_API_KEY'),
        'model' => env('DOSSIERS_AI_MODEL'),
        'timeout' => 180,
        'spacing_seconds' => 10,
    ],
    // Separate opt-in: never inherit recipients from operational alerts.
    'telegram_enabled' => env('DOSSIERS_TELEGRAM_ENABLED', false),
    'telegram_recipients' => array_values(array_filter(explode(',', env('DOSSIERS_TELEGRAM_RECIPIENTS', '')))),
];
