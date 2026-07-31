<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationPeriodService
{
    public function __construct(private readonly ReconciliationGenerator $generator)
    {
    }

    public function create(array $data, ?int $userId): ReconciliationPeriod
    {
        return DB::transaction(function () use ($data, $userId) {
            return ReconciliationPeriod::query()->create([
                ...$data,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]);
        });
    }

    public function generate(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if (!in_array($period->status, ['DRAFT', 'GENERATED'], true)) {
            throw new RuntimeException('Chỉ được sinh dữ liệu cho kỳ nháp hoặc kỳ đã sinh dữ liệu.');
        }

        return $this->generator->generate($period);
    }

    public function startReview(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->status !== 'GENERATED') {
            throw new RuntimeException('Chỉ được chuyển sang kiểm tra sau khi kỳ đã sinh dữ liệu.');
        }

        if (!$period->rows()->exists()) {
            throw new RuntimeException('Không thể bắt đầu kiểm tra khi kỳ chưa có dòng đối chiếu.');
        }

        return DB::transaction(function () use ($period) {
            $period->update(['status' => 'REVIEWING']);

            return $period->refresh();
        });
    }

    public function confirm(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->status !== 'REVIEWING') {
            throw new RuntimeException('Chỉ được xác nhận kỳ đang kiểm tra.');
        }

        if (!$period->rows()->exists()) {
            throw new RuntimeException('Không thể xác nhận kỳ chưa có dòng đối chiếu.');
        }

        if ($period->rows()->where('status', 'REJECTED')->exists()) {
            throw new RuntimeException('Không thể xác nhận kỳ còn dòng bị từ chối.');
        }

        if ($period->rows()->where('status', '!=', 'CONFIRMED')->exists()) {
            throw new RuntimeException('Chỉ được xác nhận kỳ khi tất cả dòng đã được xác nhận.');
        }

        return DB::transaction(function () use ($period) {
            $period->update([
                'status' => 'CONFIRMED',
                'confirmed_at' => now(),
            ]);

            return $period->refresh();
        });
    }

    public function lock(ReconciliationPeriod $period): ReconciliationPeriod
    {
        throw new RuntimeException('Chưa thể khóa kỳ vì schema hiện tại chưa có trạng thái LOCKED cho reconciliation_periods và reconciliation_rows.');
    }

    public function deleteDraft(ReconciliationPeriod $period): void
    {
        if ($period->status !== 'DRAFT') {
            throw new RuntimeException('Chỉ được xóa kỳ đối chiếu ở trạng thái nháp.');
        }

        DB::transaction(function () use ($period) {
            $period->delete();
        });
    }
}
