<?php

namespace App\Console\Commands;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use Illuminate\Console\Command;

class ReconciliationRepairPreview extends Command
{
    protected $signature = 'reconciliation:repair-preview {period}';

    protected $description = 'Period-limited Repair dry run; rolls back every write; use only on an isolated restored database';

    public function handle(ReconciliationLinkRepairService $service): int
    {
        if (app()->environment('production')) {
            $this->error('Preview executes rolled-back writes. Use a restored isolated local database, not production.');

            return self::FAILURE;
        }
        $id = filter_var($this->argument('period'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $period = $id === false ? null : ReconciliationPeriod::find($id);
        if (! $period) {
            $this->error('Không tìm thấy kỳ.');

            return self::FAILURE;
        }
        $report = $service->preview($period);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
