<?php

namespace App\Services\Reconciliation;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoInterval;
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

    private ?Collection $cachedCases = null;

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
        $case = $this->caseForRow($row);
        $sources = $case ? $this->caseSources($case) : $this->sources($row);
        $intervals = [];
        $allocation = null;
        $message = $sources->isEmpty()
            ? 'Chưa có ảnh ngày đủ mã máy, ngày và giờ.'
            : 'Ảnh chưa có hồ sơ ghép canonical; cần kiểm tra trước khi tính công.';

        if ($case?->status === DailyPhotoCase::STATUS_COLLECTING) {
            $message = 'Đang chờ thêm ảnh để hoàn tất cặp giờ; chưa tự tính công.';
        } elseif ($case?->status === DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS) {
            $codes = collect($case->pairing_diagnostics['codes'] ?? [])->implode(', ');
            $message = 'Không thể ghép ảnh an toàn'.($codes ? ": {$codes}" : '').'; cần xử lý ngoại lệ.';
        } elseif ($case?->status === DailyPhotoCase::STATUS_READY) {
            try {
                $intervals = $case->intervals
                    ->map(fn (DailyPhotoInterval $interval) => $this->canonicalInterval($interval))
                    ->all();
                $allocation = $this->allocator->allocate($intervals, true, $this->allocator->remainingRegularMinutes($row));
                $this->allocator->assertWithinAssignment($allocation, $row);
                $message = 'Đã phân bổ từ các cặp ảnh canonical theo giờ chụp. Có thể sửa ca và giờ trực tiếp.';
            } catch (ValidationException $exception) {
                $allocation = null;
                $message = $exception->getMessage();
            }
        }

        return compact('case', 'sources', 'intervals', 'allocation', 'message');
    }

    public function sync(ReconciliationPeriod $period, ?int $machineId = null, ?string $workDate = null): array
    {
        try {
        return DB::transaction(function () use ($period, $machineId, $workDate) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            abort_unless(in_array($period->status, ['GENERATED', 'REVIEWING'], true), 409, 'Kỳ không cho phép đồng bộ.');
            $result = ['updated' => 0, 'protected' => 0, 'changed' => 0];
            $rows = $period->rows()->with('assignment')->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->when($workDate, fn ($q) => $q
                    ->whereDate('work_date', '>=', \Carbon\Carbon::parse($workDate)->subDay()->toDateString())
                    ->whereDate('work_date', '<=', $workDate))
                ->lockForUpdate()->get();
            $this->cachedSources = OcrJob::query()->where('document_type', 'DAILY_TIMEMARK')
                ->whereIn('machine_id', $rows->pluck('machine_id')->unique())
                ->whereBetween('extracted_date', [$period->date_from, $period->date_to])
                ->when($workDate, fn ($q) => $q
                    ->whereDate('extracted_date', '>=', \Carbon\Carbon::parse($workDate)->subDay()->toDateString())
                    ->whereDate('extracted_date', '<=', $workDate))
                ->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])->whereNotNull('extracted_time')
                ->orderBy('extracted_date')->orderBy('extracted_time')->orderBy('id')->get()
                ->groupBy(fn ($job) => $job->machine_id.'|'.$job->extracted_date->toDateString());
            $this->cachedCases = DailyPhotoCase::query()
                ->with($this->caseRelations())
                ->whereIn('machine_id', $rows->pluck('machine_id')->unique())
                ->whereBetween('work_date', [$period->date_from, $period->date_to])
                ->get()
                ->keyBy(fn (DailyPhotoCase $case) => $this->caseKey($case->machine_assignment_id, $case->work_date->format('Y-m-d')));
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
            $this->cachedCases = null;
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
        $case = $this->caseForRow($row);
        $canonical = $case ? [
            'id' => $case->id,
            'status' => $case->status,
            'policy' => $case->pairing_policy_version,
            'diagnostics' => $case->pairing_diagnostics,
            'intervals' => $case->intervals->map(fn (DailyPhotoInterval $interval) => [
                $interval->id,
                $interval->sequence,
                $interval->start_evidence_id,
                $interval->end_evidence_id,
                $interval->raw_start_at->format('Y-m-d H:i:s'),
                $interval->raw_end_at->format('Y-m-d H:i:s'),
                $interval->status,
                $interval->pairing_policy_version,
            ])->values()->all(),
        ] : null;

        return hash('sha256', $this->signature($sources->sortBy('id')).json_encode([
            'canonical-v1',
            $canonical,
            $sources->sortBy('id')->map(fn ($job) => [$job->id, $job->review_status, $job->document_type, $job->status])->values()->all(),
        ]));
    }

    private function caseForRow(ReconciliationRow $row): ?DailyPhotoCase
    {
        $key = $this->caseKey($row->machine_assignment_id, $row->work_date->format('Y-m-d'));
        if ($this->cachedCases !== null) {
            return $this->cachedCases->get($key);
        }

        return DailyPhotoCase::query()
            ->with($this->caseRelations())
            ->where('machine_id', $row->machine_id)
            ->where('machine_assignment_id', $row->machine_assignment_id)
            ->whereDate('work_date', $row->work_date)
            ->first();
    }

    private function caseSources(DailyPhotoCase $case): Collection
    {
        return $case->evidenceMemberships
            ->sortBy('capture_datetime')
            ->pluck('ocrJob')
            ->filter()
            ->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])
            ->values();
    }

    private function canonicalInterval(DailyPhotoInterval $interval): array
    {
        $startJob = $interval->startEvidence?->ocrJob;
        $endJob = $interval->endEvidence?->ocrJob;
        $start = $interval->raw_start_at->format('H:i');
        $end = $interval->raw_end_at->format('H:i');
        $kind = $this->kindForStart($start);

        if (! $startJob || ! $endJob || ! $kind) {
            throw ValidationException::withMessages([
                'intervals' => 'Cặp ảnh canonical thiếu nguồn hoặc không xác định được loại ca.',
            ]);
        }

        return [
            'kind' => $kind,
            'start' => $start,
            'end' => $end,
            'start_date' => $interval->raw_start_at->format('Y-m-d'),
            'end_date' => $interval->raw_end_at->format('Y-m-d'),
            'start_job_id' => $startJob->id,
            'end_job_id' => $endJob->id,
            'canonical_interval_id' => $interval->id,
        ];
    }

    private function kindForStart(string $time): ?string
    {
        $minutes = $this->allocator->minute($time);

        return match (true) {
            $minutes < 660 => 'regular_morning',
            $minutes < 810 => 'overtime_lunch',
            $minutes < 990 => 'regular_afternoon',
            $minutes <= 1050 => 'overtime_afternoon',
            $minutes > 1050 => 'overtime_evening',
        };
    }

    private function caseKey(?int $assignmentId, string $workDate): string
    {
        return ($assignmentId ?: 'unresolved').'|'.$workDate;
    }

    private function caseRelations(): array
    {
        return [
            'evidenceMemberships.ocrJob.attachment.message',
            'intervals.startEvidence.ocrJob',
            'intervals.endEvidence.ocrJob',
        ];
    }
}
