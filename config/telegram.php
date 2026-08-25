<?php

return [
    'enabled' => env('TELEGRAM_ALERTS_ENABLED', false),
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'timeout_seconds' => (int) env('TELEGRAM_TIMEOUT_SECONDS', 10),
];
