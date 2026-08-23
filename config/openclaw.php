<?php

return [
    'reconciliation_token' => env('OPENCLAW_RECONCILIATION_API_TOKEN'),
    'lease_seconds' => (int) env('OPENCLAW_RECONCILIATION_LEASE_SECONDS', 600),
];
