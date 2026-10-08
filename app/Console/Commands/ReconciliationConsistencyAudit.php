<?php

namespace App\Console\Commands;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationConsistencyAuditService;
use Illuminate\Console\Command;

class ReconciliationConsistencyAudit extends Command
{
    protected $signature = 'reconciliation:consistency-audit {period} {--machine=} {--from=} {--to=}';

    protected $description = 'SELECT-only scoped machine/day duplicate and canonical proof; no sensitive payload values';

    public function handle(ReconciliationConsistencyAuditService $service): int
    {
        $periodId = filter_var($this->argument('period'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $machine = $this->option('machine');
        if ($periodId === false || ($machine !== null && filter_var($machine, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Period/machine phải là số nguyên dương.');

            return self::FAILURE;
        }
        $period = ReconciliationPeriod::find($periodId);
        if (! $period) {
            $this->error('Không tìm thấy kỳ.');

            return self::FAILURE;
        }
        $from = $this->option('from') ?? $period->date_from->toDateString();
        $to = $this->option('to') ?? $period->date_to->toDateString();
        foreach ([$from, $to] as $date) {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $this->error('Ngày phải hợp lệ, YYYY-MM-DD.');

                return self::FAILURE;
            }
        }
        if ($from > $to || $from < $period->date_from->toDateString() || $to > $period->date_to->toDateString()) {
            $this->error('Scope phải nằm trong kỳ, from <= to.');

            return self::FAILURE;
        }
        $this->line(json_encode($service->audit($period, $machine === null ? null : (int) $machine, $from, $to), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
