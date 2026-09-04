<?php

namespace App\Services;

use App\Models\AutomationOperationalCommand;
use App\Models\AutomationService;

class ZaloAccountManagementService
{
    public function collector(): ?AutomationService
    {
        return AutomationService::query()
            ->where('service_type', 'ZALO_COLLECTOR')
            ->latest('last_heartbeat_at')
            ->first();
    }

    public function commandStatus(AutomationOperationalCommand $command): array
    {
        $command->loadMissing('service');
        abort_unless($command->service?->service_type === 'ZALO_COLLECTOR', 404);

        $service = $command->service->fresh();
        $metrics = $service?->metrics ?? [];
        $confirmed = $command->status === 'COMPLETED' && $this->isConfirmed($command, $metrics);
        $failed = $command->status === 'FAILED';

        return [
            'id' => $command->id,
            'status' => $command->status,
            'done' => $failed || $confirmed,
            'successful' => $confirmed,
            'confirmed' => $confirmed,
            'active_account_id' => data_get($metrics, 'active_account_id'),
            'active_account_name' => data_get($metrics, 'active_account_name'),
            'message' => $this->statusMessage($command, $confirmed),
        ];
    }

    private function isConfirmed(AutomationOperationalCommand $command, array $metrics): bool
    {
        if ($command->action === 'ZALO_ACCOUNT_SWITCH') {
            return data_get($metrics, 'active_account_id') === data_get($command->payload, 'account_id');
        }
        if ($command->action === 'ZALO_GROUPS_UPDATE') {
            $account = collect(data_get($metrics, 'zalo_accounts', []))
                ->firstWhere('id', data_get($command->payload, 'account_id'));
            $actual = collect($account['groups'] ?? [])->where('enabled', true)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values();
            $expected = collect(data_get($command->payload, 'group_ids', []))->map(fn ($id) => (string) $id)->sort()->values();
            return $actual->all() === $expected->all();
        }
        return true;
    }

    private function statusMessage(AutomationOperationalCommand $command, bool $confirmed): string
    {
        if ($command->status === 'FAILED') {
            return $command->error_message ?: 'Lệnh không thực hiện được.';
        }
        if ($confirmed) {
            return $command->action === 'ZALO_ACCOUNT_SWITCH'
                ? 'Collector đã chuyển sang tài khoản mới và gửi xác nhận.'
                : 'Cài đặt nhóm đã được lưu và Collector đã xác nhận.';
        }
        if ($command->status === 'COMPLETED') {
            return 'Laptop đã xử lý xong; đang chờ heartbeat xác nhận trạng thái mới.';
        }
        return $command->status === 'PROCESSING'
            ? 'Laptop đang thực hiện lệnh…'
            : 'Lệnh đang chờ Health Agent tiếp nhận…';
    }
}
