<?php

return [
    'degraded_after_seconds' => (int) env('AUTOMATION_DEGRADED_AFTER_SECONDS', 180),
    'offline_after_seconds' => (int) env('AUTOMATION_OFFLINE_AFTER_SECONDS', 300),
    'consecutive_errors' => (int) env('AUTOMATION_CONSECUTIVE_ERRORS', 3),
];
