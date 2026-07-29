<?php

namespace App\Exports;

use App\Models\Driver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DriversExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly Collection $drivers)
    {
    }

    public function collection(): Collection
    {
        return $this->drivers->map(function (Driver $driver) {
            return [
                'Họ tên' => $driver->name,
                'SĐT' => $driver->phone,
                'CCCD' => $driver->cccd_no,
            ];
        });
    }

    public function headings(): array
    {
        return ['Họ tên', 'SĐT', 'CCCD'];
    }
}
