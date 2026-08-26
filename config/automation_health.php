<?php

return [
    'degraded_after_seconds' => (int) env('AUTOMATION_DEGRADED_AFTER_SECONDS', 180),
    'offline_after_seconds' => (int) env('AUTOMATION_OFFLINE_AFTER_SECONDS', 300),
    'consecutive_errors' => (int) env('AUTOMATION_CONSECUTIVE_ERRORS', 3),
    'hung_job_minutes' => (int) env('AUTOMATION_HUNG_JOB_MINUTES', 30),
    'command_lease_seconds' => (int) env('AUTOMATION_COMMAND_LEASE_SECONDS', 120),
    'dashboard_url' => env('AUTOMATION_DASHBOARD_URL', rtrim((string) env('APP_URL'), '/').'/automation-health'),
];
