<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconciliationPeriodDashboardService
{
    private array $schema = [];

    public function dashboard(ReconciliationPeriod $period, array $filters = []): array
    {
        $machineCodeColumn = $this->firstExistingColumn('machines', ['asset_code', 'code', 'machine_code']);
        $machineNameColumn = $this->firstExistingColumn('machines', ['name', 'title', 'description']);

        $query = DB::table('reconciliation_rows')
            ->join('machines', 'machines.id', '=', 'reconciliation_rows.machine_id')
            ->where('reconciliation_rows.reconciliation_period_id', $period->id)
            ->select([
                'machines.id as machine_id',
                DB::raw('COALESCE(machines.'.$machineCodeColumn.", '') as asset_code"),
                DB::raw('COALESCE(machines.'.$machineNameColumn.", '') as machine_name"),
                DB::raw('COUNT(reconciliation_rows.id) as total_days'),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status <> 'DRAFT' THEN 1 ELSE 0 END) as days_with_data"),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status = 'WARNING' THEN 1 ELSE 0 END) as warning_days"),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status = 'REJECTED' THEN 1 ELSE 0 END) as error_days"),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status = 'DRAFT' THEN 1 ELSE 0 END) as unreviewed_days"),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status <> 'CONFIRMED' THEN 1 ELSE 0 END) as unconfirmed_days"),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status IN ('WARNING', 'REJECTED', 'DRAFT') THEN 1 ELSE 0 END) as attention_days"),
            ])
            ->groupBy('machines.id', 'machines.'.$machineCodeColumn, 'machines.'.$machineNameColumn);

        if (filled($filters['asset_code'] ?? null)) {
            $query->where('machines.'.$machineCodeColumn, 'like', '%'.$filters['asset_code'].'%');
        }

        if (($filters['needs_attention'] ?? null) === '1') {
            $query->havingRaw("SUM(CASE WHEN reconciliation_rows.status IN ('WARNING', 'REJECTED', 'DRAFT') THEN 1 ELSE 0 END) > 0");
        }

        $machines = $query
            ->orderByDesc('error_days')
            ->orderByDesc('warning_days')
            ->orderByDesc('unconfirmed_days')
            ->orderBy('asset_code')
            ->limit(250)
            ->get();

        return [
            'summary' => $this->summary($period),
            'machines' => $machines,
        ];
    }

    private function summary(ReconciliationPeriod $period): object
    {
        return DB::table('reconciliation_rows')
            ->where('reconciliation_period_id', $period->id)
            ->select([
                DB::raw('COUNT(*) as total_rows'),
                DB::raw("SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_rows"),
                DB::raw("SUM(CASE WHEN status IN ('WARNING', 'REJECTED', 'DRAFT') THEN 1 ELSE 0 END) as attention_rows"),
                DB::raw("SUM(CASE WHEN status <> 'CONFIRMED' THEN 1 ELSE 0 END) as unconfirmed_rows"),
            ])
            ->first();
    }

    private function firstExistingColumn(string $table, array $columns): string
    {
        return collect($columns)->first(fn (string $column) => $this->hasColumn($table, $column)) ?? 'id';
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        return $this->schema[$key] ??= Schema::hasColumn($table, $column);
    }
}
