<?php

namespace App\Services;

use App\Models\MachineAssignment;
use App\Models\OcrJob;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DailyImageExceptionService
{
    private const APPROVED = ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'];

    public function paginate(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $groups = $this->groups($filters);
        $page = max(1, (int) ($filters['page'] ?? request()->integer('page', 1)));

        return new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function summary(array $filters): array
    {
        $groups = $this->groups(collect($filters)->except(['exception_status', 'page'])->all());

        return [
            'tracked' => $groups->count(),
            'automatic' => $groups->where('status', 'AUTO_COMPLETE')->count(),
            'exceptions' => $groups->where('is_exception', true)->count(),
            'no_images' => $groups->where('status', 'NO_IMAGES')->count(),
            'pending_review' => $groups->where('status', 'PENDING_REVIEW')->count(),
            'unidentified' => $this->unidentifiedCount($filters),
        ];
    }

    public function groups(array $filters): Collection
    {
        [$from, $to] = $this->range($filters);

        $assignments = MachineAssignment::query()
            ->with(['machine:id,asset_code', 'commandCenter:id,name'])
            ->whereDate('time_in', '<=', $to)
            ->where(fn ($query) => $query->whereNull('time_out')->orWhereDate('time_out', '>=', $from))
            ->when($filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->when($filters['command_center_id'] ?? null, fn ($query, $id) => $query->where('command_center_id', $id))
            ->orderBy('time_in')
            ->get();

        $machineDays = $this->machineDays($assignments, $from, $to);
        $machineIds = $machineDays->pluck('machine_id')->unique()->values();

        $jobs = OcrJob::query()
            ->with('attachment:id,storage_disk,storage_path,original_name,mime_type')
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereIn('machine_id', $machineIds)
            ->whereNotNull('extracted_date')
            ->whereBetween('extracted_date', [$from, $to])
            ->orderBy('extracted_time')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OcrJob $job) => $job->machine_id.'|'.$job->extracted_date->format('Y-m-d'));

        return $machineDays
            ->map(function (array $day) use ($jobs): array {
                $dailyJobs = $jobs->get($day['machine_id'].'|'.$day['date'], collect());
                $approved = $dailyJobs->whereIn('review_status', self::APPROVED)
                    ->sortBy(fn (OcrJob $job) => ($job->extracted_time ?: '99:99:99').'|'.str_pad((string) $job->id, 12, '0', STR_PAD_LEFT))
                    ->values();
                $pending = $dailyJobs->where('review_status', 'PENDING')->count();
                $times = $approved->map(fn (OcrJob $job) => substr((string) $job->extracted_time, 0, 5));
                $duplicate = $times->filter()->duplicates()->isNotEmpty();
                $status = $this->status($approved->count(), $pending, $duplicate);

                return $day + [
                    'approved_count' => $approved->count(),
                    'pending_count' => $pending,
                    'has_duplicate_times' => $duplicate,
                    'status' => $status,
                    'status_label' => $this->statusLabel($status),
                    'is_exception' => $status !== 'AUTO_COMPLETE',
                    'sessions' => $approved->chunk(2)->values()->map(fn (Collection $pair, int $index) => [
                        'number' => $index + 1,
                        'start' => $pair->values()->get(0),
                        'end' => $pair->values()->get(1),
                    ]),
                ];
            })
            ->when($filters['exception_status'] ?? null, fn (Collection $groups, string $status) => match ($status) {
                'EXCEPTIONS' => $groups->where('is_exception', true),
                default => $groups->where('status', $status),
            })
            ->sortBy(fn (array $group) => ($group['is_exception'] ? '0' : '1').'|'.$group['date'].'|'.$group['machine_code'])
            ->values();
    }

    private function machineDays(Collection $assignments, string $from, string $to): Collection
    {
        $rangeStart = CarbonImmutable::parse($from);
        $rangeEnd = CarbonImmutable::parse($to);

        return $assignments->flatMap(function (MachineAssignment $assignment) use ($rangeStart, $rangeEnd): array {
            $start = CarbonImmutable::parse($assignment->time_in)->startOfDay()->max($rangeStart);
            $end = ($assignment->time_out ? CarbonImmutable::parse($assignment->time_out)->startOfDay() : $rangeEnd)->min($rangeEnd);

            return collect(CarbonPeriod::create($start, $end))->map(fn ($date) => [
                'machine_id' => $assignment->machine_id,
                'machine_code' => $assignment->machine?->asset_code ?: 'CHUA-XAC-DINH',
                'date' => $date->format('Y-m-d'),
                'date_label' => $date->format('d/m/Y'),
                'command_center_id' => $assignment->command_center_id,
                'command_center' => $assignment->commandCenter?->name ?: 'Chưa xác định BCH',
            ])->all();
        })
            ->keyBy(fn (array $day) => $day['machine_id'].'|'.$day['date'])
            ->values();
    }

    private function status(int $approvedCount, int $pendingCount, bool $duplicate): string
    {
        if ($pendingCount > 0) return 'PENDING_REVIEW';
        if ($approvedCount === 0) return 'NO_IMAGES';
        if ($duplicate) return 'DUPLICATE_TIME';
        if ($approvedCount % 2 !== 0) return 'MISSING_MARK';
        if ($approvedCount === 2) return 'CTMS_PENDING';
        if (in_array($approvedCount, [4, 6, 8], true)) return 'AUTO_COMPLETE';

        return 'EXCESS_IMAGES';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'AUTO_COMPLETE' => 'Hoàn thành tự động',
            'NO_IMAGES' => 'Chưa có ảnh',
            'PENDING_REVIEW' => 'Chờ hậu kiểm OCR',
            'DUPLICATE_TIME' => 'Trùng giờ',
            'MISSING_MARK' => 'Thiếu một đầu ca',
            'CTMS_PENDING' => 'Chờ CTMS xác nhận số ca',
            default => 'Số lượng ảnh bất thường',
        };
    }

    private function unidentifiedCount(array $filters): int
    {
        [$from, $to] = $this->range($filters);

        return OcrJob::query()
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('review_status', 'PENDING')
            ->where(fn ($query) => $query
                ->whereNull('machine_id')
                ->orWhereNull('extracted_date')
                ->orWhereNull('extracted_time'))
            ->whereHas('attachment.message', fn ($query) => $query->whereBetween('sent_at', ["{$from} 00:00:00", "{$to} 23:59:59"]))
            ->count();
    }

    private function range(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['date_from'] ?? now()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['date_to'] ?? $from)->startOfDay();

        return [$from->toDateString(), $to->toDateString()];
    }
}
