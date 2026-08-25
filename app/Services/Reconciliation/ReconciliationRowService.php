<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationRowService
{
    public function __construct(private readonly ReconciliationTimeAllocator $timeAllocator)
    {
    }

    public function update(ReconciliationRow $row, array $data): ReconciliationRow
    {
        if ($row->status === 'CONFIRMED') {
            throw new RuntimeException('Không thể chỉnh sửa dòng đã xác nhận.');
        }

        return DB::transaction(function () use ($row, $data) {
            $timeFields = [
                'regular_morning_start', 'regular_morning_end',
                'regular_afternoon_start', 'regular_afternoon_end',
                'overtime_lunch_start', 'overtime_lunch_end',
                'overtime_afternoon_start', 'overtime_afternoon_end',
                'overtime_evening_start', 'overtime_evening_end',
            ];
            $timeData = [
                ...$row->only($timeFields),
                ...$data,
            ];
            $sourceIntervals = collect([
                ['start_time' => $timeData['regular_morning_start'] ?? null, 'end_time' => $timeData['regular_morning_end'] ?? null],
                ['start_time' => $timeData['regular_afternoon_start'] ?? null, 'end_time' => $timeData['regular_afternoon_end'] ?? null],
                ['start_time' => $timeData['overtime_lunch_start'] ?? null, 'end_time' => $timeData['overtime_lunch_end'] ?? null],
                ['start_time' => $timeData['overtime_afternoon_start'] ?? null, 'end_time' => $timeData['overtime_afternoon_end'] ?? null],
                ['start_time' => $timeData['overtime_evening_start'] ?? null, 'end_time' => $timeData['overtime_evening_end'] ?? null],
            ]);
            $recalculated = $this->timeAllocator->allocate($sourceIntervals);
            $data = [
                ...$data,
                ...collect($recalculated)->only([
                    ...$timeFields,
                    'confirmed_check_in', 'confirmed_check_out',
                    'regular_minutes', 'lunch_minutes',
                    'ot_afternoon_minutes', 'ot_evening_minutes',
                ])->all(),
            ];
            $row->update([
                ...$data,
                'status' => 'DRAFT',
                'reviewed_at' => null,
                'reviewed_by' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);

            return $row->refresh();
        });
    }

    public function review(ReconciliationRow $row, int $userId, string $decision, ?string $comment): ReconciliationRow
    {
        if ($row->status === 'CONFIRMED') {
            throw new RuntimeException('Không thể kiểm tra lại dòng đã xác nhận.');
        }

        if ($decision === 'reject' && trim((string) $comment) === '') {
            throw new RuntimeException('Dòng bị từ chối phải có ghi chú.');
        }

        return DB::transaction(function () use ($row, $userId, $decision, $comment) {
            $row->update([
                'status' => $decision === 'reject' ? 'REJECTED' : 'REVIEWED',
                'reviewed_at' => now(),
                'reviewed_by' => $userId,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'notes' => $comment ?: $row->notes,
            ]);

            return $row->refresh();
        });
    }

    public function confirm(ReconciliationRow $row, int $userId): ReconciliationRow
    {
        if ($row->status !== 'REVIEWED') {
            throw new RuntimeException('Chỉ dòng đã kiểm tra mới được xác nhận.');
        }

        return DB::transaction(function () use ($row, $userId) {
            $row->update([
                'status' => 'CONFIRMED',
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            return $row->refresh();
        });
    }

    public function quickConfirm(ReconciliationRow $row, int $userId): ReconciliationRow
    {
        if ($row->period()->value('status') !== 'REVIEWING') {
            throw new RuntimeException('Chỉ xác nhận nhanh khi kỳ đang ở trạng thái kiểm tra.');
        }

        if (!in_array($row->status, ['DRAFT', 'REJECTED', 'REVIEWED'], true)) {
            throw new RuntimeException('Dòng này không còn ở trạng thái cho phép xác nhận nhanh.');
        }

        foreach (['regular_morning', 'regular_afternoon', 'overtime_lunch', 'overtime_afternoon', 'overtime_evening'] as $prefix) {
            $start = $row->{$prefix.'_start'};
            $end = $row->{$prefix.'_end'};

            if (($start && !$end) || (!$start && $end)) {
                throw new RuntimeException('Mỗi khoảng giờ phải có đủ bắt đầu và kết thúc; có thể để trống cả hai nếu nghỉ.');
            }
        }

        return DB::transaction(function () use ($row, $userId) {
            $now = now();
            $row->update([
                'status' => 'CONFIRMED',
                'reviewed_at' => $now,
                'reviewed_by' => $userId,
                'confirmed_at' => $now,
                'confirmed_by' => $userId,
            ]);

            return $row->refresh();
        });
    }
}
