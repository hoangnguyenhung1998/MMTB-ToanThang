<?php

namespace App\Services;

use App\Models\OcrJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DailyPhotoOcrDiagnosticService
{
    public function __construct(
        private readonly DailyPhotoStoredOcrExtractor $extractor,
        private readonly DailyPhotoExceptionReason $reasons,
        private readonly DailyPhotoBacklogService $backlog,
    ) {}

    public function diagnose(array $filters = []): array
    {
        $limit = max(0, (int) ($filters['limit'] ?? 20));
        $samples = collect();
        $counts = collect();
        $total = 0;

        $this->query($filters)->chunkById(500, function (Collection $jobs) use ($filters, $limit, $samples, $counts, &$total): void {
            $analysis = $this->backlog->analyse($jobs)->keyBy(fn (array $row): int => $row['job']->id);
            foreach ($jobs as $job) {
                $row = $this->diagnoseJob($job, $analysis->get($job->id));
                if (($filters['reason'] ?? null) && ! in_array($filters['reason'], $row['current_reasons'], true)) {
                    continue;
                }
                $total++;
                $counts->put($row['loss_stage'], (int) $counts->get($row['loss_stage'], 0) + 1);
                if ($samples->count() < $limit) {
                    $samples->push($row);
                }
            }
        });

        return [
            'total' => $total,
            'by_loss_stage' => $counts->sortDesc()->all(),
            'samples' => $samples,
        ];
    }

    public function diagnoseJob(OcrJob $job, ?array $analysis = null): array
    {
        $analysis ??= $this->backlog->analyse(collect([$job]))->first();
        $raw = $this->extractor->extract($job);
        $parsed = data_get($job->daily_metadata, 'ocr_recovery.final_chosen_result')
            ?: data_get($job->daily_metadata, 'ocr_recovery.initial_extraction')
            ?: [];
        $retry = data_get($job->daily_metadata, 'ocr_recovery.retry_extraction') ?: [];
        $membership = $job->dailyPhotoCaseEvidence;
        $case = $membership?->dailyPhotoCase;

        return [
            'job_id' => $job->id,
            'message_id' => $job->attachment?->message?->id,
            'evidence_id' => $membership?->id,
            'sender' => $job->attachment?->message?->sender_id,
            'received_at' => $job->attachment?->message?->received_at?->toDateTimeString(),
            'raw' => $raw,
            'parsed' => [
                'machine' => $parsed['asset_code'] ?? null,
                'date' => $parsed['date'] ?? null,
                'time' => $parsed['time'] ?? null,
            ],
            'persisted' => [
                'machine' => $job->observed_asset_code ?? $job->asset_code,
                'date' => $job->extracted_date?->format('Y-m-d'),
                'time' => $job->extracted_time,
            ],
            'retry' => [
                'attempts' => (int) $job->ocr_retry_attempts,
                'status' => $job->ocr_retry_reason,
                'machine' => $retry['asset_code'] ?? null,
                'date' => $retry['date'] ?? null,
                'time' => $retry['time'] ?? null,
                'error' => $job->error_message,
            ],
            'resolved' => [
                'machine' => $job->machine?->asset_code,
                'mapping_source' => $job->machine_resolution_method,
            ],
            'canonical' => [
                'evidence_id' => $membership?->id,
                'case_id' => $case?->id,
                'interval_id' => $membership?->startInterval?->id ?? $membership?->endInterval?->id,
                'capture_datetime' => $membership?->capture_datetime?->format('Y-m-d H:i:s'),
            ],
            'current_reasons' => $analysis['reasons'] ?? $this->currentReasons($job),
            'recoverable' => (bool) ($analysis['auto_recoverable'] ?? false),
            'planned_action' => $analysis['action'] ?? 'MANUAL',
            'loss_stage' => $analysis['diagnostic_subtype'] ?? 'OCR_RECOGNITION_FAILURE',
        ];
    }

    private function query(array $filters): Builder
    {
        return OcrJob::query()
            ->with([
                'attachment.message', 'machine:id,asset_code',
                'dailyPhotoCaseEvidence.dailyPhotoCase',
                'dailyPhotoCaseEvidence.startInterval:id,start_evidence_id',
                'dailyPhotoCaseEvidence.endInterval:id,end_evidence_id',
            ])
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('status', 'EXCEPTION')
            ->when($filters['job'] ?? null, fn (Builder $query, int|string $id) => $query->whereKey($id))
            ->when($filters['sender'] ?? null, fn (Builder $query, string $sender) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->where('sender_id', $sender)))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '>=', $date)))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '<=', $date)))
            ->orderBy('id');
    }

    private function currentReasons(OcrJob $job): array
    {
        $reasons = collect($this->reasons->forJob($job));
        if (! $job->machine_id) {
            $reasons->push('SENDER_MAPPING_MISSING');
        }
        if (! $job->extracted_date) {
            $reasons->push('CAPTURE_DATE_MISSING');
        }
        if (! $job->extracted_time) {
            $reasons->push('CAPTURE_TIME_MISSING');
        }

        return $reasons->unique()->values()->all();
    }
}
