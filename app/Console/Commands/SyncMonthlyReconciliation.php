<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Console\Command;
use Throwable;

class SyncMonthlyReconciliation extends Command
{
    protected $signature = 'reconciliation:sync-monthly
        {month? : Tháng cần đồng bộ theo YYYY-MM}
        {--create-only : Chỉ bảo đảm kỳ tháng tồn tại, chưa sinh lại dữ liệu}';

    protected $description = 'Tạo kỳ đối chiếu tháng duy nhất và đồng bộ dữ liệu phân công khi kỳ còn là bản nháp sống';

    public function handle(ReconciliationPeriodService $periodService): int
    {
        try {
            $period = $periodService->ensureMonthly($this->argument('month'));

            if (!$this->option('create-only')) {
                $period = $periodService->syncMonthly($period);
            }

            $this->info(sprintf(
                'Kỳ #%d %s: %s, %d dòng.',
                $period->id,
                $period->name,
                $period->status,
                $period->rows()->count()
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
