<?php

namespace App\Console\Commands;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationResidualAuditService;
use Illuminate\Console\Command;

class ReconciliationResidualAudit extends Command
{
    protected $signature = 'reconciliation:residual-audit {period : ID kỳ cần kiểm tra} {--row=* : Row IDs; mặc định năm case Phase 17.3}';

    protected $description = 'Read-only JSON audit timeline, canonical evidence và Không BCH; không chạy Repair';

    public function handle(ReconciliationResidualAuditService $service): int
    {
        $periodId = filter_var($this->argument('period'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ids = $this->option('row') ?: [85904, 85905, 86442, 87577, 89197];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $this->error('Row ID phải là số nguyên dương.');

                return self::FAILURE;
            }
        }
        $period = $periodId === false ? null : ReconciliationPeriod::find($periodId);
        if (! $period) {
            $this->error('Không tìm thấy kỳ đối chiếu; không thu thập hoặc thay đổi dữ liệu.');

            return self::FAILURE;
        }
        $this->line(json_encode($service->audit($period, array_map('intval', $ids)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
