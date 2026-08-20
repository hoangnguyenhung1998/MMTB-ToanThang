<?php

namespace App\Services;

use App\Models\ZaloAttachment;

readonly class ZaloIngestionResult
{
    public function __construct(
        public ZaloAttachment $attachment,
        public bool $created,
    ) {
    }
}
