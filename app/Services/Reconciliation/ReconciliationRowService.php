<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationRowService
{
    public function update(ReconciliationRow $row, array $data): ReconciliationRow
    {
        if ($row->status === 'CONFIRMED') {
            throw new RuntimeException('Không thể chỉnh sửa dòng đã xác nhận.');
        }

        return DB::transaction(function () use ($row, $data) {
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
}
