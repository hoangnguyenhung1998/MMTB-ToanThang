<?php

return [
    'operation_center' => [
        'expiry_days' => (int) env('MMTB_EXPIRY_DAYS', 30),
        'list_limit' => (int) env('MMTB_OPERATION_LIST_LIMIT', 20),
    ],

    'notifications' => [
        'sync_schedule' => env('MMTB_NOTIFICATION_SCHEDULE', 'hourly'),
    ],

    'reconciliation_alerts' => [
        'waiting_evidence_hours' => (int) env('MMTB_RECONCILIATION_WAITING_ALERT_HOURS', 2),
        'dashboard_url' => env('MMTB_RECONCILIATION_DASHBOARD_URL'),
        'retry_minutes' => (int) env('MMTB_RECONCILIATION_ALERT_RETRY_MINUTES', 5),
        'max_attempts' => (int) env('MMTB_RECONCILIATION_ALERT_MAX_ATTEMPTS', 5),
    ],
];
