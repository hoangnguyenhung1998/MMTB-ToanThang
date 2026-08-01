<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconciliationMachineReviewService
{
    private array $schema = [];

    public function overview(ReconciliationPeriod $period, ?int $selectedMachineId = null, array $filters = []): array
    {
        $machines = $this->machineList($period, $filters);
        $selectedMachineId ??= optional($machines->first())->id;
        $selectedIndex = $machines->search(fn (object $machine) => $machine->id === $selectedMachineId);

        return [
            'machines' => $machines,
            'selectedMachine' => $machines->firstWhere('id', $selectedMachineId),
            'previousMachine' => $selectedIndex !== false && $selectedIndex > 0 ? $machines->get($selectedIndex - 1) : null,
            'nextMachine' => $selectedIndex !== false ? $machines->get($selectedIndex + 1) : null,
            'rows' => $selectedMachineId ? $this->rowsForMachine($period, $selectedMachineId, $filters) : collect(),
            'fields' => $this->editableFields(),
            'dateRange' => [
                'from' => Carbon::parse($period->date_from)->startOfDay(),
                'to' => Carbon::parse($period->date_to)->startOfDay(),
            ],
        ];
    }

    public function bulkUpdate(ReconciliationPeriod $period, int $machineId, array $rows, int $userId): array
    {
        return DB::transaction(function () use ($period, $machineId, $rows): array {
            $updated = 0;
            $unchanged = 0;
            $skipped = 0;

            $existingRows = DB::table('reconciliation_rows')
                ->where('reconciliation_period_id', $period->id)
                ->where('machine_id', $machineId)
                ->whereIn('id', array_keys($rows))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($rows as $rowId => $input) {
                if (! ($input['selected'] ?? false)) {
                    continue;
                }

                $row = $existingRows->get((int) $rowId);

                if (! $row || in_array($row->status, ['CONFIRMED', 'LOCKED'], true)) {
                    $skipped++;
                    continue;
                }

                $changes = $this->changesFor($row, $input);

                if ($changes === []) {
                    $unchanged++;
                    continue;
                }

                $changes = array_merge($changes, $this->workflowResetPayload());

                DB::table('reconciliation_rows')->where('id', $row->id)->update($changes);
                $updated++;
            }

            return compact('updated', 'unchanged', 'skipped');
        });
    }

    public function bulkConfirm(ReconciliationPeriod $period, int $machineId, array $rowIds, int $userId): array
    {
        return DB::transaction(function () use ($period, $machineId, $rowIds, $userId): array {
            $confirmed = 0;
            $skipped = 0;

            $rows = DB::table('reconciliation_rows')
                ->where('reconciliation_period_id', $period->id)
                ->where('machine_id', $machineId)
                ->whereIn('id', $rowIds)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                if (! in_array($row->status, ['REVIEWED', 'WARNING'], true)) {
                    $skipped++;
                    continue;
                }

                $payload = [
                    'status' => 'CONFIRMED',
                    'confirmed_by' => $userId,
                    'confirmed_at' => now(),
                    'updated_at' => now(),
                ];

                DB::table('reconciliation_rows')
                    ->where('id', $row->id)
                    ->update($this->supportedPayload($payload));

                $confirmed++;
            }

            return compact('confirmed', 'skipped');
        });
    }

    private function machineList(ReconciliationPeriod $period, array $filters): Collection
    {
        $machineCodeColumn = $this->firstExistingColumn('machines', ['asset_code', 'code', 'machine_code']);
        $machineNameColumn = $this->firstExistingColumn('machines', ['name', 'title', 'description']);

        $query = DB::table('reconciliation_rows')
            ->join('machines', 'machines.id', '=', 'reconciliation_rows.machine_id')
            ->where('reconciliation_rows.reconciliation_period_id', $period->id)
            ->select([
                'machines.id',
                DB::raw('COALESCE(machines.'.$machineCodeColumn.", '') as asset_code"),
                DB::raw('COALESCE(machines.'.$machineNameColumn.", '') as name"),
                DB::raw('COUNT(reconciliation_rows.id) as total_rows'),
                DB::raw("SUM(CASE WHEN reconciliation_rows.status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed_rows"),
            ])
            ->groupBy('machines.id', 'machines.'.$machineCodeColumn, 'machines.'.$machineNameColumn)
            ->orderBy('machines.'.$machineCodeColumn);

        if (filled($filters['machine_search'] ?? null)) {
            $query->where('machines.'.$machineCodeColumn, 'like', '%'.$filters['machine_search'].'%');
        }

        return $query->limit(250)->get();
    }

    private function rowsForMachine(ReconciliationPeriod $period, int $machineId, array $filters): Collection
    {
        $query = DB::table('reconciliation_rows')
            ->where('reconciliation_period_id', $period->id)
            ->where('machine_id', $machineId)
            ->whereDate('work_date', '>=', Carbon::parse($period->date_from)->toDateString())
            ->whereDate('work_date', '<=', Carbon::parse($period->date_to)->toDateString())
            ->orderBy('work_date');

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (($filters['only_unconfirmed'] ?? null) === '1') {
            $query->where('status', '<>', 'CONFIRMED');
        }

        return $query->get();
    }

    private function editableFields(): array
    {
        return collect(['location', 'work_content', 'explanation', 'review_note'])
            ->filter(fn (string $column) => $this->hasColumn('reconciliation_rows', $column))
            ->values()
            ->all();
    }

    private function changesFor(object $row, array $input): array
    {
        return collect($this->editableFields())
            ->mapWithKeys(fn (string $field) => [$field => $input[$field] ?? null])
            ->reject(fn ($value) => is_null($value))
            ->reject(fn ($value, string $field) => trim((string) $value) === trim((string) ($row->{$field} ?? '')))
            ->all();
    }

    private function workflowResetPayload(): array
    {
        return $this->supportedPayload([
            'status' => 'DRAFT',
            'reviewed_at' => null,
            'reviewed_by' => null,
            'confirmed_at' => null,
            'confirmed_by' => null,
            'updated_at' => now(),
        ]);
    }

    private function supportedPayload(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $column) => $this->hasColumn('reconciliation_rows', $column))
            ->all();
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
