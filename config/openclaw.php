<?php

return [
    'reconciliation_token' => env('OPENCLAW_RECONCILIATION_API_TOKEN'),
    'lease_seconds' => (int) env('OPENCLAW_RECONCILIATION_LEASE_SECONDS', 600),
    'rules' => [
        'version' => env('OPENCLAW_RULES_VERSION', 'rules-v1'),
        'match_window_minutes' => (int) env('OPENCLAW_MATCH_WINDOW_MINUTES', 180),
        'warning_minutes' => (int) env('OPENCLAW_TIME_WARNING_MINUTES', 30),
        'critical_minutes' => (int) env('OPENCLAW_TIME_CRITICAL_MINUTES', 60),
    ],
];
