<?php

namespace App\Console\Commands;

use App\Services\AutomationHealthService;
use Illuminate\Console\Command;

class EvaluateAutomationHealth extends Command
{
    protected $signature = 'automation:evaluate-health';

    protected $description = 'Đánh giá heartbeat và ghi nhận thay đổi trạng thái dịch vụ tự động';

    public function handle(AutomationHealthService $health): int
    {
        $counts = $health->evaluateAll();
        $this->info(collect($counts)->map(fn ($count, $status) => "{$status}: {$count}")->implode(' | '));

        return self::SUCCESS;
    }
}
