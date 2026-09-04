<?php

namespace App\Services;

use App\Models\AutomationNode;
use App\Models\AutomationOperationalCommand;
use App\Models\AutomationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomationOperationalCommandService
{
    public function __construct(private readonly TelegramAlertClient $telegram) {}

    public function create(AutomationService $service, int $userId, string $action, array $payload = []): AutomationOperationalCommand
    {
        if (in_array($action, ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'], true) && $service->service_type !== 'ZALO_COLLECTOR') {
            throw ValidationException::withMessages(['action' => 'Lệnh quản lý Zalo chỉ dành cho Zalo Collector.']);
        }
        if (in_array($action, ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'], true)
            && ! preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', (string) ($payload['account_id'] ?? ''))) {
            throw ValidationException::withMessages(['account_id' => 'Mã tài khoản Zalo không hợp lệ.']);
        }
        if (in_array($action, ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'], true)) {
            $account = collect(data_get($service->metrics, 'zalo_accounts', []))
                ->firstWhere('id', $payload['account_id']);
            if (! $account || ($action === 'ZALO_ACCOUNT_SWITCH' && ! ($account['ready'] ?? false))) {
                throw ValidationException::withMessages(['account_id' => 'Tài khoản chưa tồn tại hoặc chưa đủ phiên đăng nhập và nhóm Zalo.']);
            }
            if ($action === 'ZALO_GROUPS_UPDATE') {
                $groupIds = collect($payload['group_ids'] ?? [])->map(fn ($id) => (string) $id)->unique()->values();
                $availableIds = collect($account['groups'] ?? [])->pluck('id')->map(fn ($id) => (string) $id);
                if ($groupIds->isEmpty() || $groupIds->count() > 200 || $groupIds->diff($availableIds)->isNotEmpty()) {
                    throw ValidationException::withMessages(['group_ids' => 'Danh sách nhóm không hợp lệ hoặc chưa được Collector xác nhận.']);
                }
                $payload['group_ids'] = $groupIds->all();
            }
        }
        if ($service->commands()->whereIn('status', ['PENDING', 'PROCESSING'])->exists()) {
            throw ValidationException::withMessages(['action' => 'Dịch vụ đang có một lệnh chưa hoàn tất.']);
        }
        return $service->commands()->create([
            'automation_node_id' => $service->automation_node_id,
            'user_id' => $userId, 'action' => $action, 'status' => 'PENDING',
            'payload' => in_array($action, ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'], true) ? $payload : null,
        ]);
    }

    public function claim(AutomationNode $node, string $agentId, int $limit = 5): Collection
    {
        return DB::transaction(function () use ($node, $agentId, $limit): Collection {
            AutomationOperationalCommand::query()->where('automation_node_id', $node->id)
                ->where('status', 'PROCESSING')->where('lease_expires_at', '<=', now())
                ->update(['status' => 'PENDING', 'claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null]);
            $commands = AutomationOperationalCommand::query()->with('service')
                ->where('automation_node_id', $node->id)->where('status', 'PENDING')
                ->oldest('id')->lockForUpdate()->limit($limit)->get();
            foreach ($commands as $command) {
                $command->update([
                    'status' => 'PROCESSING', 'claimed_by' => $agentId, 'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds(config('automation_health.command_lease_seconds')),
                ]);
            }
            $commands->each->refresh();

            return $commands->load('service');
        });
    }

    public function complete(AutomationOperationalCommand $command, AutomationNode $node, array $result): AutomationOperationalCommand
    {
        $this->assertOwned($command, $node);
        $command->update(['status' => 'COMPLETED', 'result' => $result, 'completed_at' => now(), 'lease_expires_at' => null, 'error_message' => null]);
        $this->notifyZaloCommand($command, true);
        return $command->fresh();
    }

    public function fail(AutomationOperationalCommand $command, AutomationNode $node, string $error): AutomationOperationalCommand
    {
        $this->assertOwned($command, $node);
        $command->update(['status' => 'FAILED', 'error_message' => $error, 'failed_at' => now(), 'lease_expires_at' => null]);
        $this->notifyZaloCommand($command, false);
        return $command->fresh();
    }

    private function assertOwned(AutomationOperationalCommand $command, AutomationNode $node): void
    {
        abort_unless($command->automation_node_id === $node->id && $command->status === 'PROCESSING', 409, 'Command is not owned by this node.');
    }

    private function notifyZaloCommand(AutomationOperationalCommand $command, bool $successful): void
    {
        if (! in_array($command->action, ['ZALO_ACCOUNT_SWITCH', 'ZALO_GROUPS_UPDATE'], true) || ! $this->telegram->enabled()) return;
        try {
            $switching = $command->action === 'ZALO_ACCOUNT_SWITCH';
            $this->telegram->send(implode("\n", array_filter([
                $successful
                    ? ($switching ? '✅ Đã chuyển tài khoản Zalo Collector' : '✅ Đã lưu nhóm Zalo Collector')
                    : ($switching ? '❌ Chuyển tài khoản Zalo Collector thất bại' : '❌ Lưu nhóm Zalo Collector thất bại'),
                'Tài khoản: '.data_get($command->payload, 'account_id'),
                $switching ? null : 'Số nhóm: '.count(data_get($command->payload, 'group_ids', [])),
                'Node: '.$command->node()->value('name'),
                $successful ? ($switching ? 'Collector đã được khởi động lại.' : 'Cấu hình đã được lưu trên laptop.') : 'Lỗi: '.$command->error_message,
            ], fn ($line) => $line !== null)));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
