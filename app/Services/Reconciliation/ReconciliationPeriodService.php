<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationPeriodService
{
    public function __construct(private readonly ReconciliationGenerator $generator)
    {
    }

    public function create(array $data, ?int $userId): ReconciliationPeriod
    {
        if (($data['type'] ?? null) === 'MONTHLY') {
            $from = Carbon::parse($data['date_from'])->startOfMonth();
            $to = Carbon::parse($data['date_to'])->endOfMonth();

            if (ReconciliationPeriod::query()
                ->where('type', 'MONTHLY')
                ->whereDate('date_from', $from)
                ->whereDate('date_to', $to)
                ->exists()) {
                throw new RuntimeException('Tháng này đã có kỳ đối chiếu. Mỗi tháng chỉ được có một kỳ gốc.');
            }

            $data['date_from'] = $from->toDateString();
            $data['date_to'] = $to->toDateString();
        }

        return DB::transaction(function () use ($data, $userId) {
            return ReconciliationPeriod::query()->create([
                ...$data,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]);
        });
    }

    public function ensureMonthly(string|Carbon|null $month = null, ?int $userId = null): ReconciliationPeriod
    {
        $date = match (true) {
            $month instanceof Carbon => $month->copy(),
            is_string($month) && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month) === 1
                => Carbon::createFromFormat('!Y-m', $month),
            default => Carbon::parse($month ?: 'today'),
        };
        $from = $date->copy()->startOfMonth();
        $to = $date->copy()->endOfMonth();

        return DB::transaction(function () use ($from, $to, $userId) {
            $existing = ReconciliationPeriod::query()
                ->where('type', 'MONTHLY')
                ->whereDate('date_from', $from->toDateString())
                ->whereDate('date_to', $to->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return ReconciliationPeriod::query()->create([
                'name' => 'Đối chiếu tháng '.$from->format('m/Y'),
                'type' => 'MONTHLY',
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'status' => 'DRAFT',
                'created_by' => $userId,
                'notes' => 'Kỳ tháng được hệ thống tạo tự động.',
            ]);
        });
    }

    public function syncMonthly(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->type !== 'MONTHLY') {
            throw new RuntimeException('Chỉ đồng bộ tự động đối với kỳ tháng.');
        }

        if (!in_array($period->status, ['DRAFT', 'GENERATED'], true)) {
            return $period->refresh();
        }

        if ($period->rows()->whereIn('status', ['REVIEWED', 'CONFIRMED'])->exists()) {
            return $period->refresh();
        }

        if ($period->rows()->whereNotNull('manually_edited_at')->exists()) {
            return $period->refresh();
        }

        return $this->generator->generate($period);
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

        if ($period->type === 'MONTHLY') {
            $period = $this->syncMonthly($period);
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
