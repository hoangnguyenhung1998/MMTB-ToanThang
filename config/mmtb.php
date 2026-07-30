<?php

return [
    'operation_center' => [
        'expiry_days' => (int) env('MMTB_EXPIRY_DAYS', 30),
        'list_limit' => (int) env('MMTB_OPERATION_LIST_LIMIT', 20),
    ],

    'notifications' => [
        'sync_schedule' => env('MMTB_NOTIFICATION_SCHEDULE', 'hourly'),
    ],
];
