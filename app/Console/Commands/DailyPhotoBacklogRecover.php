<?php

namespace App\Console\Commands;

use App\Services\DailyPhotoBacklogService;
use Illuminate\Console\Command;

class DailyPhotoBacklogRecover extends Command
{
    protected $signature = 'ocr:daily-backlog-recover
        {--dry-run : Chỉ báo cáo, tuyệt đối không ghi dữ liệu}
        {--execute : Thực hiện recovery sau khi đã review dry-run}
        {--sender= : Lọc theo sender ID}
        {--reason= : Lọc theo current reason}
        {--job= : Lọc theo OCR job ID}
        {--from= : Ngày nhận từ YYYY-MM-DD}
        {--to= : Ngày nhận đến YYYY-MM-DD}
        {--command-center= : Lọc theo BCH}
        {--project= : Lọc theo project}
        {--limit=20 : Số bản ghi mẫu tối đa trong dry-run}';

    protected $description = 'Dry-run hoặc phục hồi có kiểm soát backlog OCR ảnh hằng ngày';

    public function handle(DailyPhotoBacklogService $service): int
    {
        if (! $this->option('dry-run') && ! $this->option('execute')) {
            $this->error('Phải chọn --dry-run hoặc --execute. Recovery không tự chạy ngầm.');

            return self::INVALID;
        }
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Không dùng đồng thời --dry-run và --execute.');

            return self::INVALID;
        }

        $filters = array_filter([
            'sender_id' => $this->option('sender'),
            'reason' => $this->option('reason'),
            'job' => $this->option('job'),
            'date_from' => $this->option('from'),
            'date_to' => $this->option('to'),
            'command_center_id' => $this->option('command-center'),
            'project_id' => $this->option('project'),
        ], fn (mixed $value): bool => filled($value));
        $filters['limit'] = max(0, min(100, (int) $this->option('limit')));

        if ($this->option('dry-run')) {
            $preview = $service->recoveryPreview($filters);
            $this->table(['Metric', 'Count'], collect($preview)->except(['by_loss_stage', 'by_actionable_subtype', 'samples'])
                ->map(fn (mixed $count, string $metric): array => [$metric, $count])->values()->all());
            $this->table(['Actionable subtype', 'Count'], collect($preview['by_actionable_subtype'])
                ->map(fn (int $count, string $stage): array => [$stage, $count])->values()->all());
            $this->table(['Job', 'Action', 'Subtype', 'Machine', 'Date', 'Time', 'Image type'], collect($preview['samples'])
                ->map(fn (array $sample): array => array_values($sample))->all());

            return self::SUCCESS;
        }

        $result = $service->recover($filters);
        $this->table(['Metric', 'Count'], collect($result)
            ->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());

        return self::SUCCESS;
    }
}
