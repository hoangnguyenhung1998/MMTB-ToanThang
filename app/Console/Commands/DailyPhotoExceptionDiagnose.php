<?php

namespace App\Console\Commands;

use App\Services\DailyPhotoOcrDiagnosticService;
use Illuminate\Console\Command;

class DailyPhotoExceptionDiagnose extends Command
{
    protected $signature = 'ocr:daily-exception-diagnose
        {--limit=20 : Số sample chi tiết}
        {--sender= : Lọc theo sender ID}
        {--reason= : Lọc theo current reason}
        {--job= : Lọc theo OCR job ID}
        {--from= : Ngày nhận từ YYYY-MM-DD}
        {--to= : Ngày nhận đến YYYY-MM-DD}';

    protected $description = 'Chẩn đoán read-only vị trí mất dữ liệu OCR ảnh hằng ngày';

    public function handle(DailyPhotoOcrDiagnosticService $service): int
    {
        $report = $service->diagnose(array_filter([
            'limit' => $this->option('limit'),
            'sender' => $this->option('sender'),
            'reason' => $this->option('reason'),
            'job' => $this->option('job'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ], fn (mixed $value): bool => filled($value)));

        $this->info('Total: '.$report['total']);
        $this->table(['LOSS_STAGE', 'Jobs'], collect($report['by_loss_stage'])
            ->map(fn (int $count, string $stage): array => [$stage, $count])->values()->all());

        foreach ($report['samples'] as $sample) {
            $this->newLine();
            $this->line(sprintf(
                'job_id=%s message_id=%s evidence_id=%s sender=%s received_at=%s',
                $sample['job_id'], $sample['message_id'] ?? '-', $sample['evidence_id'] ?? '-',
                $sample['sender'] ?? '-', $sample['received_at'] ?? '-',
            ));
            foreach (['raw', 'parsed', 'persisted', 'retry', 'resolved', 'canonical'] as $section) {
                $this->line(strtoupper($section).': '.json_encode($sample[$section], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $this->line('CURRENT REASONS: '.implode(', ', $sample['current_reasons']));
            $this->line('LOSS_STAGE: '.$sample['loss_stage']);
        }

        return self::SUCCESS;
    }
}
