<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [
            'Dự án Bắc Ninh',
            'Dự án Hải Phòng',
            'Dự án Long An',
        ];

        foreach ($projects as $name) {
            Project::firstOrCreate(['name' => $name]);
        }
    }
}
