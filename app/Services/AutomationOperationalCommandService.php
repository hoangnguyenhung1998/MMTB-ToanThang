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
        if ($action === 'ZALO_ACCOUNT_SWITCH' && $service->service_type !== 'ZALO_COLLECTOR') {
            throw ValidationException::withMessages(['action' => 'Chỉ Zalo Collector mới nhận lệnh chuyển tài khoản.']);
        }
        if ($action === 'ZALO_ACCOUNT_SWITCH' && ! preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', (string) ($payload['account_id'] ?? ''))) {
            throw ValidationException::withMessages(['account_id' => 'Mã tài khoản Zalo không hợp lệ.']);
        }
        if ($action === 'ZALO_ACCOUNT_SWITCH') {
            $account = collect(data_get($service->metrics, 'zalo_accounts', []))
                ->firstWhere('id', $payload['account_id']);
            if (! $account || ! ($account['ready'] ?? false)) {
                throw ValidationException::withMessages(['account_id' => 'Tài khoản chưa tồn tại hoặc chưa đủ phiên đăng nhập và nhóm Zalo.']);
            }
        }
        if ($service->commands()->whereIn('status', ['PENDING', 'PROCESSING'])->exists()) {
            throw ValidationException::withMessages(['action' => 'Dịch vụ đang có một lệnh chưa hoàn tất.']);
        }
        return $service->commands()->create([
            'automation_node_id' => $service->automation_node_id,
            'user_id' => $userId, 'action' => $action, 'status' => 'PENDING',
            'payload' => $action === 'ZALO_ACCOUNT_SWITCH' ? ['account_id' => $payload['account_id']] : null,
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
        $this->notifyZaloSwitch($command, true);
        return $command->fresh();
    }

    public function fail(AutomationOperationalCommand $command, AutomationNode $node, string $error): AutomationOperationalCommand
    {
        $this->assertOwned($command, $node);
        $command->update(['status' => 'FAILED', 'error_message' => $error, 'failed_at' => now(), 'lease_expires_at' => null]);
        $this->notifyZaloSwitch($command, false);
        return $command->fresh();
    }

    private function assertOwned(AutomationOperationalCommand $command, AutomationNode $node): void
    {
        abort_unless($command->automation_node_id === $node->id && $command->status === 'PROCESSING', 409, 'Command is not owned by this node.');
    }

    private function notifyZaloSwitch(AutomationOperationalCommand $command, bool $successful): void
    {
        if ($command->action !== 'ZALO_ACCOUNT_SWITCH' || ! $this->telegram->enabled()) return;
        try {
            $this->telegram->send(implode("\n", [
                $successful ? '✅ Đã chuyển tài khoản Zalo Collector' : '❌ Chuyển tài khoản Zalo Collector thất bại',
                'Tài khoản: '.data_get($command->payload, 'account_id'),
                'Node: '.$command->node()->value('name'),
                $successful ? 'Collector đã được khởi động lại.' : 'Lỗi: '.$command->error_message,
            ]));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
