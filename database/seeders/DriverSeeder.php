<?php

namespace Database\Seeders;

use App\Models\Driver;
use Illuminate\Database\Seeder;

class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $drivers = [
            ['name' => 'Nguyễn Văn Nam', 'phone' => '0900000001'],
            ['name' => 'Trần Văn Hậu', 'phone' => '0900000002'],
            ['name' => 'Phạm Thị Thu', 'phone' => '0900000003'],
        ];

        foreach ($drivers as $driver) {
            Driver::firstOrCreate(['name' => $driver['name']], $driver);
        }
    }
}
