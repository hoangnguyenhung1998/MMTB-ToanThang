<?php

namespace App\Console\Commands;

use App\Models\ReconciliationPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDraftReconciliationPeriods extends Command
{
    protected $signature = 'reconciliation:cleanup-drafts
        {--before= : Chỉ xét kỳ kết thúc trước ngày YYYY-MM-DD}
        {--execute : Thực sự xóa; mặc định chỉ xem trước}';

    protected $description = 'Xem trước hoặc xóa an toàn các kỳ nháp cũ chưa có dòng được duyệt/xác nhận';

    public function handle(): int
    {
        $before = $this->option('before') ?: now()->startOfMonth()->toDateString();
        $periods = ReconciliationPeriod::query()
            ->whereIn('status', ['DRAFT', 'GENERATED'])
            ->whereDate('date_to', '<', $before)
            ->whereDoesntHave('rows', fn ($query) => $query->where('status', '!=', 'DRAFT'))
            ->withCount('rows')
            ->orderBy('date_from')
            ->get();

        if ($periods->isEmpty()) {
            $this->info('Không có kỳ nháp cũ đủ điều kiện xóa.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tên kỳ', 'Từ ngày', 'Đến ngày', 'Trạng thái', 'Số dòng'],
            $periods->map(fn ($period) => [
                $period->id,
                $period->name,
                $period->date_from->format('d/m/Y'),
                $period->date_to->format('d/m/Y'),
                $period->status,
                $period->rows_count,
            ])->all()
        );

        if (!$this->option('execute')) {
            $this->warn('Đây là chế độ xem trước. Thêm --execute để xóa các kỳ trên.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => ReconciliationPeriod::query()
            ->whereIn('id', $periods->pluck('id')->all())
            ->delete());

        $this->info('Đã xóa '.$periods->count().' kỳ nháp cũ; dữ liệu đã duyệt/xác nhận không bị tác động.');

        return self::SUCCESS;
    }
}
