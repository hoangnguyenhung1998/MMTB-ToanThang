<?php

namespace App\Services\Reconciliation;

use App\Models\OcrJob;
use App\Models\ActivityLog;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyPhotoSyncService
{
    private ?Collection $cachedSources = null;
    public function __construct(private readonly DailyTimeAllocator $allocator) {}

    public function sources(ReconciliationRow $row, bool $includeNextDay = false): Collection
    {
        $jobs = $this->cachedSources !== null && !$includeNextDay
            ? collect($this->cachedSources->get($row->machine_id.'|'.$row->work_date->toDateString(), []))
            : OcrJob::query()->with('attachment.message')
            ->where('document_type', 'DAILY_TIMEMARK')->where('machine_id', $row->machine_id)
            ->whereBetween('extracted_date', [$row->work_date->toDateString(), $row->work_date->copy()->addDays($includeNextDay ? 1 : 0)->toDateString()])
            ->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])
            ->whereNotNull('extracted_time')->orderBy('extracted_date')->orderBy('extracted_time')->orderBy('id')->get();
        return $jobs->filter(function (OcrJob $job) use ($row) {
                if ($job->extracted_date->isSameDay($row->work_date)) {
                        return $this->allocator->minute($job->extracted_time) >= $this->allocator->minute($row->segment_start)
                            && $this->allocator->minute($job->extracted_time) <= $this->allocator->minute($row->segment_end);
                }
                // Next-day images are offered only for explicit overnight selection.
                $assignment = $row->assignment;
                $at = $job->extracted_date->copy()->setTimeFromTimeString($job->extracted_time);
                return $assignment && (!$assignment->time_out || $at->lte($assignment->time_out));
            })->values();
    }

    public function preview(ReconciliationRow $row): array
    {
        $sources = $this->sources($row);
        $points = $sources->unique(fn ($job) => substr($job->extracted_time, 0, 5))->values();
        $intervals = [];
        // Only the conventional four-photo, two-daytime-shift case is automatic.
        // Missing endpoints, extra shifts and overnight work require explicit pairing.
        if ($points->count() === 4 && $points[0]->shift === 'MORNING' && $points[2]->shift === 'AFTERNOON'
            && in_array($points[1]->shift, ['MORNING', 'MIDDAY'], true)
            && !$sources->contains(fn ($job) => data_get($job->daily_metadata, 'near_duplicate_ids', []) !== [])) {
            foreach ([0 => 'regular_morning', 2 => 'regular_afternoon'] as $index => $kind) {
                $intervals[] = ['kind' => $kind, 'start' => substr($points[$index]->extracted_time, 0, 5),
                    'end' => substr($points[$index + 1]->extracted_time, 0, 5),
                    'start_job_id' => $points[$index]->id, 'end_job_id' => $points[$index + 1]->id];
            }
        }
        $allocation = null;
        $message = $sources->isEmpty() ? 'Chưa có ảnh ngày đủ mã máy, ngày và giờ.' : 'Cần kiểm tra ghép ca hoặc bổ sung ảnh. Ca 4 giờ chỉ là gợi ý, chưa tính công.';
        if ($intervals) {
            try {
                $allocation = $this->allocator->allocate($intervals, true, $this->allocator->remainingRegularMinutes($row));
                $this->allocator->assertWithinAssignment($allocation, $row);
                $message = 'Đã phân bổ từ bốn mốc ảnh ngày. Có thể sửa ca và giờ trực tiếp.';
            } catch (ValidationException $exception) {
                $allocation = null;
                $message = $exception->getMessage();
            }
        }
        return compact('sources', 'intervals', 'allocation', 'message');
    }

    public function sync(ReconciliationPeriod $period, ?int $machineId = null, ?string $workDate = null): array
    {
        try {
        return DB::transaction(function () use ($period, $machineId, $workDate) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            abort_unless(in_array($period->status, ['GENERATED', 'REVIEWING'], true), 409, 'Kỳ không cho phép đồng bộ.');
            $result = ['updated' => 0, 'protected' => 0, 'changed' => 0];
            $rows = $period->rows()->with('assignment')->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->when($workDate, fn ($q) => $q->whereBetween('work_date', [\Carbon\Carbon::parse($workDate)->subDay()->toDateString(), $workDate]))->lockForUpdate()->get();
            $this->cachedSources = OcrJob::query()->where('document_type', 'DAILY_TIMEMARK')
                ->whereIn('machine_id', $rows->pluck('machine_id')->unique())
                ->whereBetween('extracted_date', [$period->date_from, $period->date_to])
                ->when($workDate, fn ($q) => $q->whereBetween('extracted_date', [\Carbon\Carbon::parse($workDate)->subDay()->toDateString(), $workDate]))
                ->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])->whereNotNull('extracted_time')
                ->orderBy('extracted_date')->orderBy('extracted_time')->orderBy('id')->get()
                ->groupBy(fn ($job) => $job->machine_id.'|'.$job->extracted_date->toDateString());
            foreach ($rows as $row) {
                $preview = $this->preview($row);
                $sources = $preview['sources'];
                $signature = $this->signatureForRow($row, $sources);
                if ($signature === $row->evidence_signature) continue;
                if ($row->manually_edited_at || in_array($row->status, ['REVIEWED', 'CONFIRMED', 'REJECTED'], true)) {
                    $row->update(['has_evidence_changes' => true]);
                    $result['protected']++;
                    $result['changed']++;
                    continue;
                }
                $allocation = $preview['allocation'];
                // Clear obsolete automatic times if evidence is withdrawn; retain manual values above.
                $empty = $this->allocator->allocate([]);
                foreach (['regular_minutes', 'lunch_minutes', 'ot_afternoon_minutes', 'ot_evening_minutes'] as $key) $empty[$key] = null;
                $before = $row->toArray();
                $row->update([
                    ...($allocation ?? $empty),
                    'rounded_check_in' => $allocation['confirmed_check_in'] ?? null,
                    'rounded_check_out' => $allocation['confirmed_check_out'] ?? null,
                    'ocr_check_in_raw' => $sources->first()?->extracted_time,
                    'ocr_check_out_raw' => $sources->last()?->extracted_time,
                    'daily_intervals' => $preview['intervals'],
                    'daily_ocr_job_ids' => $sources->pluck('id')->all(),
                    'evidence_status' => $allocation ? 'DAILY_READY' : ($sources->isEmpty() ? 'NO_EVIDENCE' : 'DAILY_REVIEW'),
                    'evidence_summary' => $preview['message'],
                    'evidence_signature' => $signature, 'evidence_synced_at' => now(), 'has_evidence_changes' => false,
                ]);
                if (collect(['regular_minutes', 'lunch_minutes', 'ot_afternoon_minutes', 'ot_evening_minutes'])->contains(fn ($key) => !empty($before[$key]))) {
                    ActivityLog::create(['machine_id' => $row->machine_id, 'event' => 'daily_photo.synced',
                        'description' => 'Đồng bộ giờ ảnh ngày; lưu kết quả trước khi thay đổi.',
                        'subject_type' => ReconciliationRow::class, 'subject_id' => $row->id,
                        'properties' => ['before' => $before, 'after' => $row->fresh()->toArray()], 'occurred_at' => now()]);
                }
                $result['updated']++;
            }
            return $result;
        });
        } finally {
            $this->cachedSources = null;
        }
    }

    public function signature(Collection $sources): string
    {
        return hash('sha256', json_encode(['daily-v1', $sources->map(fn ($job) => [$job->id, $job->extracted_date?->toDateString(), $job->extracted_time, $job->machine_id, $job->daily_metadata])->values()->all()]));
    }

    public function signatureForRow(ReconciliationRow $row, Collection $sources, ?array $usedIds = null): string
    {
        // Include selected overnight evidence even if it has been withdrawn or reclassified.
        $usedIds ??= collect($row->daily_intervals ?? [])->flatMap(fn ($part) => [$part['start_job_id'] ?? null, $part['end_job_id'] ?? null])->filter()->all();
        $extraIds = array_diff($usedIds, $sources->pluck('id')->all());
        if ($extraIds) {
            $sources = $sources->concat(OcrJob::query()->whereIn('id', $extraIds)->get());
        }
        return hash('sha256', $this->signature($sources->sortBy('id')).json_encode($sources->sortBy('id')->map(fn ($job) => [$job->id, $job->review_status, $job->document_type, $job->status])->values()->all()));
    }
}
