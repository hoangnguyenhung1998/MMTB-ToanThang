<?php

namespace App\Console\Commands;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationEvidenceSyncService;
use Illuminate\Console\Command;

class SyncReconciliationEvidence extends Command
{
    protected $signature = 'reconciliation:sync-evidence {month? : Tháng YYYY-MM; mặc định là tháng hiện tại}';

    protected $description = 'Đồng bộ OCR ảnh hằng ngày, nhật trình và kết quả AI vào kỳ đối chiếu đang mở';

    public function handle(ReconciliationEvidenceSyncService $service): int
    {
        $month = $this->argument('month') ?: now('Asia/Ho_Chi_Minh')->format('Y-m');
        $periods = ReconciliationPeriod::query()
            ->whereIn('status', ['GENERATED', 'REVIEWING'])
            ->whereDate('date_from', $month.'-01')
            ->get();

        if ($periods->isEmpty()) {
            $this->warn('Không có kỳ đối chiếu đang mở cho tháng '.$month.'.');
            return self::SUCCESS;
        }

        foreach ($periods as $period) {
            $result = $service->sync($period);
            $this->info(sprintf(
                'Kỳ #%d: cập nhật %d, bảo vệ %d, có nguồn mới %d.',
                $period->id, $result['updated'], $result['protected'], $result['changed']
            ));
        }

        return self::SUCCESS;
    }
}
