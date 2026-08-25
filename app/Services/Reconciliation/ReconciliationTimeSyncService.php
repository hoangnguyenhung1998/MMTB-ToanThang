<?php

namespace App\Services\Reconciliation;

use App\Models\JournalRow;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationTimeSyncService
{
    private const JOURNAL_REVIEW_STATUSES = ['APPROVED', 'CORRECTED'];

    public function __construct(private readonly ReconciliationTimeAllocator $allocator)
    {
    }

    public function sync(ReconciliationPeriod $period): int
    {
        if (!in_array($period->status, ['GENERATED', 'REVIEWING'], true)) {
            throw new RuntimeException('Chỉ được tự phân bổ giờ cho kỳ đã sinh dữ liệu hoặc đang kiểm tra.');
        }

        $rows = $period->rows()
            ->where('status', '!=', 'CONFIRMED')
            ->get(['id', 'reconciliation_period_id', 'machine_id', 'work_date', 'segment_start', 'segment_end']);
        $machineIds = $rows->pluck('machine_id')->unique();

        $journalRows = JournalRow::query()
            ->whereBetween('work_date', [$period->date_from, $period->date_to])
            ->whereHas('document', fn ($query) => $query->whereIn('machine_id', $machineIds))
            ->whereHas('document.ocrJob', fn ($query) => $query
                ->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
            ->with('document:id,machine_id,ocr_job_id')
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (JournalRow $row) => $row->document->machine_id.'|'.$row->work_date?->format('Y-m-d'));

        $updates = [];
        $now = now();
        foreach ($rows as $row) {
            $sourceRows = collect($journalRows->get($row->machine_id.'|'.$row->work_date->format('Y-m-d'), []))
                ->filter(fn (JournalRow $source) => $source->start_time
                    && $source->start_time >= $row->segment_start
                    && $source->start_time <= $row->segment_end);

            if ($sourceRows->isEmpty()) {
                continue;
            }

            $updates[] = [
                'id' => $row->id,
                'reconciliation_period_id' => $row->reconciliation_period_id,
                'machine_id' => $row->machine_id,
                'work_date' => $row->work_date->format('Y-m-d'),
                ...$this->allocator->allocate($sourceRows),
                'work_location' => $sourceRows->pluck('work_location')->filter()->unique()->implode(', ') ?: null,
                'work_content' => $sourceRows->pluck('work_content')->filter()->unique()->implode("\n") ?: null,
                'explanation' => $sourceRows->pluck('error_explanation')->filter()->unique()->implode("\n") ?: null,
                'status' => 'DRAFT',
                'reviewed_at' => null,
                'reviewed_by' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'updated_at' => $now,
            ];
        }

        $columns = array_values(array_diff(array_keys($updates[0] ?? []), ['id']));
        DB::transaction(function () use ($updates, $columns): void {
            foreach (array_chunk($updates, 500) as $chunk) {
                DB::table('reconciliation_rows')->upsert($chunk, ['id'], $columns);
            }
        });

        return count($updates);
    }
}
