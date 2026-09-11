<?php

namespace App\Console\Commands;

use App\Services\DailyPhotoBacklogService;
use App\Services\DailyPhotoExceptionReason;
use Illuminate\Console\Command;

class DailyPhotoBacklogReport extends Command
{
    protected $signature = 'ocr:daily-backlog-report
        {--sender= : Lọc theo sender ID}
        {--from= : Ngày nhận từ YYYY-MM-DD}
        {--to= : Ngày nhận đến YYYY-MM-DD}
        {--reason= : Lọc theo reason chuẩn hóa}
        {--command-center= : Lọc theo BCH}
        {--project= : Lọc theo project}';

    protected $description = 'Báo cáo read-only ngoại lệ ảnh hằng ngày và khả năng tự phục hồi';

    public function handle(DailyPhotoBacklogService $service): int
    {
        $report = $service->report(array_filter([
            'sender_id' => $this->option('sender'),
            'date_from' => $this->option('from'),
            'date_to' => $this->option('to'),
            'reason' => $this->option('reason'),
            'command_center_id' => $this->option('command-center'),
            'project_id' => $this->option('project'),
        ], fn ($value) => filled($value)));

        $this->info('Tổng exception: '.$report['total']);
        $this->line('Ảnh có mapping hiệu lực: '.$report['mapped']);
        $this->line('Ảnh chưa có mapping hiệu lực: '.$report['unmapped']);
        $this->line('Có khả năng auto recover: '.$report['auto_recoverable']);
        $this->line('Cần manual: '.$report['manual']);
        $this->newLine();
        $this->table(['Reason', 'Mô tả', 'Số ảnh'], collect($report['by_reason'])->map(
            fn (int $count, string $reason): array => [$reason, DailyPhotoExceptionReason::LABELS[$reason] ?? 'Ngoại lệ khác', $count]
        )->values()->all());
        $this->table(['Sender', 'Tên', 'Tổng', 'Mapped', 'Auto recover'], collect($report['by_sender'])->map(
            fn (array $row, string $sender): array => [$sender, $row['sender_name'], $row['total'], $row['mapped'], $row['auto_recoverable']]
        )->values()->all());

        return self::SUCCESS;
    }
}
