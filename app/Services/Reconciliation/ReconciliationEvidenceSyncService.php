<?php

namespace App\Services\Reconciliation;

use App\Models\AiReconciliationJob;
use App\Models\JournalRow;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationEvidenceSyncService
{
    private const DAILY_REVIEW_STATUSES = ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'];

    private const JOURNAL_REVIEW_STATUSES = ['APPROVED', 'CORRECTED'];

    public function __construct(private readonly ReconciliationTimeAllocator $allocator)
    {
    }

    public function sync(ReconciliationPeriod $period, ?int $machineId = null, ?string $workDate = null): array
    {
        if (!in_array($period->status, ['GENERATED', 'REVIEWING'], true)) {
            throw new RuntimeException('Chỉ đồng bộ bằng chứng khi kỳ đã sinh dữ liệu hoặc đang kiểm tra.');
        }

        $rows = $period->rows()
            ->when($machineId, fn ($query) => $query->where('machine_id', $machineId))
            ->when($workDate, fn ($query) => $query->whereDate('work_date', $workDate))
            ->get();
        $machineIds = $rows->pluck('machine_id')->unique()->values();

        $journalRows = JournalRow::query()
            ->whereBetween('work_date', [$period->date_from, $period->date_to])
            ->whereHas('document', fn ($query) => $query->whereIn('machine_id', $machineIds))
            ->whereHas('document.ocrJob', fn ($query) => $query->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
            ->with('document:id,machine_id,ocr_job_id')
            ->orderBy('work_date')->orderBy('start_time')->get()
            ->groupBy(fn (JournalRow $row) => $row->document->machine_id.'|'.$row->work_date?->format('Y-m-d'));

        $dailyJobs = OcrJob::query()
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereIn('machine_id', $machineIds)
            ->whereBetween('extracted_date', [$period->date_from, $period->date_to])
            ->whereIn('review_status', self::DAILY_REVIEW_STATUSES)
            ->orderBy('extracted_date')->orderBy('extracted_time')->get()
            ->groupBy(fn (OcrJob $job) => $job->machine_id.'|'.$job->extracted_date?->format('Y-m-d'));

        $aiJobs = AiReconciliationJob::query()
            ->whereIn('machine_id', $machineIds)
            ->whereBetween('work_date', [$period->date_from, $period->date_to])
            ->with('latestSubmission')
            ->get()
            ->keyBy(fn (AiReconciliationJob $job) => $job->machine_id.'|'.$job->work_date?->format('Y-m-d'));

        $result = ['updated' => 0, 'protected' => 0, 'changed' => 0];
        DB::transaction(function () use ($rows, $journalRows, $dailyJobs, $aiJobs, &$result): void {
            foreach ($rows as $row) {
                $key = $row->machine_id.'|'.$row->work_date->format('Y-m-d');
                $journals = $this->forSegment(collect($journalRows->get($key, [])), $row);
                $daily = $this->forSegment(collect($dailyJobs->get($key, [])), $row);
                $aiJob = $aiJobs->get($key);
                $submission = $aiJob?->latestSubmission;
                $signature = $this->signature($daily, $journals, $aiJob, $submission);
                $status = $this->evidenceStatus($daily, $journals, $aiJob, $submission);
                $protected = $row->status === 'CONFIRMED' || $row->manually_edited_at !== null;
                $changed = $row->evidence_signature !== null && $row->evidence_signature !== $signature;

                $provenance = [
                    'evidence_status' => $status,
                    'daily_ocr_job_ids' => $daily->pluck('id')->values()->all() ?: null,
                    'journal_row_ids' => $journals->pluck('id')->values()->all() ?: null,
                    'ai_reconciliation_job_id' => $aiJob?->id,
                    'ai_reconciliation_submission_id' => $submission?->id,
                    'evidence_signature' => $signature,
                    'evidence_summary' => $submission?->summary,
                    'evidence_synced_at' => now(),
                ];

                if ($protected) {
                    $row->update([
                        ...$provenance,
                        'has_evidence_changes' => $changed || $row->has_evidence_changes,
                    ]);
                    $result['protected']++;
                    if ($changed) $result['changed']++;
                    continue;
                }

                $allocation = $journals->isEmpty() ? [] : $this->allocator->allocate($journals);
                $row->update([
                    ...$allocation,
                    ...$provenance,
                    'ocr_check_in_raw' => $this->firstTime($daily),
                    'ocr_check_out_raw' => $this->lastTime($daily),
                    'work_location' => $journals->pluck('work_location')->filter()->unique()->implode(', ')
                        ?: $daily->pluck('work_location')->filter()->unique()->implode(', ') ?: null,
                    'work_content' => $journals->pluck('work_content')->filter()->unique()->implode("\n") ?: null,
                    'explanation' => $this->explanation($journals, $submission),
                    'has_evidence_changes' => false,
                    'status' => 'DRAFT',
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ]);
                $result['updated']++;
            }
        });

        return $result;
    }

    private function forSegment(Collection $items, ReconciliationRow $row): Collection
    {
        return $items->filter(function ($item) use ($row): bool {
            $time = $item instanceof JournalRow ? $item->start_time : $item->extracted_time;
            return !$time || ($time >= $row->segment_start && $time <= $row->segment_end);
        })->values();
    }

    private function evidenceStatus(Collection $daily, Collection $journals, ?AiReconciliationJob $job, $submission): string
    {
        if ($daily->isEmpty() && $journals->isEmpty()) return 'NO_EVIDENCE';
        if ($daily->isEmpty()) return 'JOURNAL_ONLY';
        if ($journals->isEmpty()) return 'DAILY_ONLY';
        if (!$submission) return $job?->status === 'WAITING_EVIDENCE' ? 'WAITING_EVIDENCE' : 'PENDING_ANALYSIS';
        return match ($submission->outcome) {
            'MATCHED' => 'MATCHED',
            'WARNING' => 'WARNING',
            'EXCEPTION' => 'EXCEPTION',
            default => 'PENDING_ANALYSIS',
        };
    }

    private function signature(Collection $daily, Collection $journals, $job, $submission): string
    {
        return hash('sha256', json_encode([
            'daily' => $daily->map(fn ($item) => [$item->id, $item->updated_at?->format('Y-m-d H:i:s.u')])->all(),
            'journal' => $journals->map(fn ($item) => [$item->id, $item->updated_at?->format('Y-m-d H:i:s.u')])->all(),
            'job' => $job ? [$job->id, $job->source_signature, $job->status] : null,
            'submission' => $submission ? [$submission->id, $submission->outcome, $submission->updated_at?->format('Y-m-d H:i:s.u')] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function firstTime(Collection $daily): ?string
    {
        return $daily->pluck('extracted_time')->filter()->sort()->first();
    }

    private function lastTime(Collection $daily): ?string
    {
        return $daily->pluck('extracted_time')->filter()->sort()->last();
    }

    private function explanation(Collection $journals, $submission): ?string
    {
        return collect([
            $journals->pluck('error_explanation')->filter()->unique()->implode("\n") ?: null,
            $submission && in_array($submission->outcome, ['WARNING', 'EXCEPTION'], true) ? $submission->summary : null,
        ])->filter()->implode("\n") ?: null;
    }
}
