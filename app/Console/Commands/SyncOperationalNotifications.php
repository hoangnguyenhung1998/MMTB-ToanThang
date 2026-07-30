<?php

namespace App\Console\Commands;

use App\Services\NotificationSyncService;
use Illuminate\Console\Command;

class SyncOperationalNotifications extends Command
{
    protected $signature = 'notifications:sync-operational';

    protected $description = 'Đồng bộ cảnh báo vận hành vào trung tâm thông báo';

    public function handle(NotificationSyncService $service): int
    {
        $result = $service->syncForAllUsers();

        $this->info(
            "Đã tạo {$result['created']} thông báo mới, xóa {$result['resolved']} cảnh báo đã xử lý."
        );

        return self::SUCCESS;
    }
}
