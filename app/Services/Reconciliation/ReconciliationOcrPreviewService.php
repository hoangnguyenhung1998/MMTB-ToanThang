<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReconciliationOcrPreviewService
{
    private array $schema = [];

    public function preview(ReconciliationPeriod $period, array $parsedWorkbook): array
    {
        $sourceRows = collect($parsedWorkbook['rows'] ?? []);
        $periodStart = Carbon::parse($period->date_from)->startOfDay();
        $periodEnd = Carbon::parse($period->date_to)->startOfDay();
        $machineCodeColumn = $this->machineCodeColumn();
        $machineCodes = $sourceRows
            ->pluck('machine_code')
            ->filter(fn ($code) => filled($code))
            ->unique()
            ->values();

        $machines = $this->machinesByCode($machineCodeColumn, $machineCodes);
        $machineIds = $machines->pluck('id')->filter()->unique()->values();
        $assignments = $this->assignmentsByMachine($machineIds, $periodStart, $periodEnd);
        $driverHistories = $this->driverHistoriesByMachine($machineIds, $periodStart, $periodEnd);
        $reconciliationRows = $this->reconciliationRowsByKey((int) $period->id, $machineIds);
        $projectNames = $this->namesById('projects', $assignments->pluck('project_id'));
        $commandCenterNames = $this->namesById('command_centers', $assignments->pluck('command_center_id'));

        $seenKeys = [];

        $rows = $sourceRows->map(function (array $source) use (
            $period,
            $periodStart,
            $periodEnd,
            $machines,
            $assignments,
            $driverHistories,
            $reconciliationRows,
            $projectNames,
            $commandCenterNames,
            &$seenKeys,
        ): array {
            $errors = [];
            $warnings = [];
            $machine = null;
            $assignment = null;
            $row = null;
            $workDate = filled($source['work_date'] ?? null)
                ? Carbon::parse($source['work_date'])->startOfDay()
                : null;

            if (blank($source['work_date'] ?? null)) {
                $errors[] = 'Ngày không hợp lệ';
            }

            if (blank($source['machine_code'] ?? null)) {
                $errors[] = 'Thiếu mã tài sản';
            } else {
                $machine = $machines->get($this->machineLookupKey($source['machine_code']));

                if (! $machine) {
                    $errors[] = 'Không tìm thấy mã tài sản: '.$source['machine_code'];
                }
            }

            if ($workDate && ($workDate->lt($periodStart) || $workDate->gt($periodEnd))) {
                $errors[] = $this->outsidePeriodMessage($period, $workDate);
            }

            if (blank($source['machine_code'] ?? null) && blank($source['work_date'] ?? null)) {
                $warnings[] = 'Dòng trống mã tài sản/ngày';
            }

            if (filled($source['machine_code'] ?? null) && filled($source['work_date'] ?? null)) {
                $duplicateKey = $this->machineLookupKey($source['machine_code']).':'.$source['work_date'];

                if (isset($seenKeys[$duplicateKey])) {
                    $errors[] = 'Trùng máy/ngày trong file OCR';
                }

                $seenKeys[$duplicateKey] = true;
            }

            if ($machine && $workDate) {
                $assignment = $this->recordForDate($assignments->get($machine->id, collect()), $workDate);

                if (! $assignment) {
                    $warnings[] = 'Không có phân công trong ngày';
                }

                $row = $reconciliationRows->get($machine->id.':'.$workDate->toDateString());

                if (! $row) {
                    $errors[] = 'Không có dòng đối soát tương ứng trong kỳ';
                } elseif (in_array($row->status, ['CONFIRMED', 'LOCKED'], true)) {
                    $errors[] = 'Dòng đã xác nhận hoặc đã khóa, không thể nhập lại';
                }
            }

            $assignmentProject = $assignment ? ($projectNames[$assignment->project_id] ?? null) : null;
            $assignmentCommandCenter = $assignment ? ($commandCenterNames[$assignment->command_center_id] ?? null) : null;

            if (filled($source['source_bch'] ?? null) && filled($assignmentCommandCenter) && $this->differentText($source['source_bch'], $assignmentCommandCenter)) {
                $warnings[] = 'BCH không khớp lịch sử';
            }

            if (blank($source['location'] ?? null) || blank($source['explanation'] ?? null) || blank($source['work_content'] ?? null)) {
                $warnings[] = 'Có thể bổ sung sau trên web';
            }

            $driverHistory = $machine && $workDate
                ? $this->recordForDate($driverHistories->get($machine->id, collect()), $workDate)
                : null;
            $status = $this->statusFor($source, $errors, $warnings);

            return [
                'source' => $source,
                'machine_id' => $machine->id ?? null,
                'machine_code' => $source['machine_code'] ?? null,
                'machine_label' => $this->machineLabel($machine),
                'row_id' => $row->id ?? null,
                'assignment_id' => $assignment->id ?? null,
                'project_id' => $assignment->project_id ?? null,
                'project_label' => $assignmentProject,
                'command_center_id' => $assignment->command_center_id ?? null,
                'command_center_label' => $assignmentCommandCenter ?: ($source['source_bch'] ?? null),
                'driver_id' => $driverHistory->driver_id ?? null,
                'status' => $status,
                'errors' => $status === 'skipped' ? [] : $errors,
                'warnings' => $this->messagesFor($source, $warnings, $status),
            ];
        })->values();

        $displayRows = $this->displayRows($rows);

        return [
            'worksheet' => $parsedWorkbook['worksheet'] ?? ReconciliationOcrSpreadsheetParser::WORKSHEET,
            'rows' => $rows->all(),
            'display_rows' => $displayRows,
            'skipped_examples' => $rows->where('status', 'skipped')->take(10)->values()->all(),
            'summary' => $this->summary($rows, count($displayRows)),
        ];
    }

    private function displayRows(Collection $rows): array
    {
        $attentionRows = $rows->where('status', 'invalid')
            ->concat($rows->where('status', 'warning'))
            ->take(200);

        return $attentionRows
            ->concat($rows->where('status', 'valid')->take(50))
            ->values()
            ->all();
    }

    private function summary(Collection $rows, int $renderedRows): array
    {
        return [
            'total' => $rows->count(),
            'working_time' => $rows->filter(fn (array $row) => $row['source']['has_working_time_data'] ?? false)->count(),
            'blank_machine_day' => $rows->filter(fn (array $row) => blank($row['source']['machine_code'] ?? null) && blank($row['source']['work_date'] ?? null))->count(),
            'valid' => $rows->where('status', 'valid')->count(),
            'warning' => $rows->where('status', 'warning')->count(),
            'invalid' => $rows->where('status', 'invalid')->count(),
            'skipped' => $rows->where('status', 'skipped')->count(),
            'duplicate' => $rows->filter(fn (array $row) => in_array('Trùng máy/ngày trong file OCR', $row['errors'], true))->count(),
            'rendered' => $renderedRows,
        ];
    }

    private function machinesByCode(?string $machineCodeColumn, Collection $machineCodes): Collection
    {
        if (! $machineCodeColumn || $machineCodes->isEmpty()) {
            return collect();
        }

        return DB::table('machines')
            ->whereIn($machineCodeColumn, $machineCodes->all())
            ->get()
            ->keyBy(fn (object $machine) => $this->machineLookupKey($machine->{$machineCodeColumn}));
    }

    private function assignmentsByMachine(Collection $machineIds, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        if ($machineIds->isEmpty() || ! Schema::hasTable('machine_assignments')) {
            return collect();
        }

        $startColumn = $this->firstExistingColumn('machine_assignments', ['start_date', 'assigned_date', 'assigned_at', 'from_date', 'effective_from']);
        $endColumn = $this->firstExistingColumn('machine_assignments', ['end_date', 'released_date', 'released_at', 'to_date', 'effective_to']);

        $query = DB::table('machine_assignments')->whereIn('machine_id', $machineIds->all());

        if ($startColumn) {
            $query->whereDate($startColumn, '<=', $periodEnd->toDateString());
        }

        if ($endColumn) {
            $query->where(function ($query) use ($endColumn, $periodStart) {
                $query->whereNull($endColumn)->orWhereDate($endColumn, '>=', $periodStart->toDateString());
            });
        }

        if ($startColumn) {
            $query->orderByDesc($startColumn);
        }

        return $query->get()->groupBy('machine_id');
    }

    private function driverHistoriesByMachine(Collection $machineIds, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        if ($machineIds->isEmpty() || ! Schema::hasTable('machine_driver_histories')) {
            return collect();
        }

        $startColumn = $this->firstExistingColumn('machine_driver_histories', ['start_date', 'assigned_date', 'assigned_at', 'from_date', 'effective_from']);
        $endColumn = $this->firstExistingColumn('machine_driver_histories', ['end_date', 'released_date', 'released_at', 'to_date', 'effective_to']);

        $query = DB::table('machine_driver_histories')->whereIn('machine_id', $machineIds->all());

        if ($startColumn) {
            $query->whereDate($startColumn, '<=', $periodEnd->toDateString());
        }

        if ($endColumn) {
            $query->where(function ($query) use ($endColumn, $periodStart) {
                $query->whereNull($endColumn)->orWhereDate($endColumn, '>=', $periodStart->toDateString());
            });
        }

        if ($startColumn) {
            $query->orderByDesc($startColumn);
        }

        return $query->get()->groupBy('machine_id');
    }

    private function reconciliationRowsByKey(int $periodId, Collection $machineIds): Collection
    {
        if ($machineIds->isEmpty()) {
            return collect();
        }

        return DB::table('reconciliation_rows')
            ->where('reconciliation_period_id', $periodId)
            ->whereIn('machine_id', $machineIds->all())
            ->get()
            ->keyBy(fn (object $row) => $row->machine_id.':'.Carbon::parse($row->work_date)->toDateString());
    }

    private function namesById(string $table, Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty() || ! Schema::hasTable($table)) {
            return [];
        }

        $labelColumn = $this->firstExistingColumn($table, ['name', 'title', 'code']);

        if (! $labelColumn) {
            return [];
        }

        return DB::table($table)->whereIn('id', $ids->all())->pluck($labelColumn, 'id')->all();
    }

    private function recordForDate(Collection $records, Carbon $workDate): ?object
    {
        return $records->first(function (object $record) use ($workDate): bool {
            $start = $this->recordDate($record, ['start_date', 'assigned_date', 'assigned_at', 'from_date', 'effective_from']);
            $end = $this->recordDate($record, ['end_date', 'released_date', 'released_at', 'to_date', 'effective_to']);

            return (! $start || $workDate->greaterThanOrEqualTo($start))
                && (! $end || $workDate->lessThanOrEqualTo($end));
        });
    }

    private function recordDate(object $record, array $columns): ?Carbon
    {
        foreach ($columns as $column) {
            if (isset($record->{$column})) {
                return Carbon::parse($record->{$column})->startOfDay();
            }
        }

        return null;
    }

    private function machineCodeColumn(): ?string
    {
        return $this->firstExistingColumn('machines', ['asset_code', 'code', 'machine_code']);
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($this->hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        return $this->schema[$key] ??= Schema::hasColumn($table, $column);
    }

    private function outsidePeriodMessage(ReconciliationPeriod $period, Carbon $workDate): string
    {
        return sprintf(
            'Ngày %s nằm ngoài kỳ %s–%s',
            $workDate->format('d/m/Y'),
            Carbon::parse($period->date_from)->format('d/m/Y'),
            Carbon::parse($period->date_to)->format('d/m/Y'),
        );
    }

    private function statusFor(array $source, array $errors, array $warnings): string
    {
        if (filled($errors)) {
            return 'invalid';
        }

        if (! ($source['has_working_time_data'] ?? false)) {
            return 'skipped';
        }

        return filled($warnings) ? 'warning' : 'valid';
    }

    private function messagesFor(array $source, array $warnings, string $status): array
    {
        if ($status === 'skipped') {
            return ['Không phát sinh dữ liệu giờ làm việc'];
        }

        return $warnings;
    }

    private function differentText(string $left, string $right): bool
    {
        return Str::of($left)->ascii()->lower()->squish()->toString()
            !== Str::of($right)->ascii()->lower()->squish()->toString();
    }

    private function machineLookupKey(?string $code): string
    {
        return Str::of((string) $code)->replaceMatches('/\s+/u', ' ')->replace(['–', '—'], '-')->trim()->lower()->toString();
    }

    private function machineLabel(?object $machine): ?string
    {
        if (! $machine) {
            return null;
        }

        return collect(['asset_code', 'code', 'machine_code', 'name'])
            ->map(fn (string $column) => $machine->{$column} ?? null)
            ->filter()
            ->implode(' - ');
    }
}
