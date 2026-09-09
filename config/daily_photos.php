<?php

return [
    'enabled' => (bool) env('DAILY_PHOTOS_ONLY', true),
    // OCR date/time is a naive local wall-clock value printed on the image.
    // It is never derived from the Zalo envelope's sent_at timestamp.
    'capture_timezone' => env('DAILY_PHOTO_CAPTURE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'foundation_version' => 'daily-photo-foundation-v1',
];
