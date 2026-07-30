<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class MmtbHealthCheck extends Command
{
    protected $signature = 'mmtb:health-check';

    protected $description = 'Kiểm tra nhanh nền tảng MMTB sau các phase';

    public function handle(): int
    {
        $checks = [
            'Kết nối cơ sở dữ liệu' => fn () => DB::connection()->getPdo() !== null,
            'Bảng machines' => fn () => Schema::hasTable('machines'),
            'Bảng activity_logs' => fn () => Schema::hasTable('activity_logs'),
            'Bảng notifications' => fn () => Schema::hasTable('notifications'),
            'Route Operation Center' => fn () => Route::has('operation-center.index'),
            'Route Activity Center' => fn () => Route::has('activities.index'),
            'Route Notification Center' => fn () => Route::has('notifications.index'),
        ];

        $failed = 0;

        foreach ($checks as $label => $check) {
            try {
                $passed = (bool) $check();
            } catch (\Throwable $exception) {
                $passed = false;
            }

            $passed
                ? $this->components->info($label)
                : $this->components->error($label);

            if (!$passed) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("Có {$failed} mục chưa đạt.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('MMTB đang ở trạng thái ổn định.');

        return self::SUCCESS;
    }
}
