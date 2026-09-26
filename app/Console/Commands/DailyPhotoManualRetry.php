<?php

namespace App\Console\Commands;

use App\Services\DailyPhotoManualRetryService;
use Illuminate\Console\Command;

class DailyPhotoManualRetry extends Command
{
    protected $signature = 'ocr:daily-manual-retry
        {--dry-run : Chỉ báo cáo, tuyệt đối không ghi dữ liệu}
        {--execute : Xếp lại hàng đợi sau khi đã review dry-run}
        {--retry-version= : Cho phép retry đúng một lần cho phiên bản pipeline được chỉ định}
        {--sample-limit=20 : Số bản ghi mẫu tối đa}';

    protected $description = 'Xếp lại hàng đợi OCR cho Daily Photo đang cần xử lý thủ công, với đầy đủ protection guard';

    public function handle(DailyPhotoManualRetryService $service): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('execute')) {
            $this->error('Phải chọn đúng một trong --dry-run hoặc --execute.');

            return self::INVALID;
        }

        $sampleLimit = max(0, min(100, (int) $this->option('sample-limit')));
        $retryVersion = $this->option('retry-version');

        try {
            $result = $this->option('execute')
                ? $service->execute($sampleLimit, null, is_string($retryVersion) ? $retryVersion : null)
                : $service->preview($sampleLimit, is_string($retryVersion) ? $retryVersion : null);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->table(
            ['Metric', 'Count'],
            collect($result)->except('samples')
                ->map(fn (mixed $count, string $metric): array => [$metric, $count])
                ->values()->all(),
        );
        if ($result['samples'] !== []) {
            $this->table(
                ['Job ID', 'Machine', 'Date', 'Time', 'Current status', 'UI Manual reason/state', 'Eligible', 'Skip reason', 'Source image', 'Re-OCR attempted'],
                collect($result['samples'])->map(fn (array $sample): array => array_values($sample))->all(),
            );
        }

        return self::SUCCESS;
    }
}
