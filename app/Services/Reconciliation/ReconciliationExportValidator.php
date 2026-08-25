<?php

namespace App\Services\Reconciliation;

use App\Models\Machine;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Collection;

class ReconciliationExportValidator
{
    public function validate(ReconciliationPeriod $period): array
    {
        $rows = $period->rows()
            ->with(['machine:id,asset_code', 'commandCenter:id,name'])
            ->orderBy('machine_id')
            ->orderBy('work_date')
            ->orderBy('segment_start')
            ->get();

        $blocking = collect();
        $warnings = collect();

        if ($rows->isEmpty() && in_array($period->status, ['CONFIRMED', 'EXPORTED'], true)) {
            $blocking->push('Kỳ đối chiếu chưa có dòng dữ liệu để xuất.');
        }

        Machine::query()
            ->where('status', 'ACTIVE')
            ->where('created_at', '<=', $period->date_to->copy()->endOfDay())
            ->whereDoesntHave('assignments', function ($query) use ($period): void {
                $query->where('time_in', '<=', $period->date_to->copy()->endOfDay())
                    ->where(function ($assignmentQuery) use ($period): void {
                        $assignmentQuery->whereNull('time_out')
                            ->orWhere('time_out', '>=', $period->date_from->copy()->startOfDay());
                    });
            })
            ->orderBy('asset_code')
            ->pluck('asset_code')
            ->each(fn (string $assetCode) => $blocking->push(
                $assetCode.': đang hoạt động nhưng không có lịch phân BCH trong kỳ.'
            ));

        foreach ($rows as $row) {
            $label = $this->rowLabel($row);

            if (!$row->command_center_id) {
                $blocking->push($label.': chưa xác định BCH.');
            }

            if (!$row->project_id) {
                $blocking->push($label.': chưa xác định dự án.');
            }

            if (!$row->segment_start || !$row->segment_end) {
                $blocking->push($label.': thiếu khoảng giờ thuộc BCH.');
            }

            if (!$row->gps_check_in || !$row->gps_check_out) {
                $warnings->push($label.': chưa có định vị.');
            }
        }

        $rows->groupBy(fn ($row) => $row->machine_id.'|'.$row->work_date?->format('Y-m-d'))
            ->each(function (Collection $dailyRows) use ($blocking, $warnings): void {
                $dailyRows = $dailyRows->values();

                for ($left = 0; $left < $dailyRows->count(); $left++) {
                    for ($right = $left + 1; $right < $dailyRows->count(); $right++) {
                        $first = $dailyRows[$left];
                        $second = $dailyRows[$right];

                        if ($first->command_center_id === $second->command_center_id) {
                            continue;
                        }

                        $pairLabel = $this->pairLabel($first, $second);
                        [$firstStart, $firstEnd] = $this->effectiveRange($first);
                        [$secondStart, $secondEnd] = $this->effectiveRange($second);

                        if (!$firstStart || !$firstEnd || !$secondStart || !$secondEnd) {
                            continue;
                        }

                        if ($firstStart === $secondStart && $firstEnd === $secondEnd) {
                            $blocking->push($pairLabel.': hai BCH có khoảng giờ giống hệt nhau.');
                            continue;
                        }

                        if ($this->overlaps($firstStart, $firstEnd, $secondStart, $secondEnd)) {
                            $warnings->push($pairLabel.': hai khoảng giờ BCH chồng lấn, cần kiểm tra.');
                        }
                    }
                }
            });

        return [
            'blocking' => $blocking->unique()->values(),
            'warnings' => $warnings->unique()->values(),
            'can_export' => $blocking->isEmpty(),
        ];
    }

    private function effectiveRange($row): array
    {
        return [
            $row->confirmed_check_in ?: $row->segment_start,
            $row->confirmed_check_out ?: $row->segment_end,
        ];
    }

    private function overlaps(string $firstStart, string $firstEnd, string $secondStart, string $secondEnd): bool
    {
        return $firstStart < $secondEnd && $secondStart < $firstEnd;
    }

    private function rowLabel($row): string
    {
        return sprintf(
            '%s ngày %s',
            $row->machine?->asset_code ?? 'Máy #'.$row->machine_id,
            $row->work_date?->format('d/m/Y')
        );
    }

    private function pairLabel($first, $second): string
    {
        return sprintf(
            '%s (%s và %s)',
            $this->rowLabel($first),
            $first->commandCenter?->name ?? 'BCH chưa rõ',
            $second->commandCenter?->name ?? 'BCH chưa rõ'
        );
    }
}
