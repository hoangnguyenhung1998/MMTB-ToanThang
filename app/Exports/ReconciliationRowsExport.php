<?php

namespace App\Exports;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationCalculator;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ReconciliationRowsExport implements FromArray, WithHeadings
{
    public function __construct(
        private readonly ReconciliationPeriod $period,
        private readonly ReconciliationCalculator $calculator
    ) {
    }

    public function headings(): array
    {
        return [
            'Kỳ',
            'Ngày',
            'Mã máy',
            'BCH',
            'Dự án',
            'Lái xe',
            'Trạng thái',
            'Biến động',
            'Logbook phút',
            'GPS phút',
            'Chênh lệch phút',
            'Chênh lệch tuyệt đối',
            'Mức lệch',
            'Giờ thường',
            'Nghỉ trưa',
            'OT chiều',
            'OT tối',
            'GPS vào',
            'GPS ra',
            'Địa điểm',
            'Nội dung',
            'Giải trình',
            'Ghi chú',
        ];
    }

    public function array(): array
    {
        return $this->period->rows()
            ->with(['machine', 'commandCenter:id,name', 'project:id,name', 'driver'])
            ->orderBy('work_date')
            ->orderBy('machine_id')
            ->get()
            ->map(function ($row) {
                $summary = $this->calculator->summaryFor($row);

                return [
                    $this->period->name,
                    $row->work_date?->format('Y-m-d'),
                    $row->machine?->asset_code ?? $row->machine?->code ?? ('Máy #'.$row->machine_id),
                    $row->commandCenter?->name,
                    $row->project?->name,
                    $row->driver?->name,
                    $row->status,
                    $row->change_type,
                    $summary['logbook_minutes'],
                    $summary['gps_minutes'],
                    $summary['difference_minutes'],
                    $summary['absolute_difference_minutes'],
                    $summary['variance'],
                    $row->regular_minutes,
                    $row->lunch_minutes,
                    $row->ot_afternoon_minutes,
                    $row->ot_evening_minutes,
                    $row->gps_check_in,
                    $row->gps_check_out,
                    $row->work_location,
                    $row->work_content,
                    $row->explanation,
                    $row->notes,
                ];
            })
            ->all();
    }
}
