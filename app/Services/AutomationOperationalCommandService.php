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
    public function create(AutomationService $service, int $userId, string $action): AutomationOperationalCommand
    {
        if ($service->commands()->whereIn('status', ['PENDING', 'PROCESSING'])->exists()) {
            throw ValidationException::withMessages(['action' => 'Dịch vụ đang có một lệnh chưa hoàn tất.']);
        }
        return $service->commands()->create([
            'automation_node_id' => $service->automation_node_id,
            'user_id' => $userId, 'action' => $action, 'status' => 'PENDING',
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
        return $command->fresh();
    }

    public function fail(AutomationOperationalCommand $command, AutomationNode $node, string $error): AutomationOperationalCommand
    {
        $this->assertOwned($command, $node);
        $command->update(['status' => 'FAILED', 'error_message' => $error, 'failed_at' => now(), 'lease_expires_at' => null]);
        return $command->fresh();
    }

    private function assertOwned(AutomationOperationalCommand $command, AutomationNode $node): void
    {
        abort_unless($command->automation_node_id === $node->id && $command->status === 'PROCESSING', 409, 'Command is not owned by this node.');
    }
}
