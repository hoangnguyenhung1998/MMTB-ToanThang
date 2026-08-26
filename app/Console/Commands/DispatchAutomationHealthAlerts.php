<?php

namespace App\Console\Commands;

use App\Services\AutomationHealthAlertDispatcher;
use Illuminate\Console\Command;

class DispatchAutomationHealthAlerts extends Command
{
    protected $signature = 'automation:dispatch-alerts';
    protected $description = 'Gửi cảnh báo Telegram cho thay đổi trạng thái tiến trình tự động';

    public function handle(AutomationHealthAlertDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatch();
        $this->info("Đã gửi {$result['sent']}; lỗi {$result['failed']}.");
        return self::SUCCESS;
    }
}
