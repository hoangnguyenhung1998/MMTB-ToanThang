<?php

namespace App\Console\Commands;

use App\Models\OcrJob;
use Illuminate\Console\Command;

class AutoApproveDailyOcr extends Command
{
    protected $signature = 'ocr:auto-approve-daily';

    protected $description = 'Tự duyệt ảnh hằng ngày đã đủ mã máy, ngày và giờ';

    public function handle(): int
    {
        $count = OcrJob::query()
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('review_status', 'PENDING')
            ->whereNotNull('machine_id')
            ->whereNotNull('extracted_date')
            ->whereNotNull('extracted_time')
            ->whereHas('machine')
            ->update([
                'status' => 'COMPLETED',
                'review_status' => 'AUTO_APPROVED',
                'review_flags' => null,
                'exceptions' => null,
                'updated_at' => now(),
            ]);

        $this->info("Đã tự duyệt {$count} ảnh hằng ngày đủ mã máy, ngày và giờ.");

        return self::SUCCESS;
    }
}
