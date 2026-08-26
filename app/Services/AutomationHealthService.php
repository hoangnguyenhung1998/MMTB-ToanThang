<?php

namespace App\Services;

use App\Models\AutomationIncident;
use App\Models\AutomationNode;
use App\Models\AutomationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutomationHealthService
{
    public function heartbeat(AutomationNode $node, array $payload): Collection
    {
        return DB::transaction(function () use ($node, $payload): Collection {
            $now = now();
            $node->update([
                'last_heartbeat_at' => $now,
                'agent_version' => $payload['agent_version'] ?? $node->agent_version,
                'metadata' => $payload['metadata'] ?? $node->metadata,
            ]);

            return collect($payload['services'])->map(function (array $data) use ($node, $now): AutomationService {
                $errors = (int) ($data['consecutive_errors'] ?? 0);
                $reported = $data['status'];
                if ($reported !== 'PAUSED' && $errors >= config('automation_health.consecutive_errors')) {
                    $reported = 'DEGRADED';
                }

                $service = $node->services()->updateOrCreate(
                    ['service_key' => $data['service_key']],
                    [
                        'name' => $data['name'],
                        'service_type' => $data['service_type'],
                        'reported_status' => $reported,
                        'last_heartbeat_at' => $now,
                        'last_success_at' => $data['last_success_at'] ?? null,
                        'version' => $data['version'] ?? null,
                        'current_job' => $data['current_job'] ?? null,
                        'queue_depth' => $data['queue_depth'] ?? null,
                        'consecutive_errors' => $errors,
                        'last_error_code' => $data['error_code'] ?? null,
                        'last_error_message' => $data['error_message'] ?? null,
                        'metrics' => $data['metrics'] ?? null,
                    ]
                );

                $this->synchronizeIncident($service, $this->statusFor($service, $now));

                return $service;
            });
        });
    }

    public function statusFor(AutomationService $service, ?CarbonInterface $at = null): string
    {
        $at ??= now();
        if ($service->reported_status === 'PAUSED') {
            return 'PAUSED';
        }
        if (! $service->last_heartbeat_at || $service->last_heartbeat_at->diffInSeconds($at, false) > config('automation_health.offline_after_seconds')) {
            return 'OFFLINE';
        }
        if ($service->reported_status === 'DEGRADED'
            || $service->last_heartbeat_at->diffInSeconds($at, false) > config('automation_health.degraded_after_seconds')) {
            return 'DEGRADED';
        }

        return 'HEALTHY';
    }

    public function evaluateAll(): array
    {
        $counts = ['HEALTHY' => 0, 'DEGRADED' => 0, 'OFFLINE' => 0, 'PAUSED' => 0];
        AutomationService::query()->eachById(function (AutomationService $service) use (&$counts): void {
            $status = $this->statusFor($service);
            $counts[$status]++;
            $this->synchronizeIncident($service, $status);
        });

        return $counts;
    }

    public function synchronizeIncident(AutomationService $service, string $status): void
    {
        if (in_array($status, ['HEALTHY', 'PAUSED'], true)) {
            $service->incidents()->where('status', 'OPEN')->update([
                'status' => 'RESOLVED',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        $open = $service->incidents()->where('status', 'OPEN')->latest('started_at')->first();
        if ($open?->type === $status) {
            return;
        }
        if ($open) {
            $open->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
        }

        AutomationIncident::query()->create([
            'automation_service_id' => $service->id,
            'type' => $status,
            'severity' => $status === 'OFFLINE' ? 'CRITICAL' : 'WARNING',
            'status' => 'OPEN',
            'message' => $this->incidentMessage($service, $status),
            'context' => [
                'error_code' => $service->last_error_code,
                'error_message' => $service->last_error_message,
                'consecutive_errors' => $service->consecutive_errors,
            ],
            'started_at' => now(),
        ]);
    }

    private function incidentMessage(AutomationService $service, string $status): string
    {
        return match ($status) {
            'OFFLINE' => "{$service->name} không gửi heartbeat quá 5 phút.",
            default => $service->last_error_message ?: "{$service->name} đang hoạt động không ổn định.",
        };
    }
}
