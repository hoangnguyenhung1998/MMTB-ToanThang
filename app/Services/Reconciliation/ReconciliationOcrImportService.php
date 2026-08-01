<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconciliationOcrImportService
{
    public function __construct(
        private ReconciliationCalculator $calculator,
    ) {
    }

    public function import(ReconciliationPeriod $period, array $preview): array
    {
        $rows = collect($preview['rows'] ?? []);

        return DB::transaction(function () use ($period, $rows): array {
            $summary = [
                'total_source' => $rows->count(),
                'importable' => $rows->whereIn('status', ['valid', 'warning'])->count(),
                'imported' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'skipped' => 0,
                'failed' => 0,
                'warning_imported' => 0,
                'invalid_skipped' => $rows->where('status', 'invalid')->count(),
                'empty_skipped' => $rows->where('status', 'skipped')->count(),
                'text_only_updated' => 0,
            ];

            foreach ($rows as $previewRow) {
                if (($previewRow['status'] ?? null) === 'invalid') {
                    $summary['skipped']++;
                    continue;
                }

                if (($previewRow['status'] ?? null) === 'skipped' && ! $this->hasImportableText($previewRow['source'] ?? [])) {
                    $summary['skipped']++;
                    continue;
                }

                $row = ReconciliationRow::query()
                    ->where('reconciliation_period_id', $period->id)
                    ->where('machine_id', $previewRow['machine_id'])
                    ->whereDate('work_date', $previewRow['source']['work_date'])
                    ->lockForUpdate()
                    ->first();

                if (! $row || $row->status === 'LOCKED') {
                    $summary['skipped']++;
                    continue;
                }

                $changes = $this->changesFor($row, $previewRow);

                if ($changes === []) {
                    $summary['unchanged']++;
                    continue;
                }

                if (in_array($row->status, ['REVIEWED', 'REJECTED', 'CONFIRMED'], true)) {
                    $changes = array_merge($changes, $this->workflowResetPayload($row));
                }

                $row->fill($changes);
                $row->save();

                $summary['updated']++;
                $summary['imported']++;

                if (($previewRow['status'] ?? null) === 'warning') {
                    $summary['warning_imported']++;
                }

                if (($previewRow['status'] ?? null) === 'skipped') {
                    $summary['text_only_updated']++;
                }
            }

            return $summary;
        });
    }

    private function changesFor(ReconciliationRow $row, array $previewRow): array
    {
        $source = $previewRow['source'];
        $payload = [
            'project_id' => $previewRow['project_id'] ?? null,
            'command_center_id' => $previewRow['command_center_id'] ?? null,
            'driver_id' => $previewRow['driver_id'] ?? null,
            'source_key' => $source['key'] ?? null,
            'source_bch' => $source['source_bch'] ?? null,
        ];

        $this->putManualFieldIfBlank($row, $payload, 'location', $source['location'] ?? null);
        $this->putManualFieldIfBlank($row, $payload, 'work_content', $source['work_content'] ?? null);
        $this->putManualFieldIfBlank($row, $payload, 'explanation', $source['explanation'] ?? null);
        $this->putManualFieldIfBlank($row, $payload, 'review_note', $source['explanation'] ?? null);
        $this->putManualFieldIfBlank($row, $payload, 'warning_note', $this->warningText($previewRow));
        $this->putManualFieldIfBlank($row, $payload, 'ocr_warning', $this->warningText($previewRow));
        $this->putManualFieldIfBlank($row, $payload, 'note', $this->warningText($previewRow));

        $payload = array_merge($payload, $this->intervalPayload($row, $source['intervals'] ?? []));
        $payload = $this->supportedPayload($row, $payload);

        return collect($payload)
            ->reject(fn ($value) => blank($value))
            ->reject(fn ($value, string $key) => (string) ($row->{$key} ?? '') === (string) $value)
            ->all();
    }

    private function intervalPayload(ReconciliationRow $row, array $intervals): array
    {
        $payload = [];

        foreach ($intervals as $interval) {
            $key = $interval['key'] ?? null;

            if (! $key) {
                continue;
            }

            $this->putFirstSupported($row, $payload, $this->intervalColumns($key, 'start'), $interval['start'] ?? null);
            $this->putFirstSupported($row, $payload, $this->intervalColumns($key, 'end'), $interval['end'] ?? null);
        }

        $firstInterval = collect($intervals)->first();
        $lastInterval = collect($intervals)->last();

        $this->putFirstSupported($row, $payload, ['ocr_start_time', 'ocr_start_at', 'start_time'], $firstInterval['start'] ?? null);
        $this->putFirstSupported($row, $payload, ['ocr_end_time', 'ocr_end_at', 'end_time'], $lastInterval['end'] ?? null);

        return $payload;
    }

    private function intervalColumns(string $interval, string $edge): array
    {
        return [
            "{$interval}_{$edge}_time",
            "{$interval}_{$edge}_at",
            "ocr_{$interval}_{$edge}_time",
            "ocr_{$interval}_{$edge}_at",
        ];
    }

    private function putFirstSupported(ReconciliationRow $row, array &$payload, array $columns, mixed $value): void
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($row->getTable(), $column)) {
                $payload[$column] = $value;
                return;
            }
        }
    }

    private function putManualFieldIfBlank(ReconciliationRow $row, array &$payload, string $column, mixed $value): void
    {
        if (blank($value) || ! Schema::hasColumn($row->getTable(), $column) || filled($row->{$column} ?? null)) {
            return;
        }

        $payload[$column] = $value;
    }

    private function hasImportableText(array $source): bool
    {
        return filled($source['location'] ?? null)
            || filled($source['explanation'] ?? null)
            || filled($source['work_content'] ?? null)
            || filled($source['source_bch'] ?? null)
            || filled($source['key'] ?? null);
    }

    private function warningText(array $previewRow): ?string
    {
        return collect($previewRow['warnings'] ?? [])->filter()->implode('; ') ?: null;
    }

    private function supportedPayload(ReconciliationRow $row, array $payload): array
    {
        $fillable = $row->getFillable();

        if ($fillable === []) {
            return collect($payload)
                ->filter(fn ($value, string $key) => Schema::hasColumn($row->getTable(), $key))
                ->all();
        }

        return collect($payload)
            ->only($fillable)
            ->filter(fn ($value, string $key) => Schema::hasColumn($row->getTable(), $key))
            ->all();
    }

    private function workflowResetPayload(ReconciliationRow $row): array
    {
        return $this->supportedPayload($row, [
            'status' => 'DRAFT',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'review_note' => null,
            'confirmation_note' => null,
        ]);
    }
}
