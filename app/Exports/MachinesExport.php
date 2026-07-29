<?php

namespace App\Exports;

use App\Models\Machine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MachinesExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly Collection $machines)
    {
    }

    public function collection(): Collection
    {
        return $this->machines->map(function (Machine $machine) {
            $currentAssignment = $machine->assignments->firstWhere('time_out', null);
            $latestDriverHistory = $machine->driverHistories->sortByDesc('started_at')->first();
            $driver = $latestDriverHistory?->driver;

            return [
                'Mã máy' => $machine->asset_code,
                'Tên loại máy' => $machine->machine_type,
                'Năm sản xuất' => $machine->manufacture_year,
                'Trạng thái' => $machine->status,
                'Công ty' => $machine->company,
                'Số khung' => $machine->chassis_no,
                'Số máy' => $machine->engine_no,
                'Dự án hiện tại' => $currentAssignment?->project?->name,
                'BCH hiện tại' => $currentAssignment?->commandCenter?->name,
                'Tài xế hiện tại' => $driver?->name,
                'SĐT tài xế' => $driver?->phone,
                'Số CCCD tài xế' => $driver?->cccd_no,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Mã máy',
            'Tên loại máy',
            'Năm sản xuất',
            'Trạng thái',
            'Công ty',
            'Số khung',
            'Số máy',
            'Dự án hiện tại',
            'BCH hiện tại',
            'Tài xế hiện tại',
            'SĐT tài xế',
            'Số CCCD tài xế',
        ];
    }
}
