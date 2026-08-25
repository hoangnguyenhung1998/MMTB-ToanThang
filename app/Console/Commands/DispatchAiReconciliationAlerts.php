<?php

namespace App\Console\Commands;

use App\Services\AiReconciliationAlertDispatcher;
use Illuminate\Console\Command;

class DispatchAiReconciliationAlerts extends Command
{
    protected $signature = 'reconciliation:dispatch-alerts {mode=urgent : urgent, warnings or daily}';

    protected $description = 'Gửi cảnh báo và báo cáo Telegram cho đối soát AI';

    public function handle(AiReconciliationAlertDispatcher $dispatcher): int
    {
        $result = match ($this->argument('mode')) {
            'urgent' => $dispatcher->dispatchUrgent(),
            'warnings' => $dispatcher->dispatchWarnings(),
            'daily' => $dispatcher->dispatchDailyDigest(),
            default => null,
        };

        if ($result === null) {
            $this->error('Mode phải là urgent, warnings hoặc daily.');

            return self::INVALID;
        }

        if ($result['skipped']) {
            $this->warn('Telegram chưa được bật hoặc chưa đủ cấu hình; cảnh báo vẫn được giữ trong hàng đợi.');

            return self::SUCCESS;
        }

        $this->info("Đã gửi {$result['sent']} cảnh báo; lỗi {$result['failed']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
