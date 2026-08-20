<?php

return [
    'token' => env('COLLECTOR_API_TOKEN'),
    'disk' => env('COLLECTOR_STORAGE_DISK', 'local'),
    'directory' => env('COLLECTOR_STORAGE_DIRECTORY', 'zalo'),
    'max_file_kilobytes' => (int) env('COLLECTOR_MAX_FILE_KILOBYTES', 25600),
];
