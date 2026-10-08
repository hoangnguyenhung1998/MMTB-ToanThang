<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\GlobalReconciliationRepairService;
use Illuminate\Console\Command;

class ProcessReconciliationRepairRuns extends Command
{
    protected $signature = 'reconciliation:process-repair-runs {--limit=1}';

    protected $description = 'Process bounded durable global repair periods with shared locked proof';

    public function handle(GlobalReconciliationRepairService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($limit === false) {
            return self::FAILURE;
        }
        for ($i = 0; $i < $limit && $service->processNext(); $i++) {
            $this->line('Processed one period; inspect the durable run report for its disposition.');
        }

        return self::SUCCESS;
    }
}
