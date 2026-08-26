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
    public function __construct(private readonly AutomationHealthAlertRecorder $alerts) {}

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

                $existing = $node->services()->where('service_key', $data['service_key'])->first();
                $currentJob = $data['current_job'] ?? null;
                $jobStartedAt = $data['current_job_started_at'] ?? null;
                if ($currentJob && ! $jobStartedAt) {
                    $jobStartedAt = $existing?->current_job === $currentJob
                        ? $existing->current_job_started_at
                        : $now;
                }
                if (! $currentJob) $jobStartedAt = null;

                $service = $node->services()->updateOrCreate(
                    ['service_key' => $data['service_key']],
                    [
                        'name' => $data['name'],
                        'service_type' => $data['service_type'],
                        'reported_status' => $reported,
                        'last_heartbeat_at' => $now,
                        'last_success_at' => $data['last_success_at'] ?? $existing?->last_success_at,
                        'last_api_success_at' => $data['last_api_success_at'] ?? $existing?->last_api_success_at,
                        'last_job_success_at' => $data['last_job_success_at'] ?? $existing?->last_job_success_at,
                        'version' => $data['version'] ?? null,
                        'current_job' => $data['current_job'] ?? null,
                        'current_job_started_at' => $jobStartedAt,
                        'queue_depth' => $data['queue_depth'] ?? null,
                        'consecutive_errors' => $errors,
                        'last_error_code' => $data['error_code'] ?? null,
                        'last_error_message' => $data['error_message'] ?? null,
                        'metrics' => $data['metrics'] ?? null,
                    ]
                );

                $this->synchronizeIncident($service, $this->conditionFor($service, $now));

                return $service;
            });
        });
    }

    public function statusFor(AutomationService $service, ?CarbonInterface $at = null): string
    {
        $condition = $this->conditionFor($service, $at);
        return $condition === 'HUNG' ? 'DEGRADED' : $condition;
    }

    public function conditionFor(AutomationService $service, ?CarbonInterface $at = null): string
    {
        $at ??= now();
        if ($service->reported_status === 'PAUSED') {
            return 'PAUSED';
        }
        if (! $service->last_heartbeat_at || $service->last_heartbeat_at->diffInSeconds($at, false) > config('automation_health.offline_after_seconds')) {
            return 'OFFLINE';
        }
        if ($service->current_job_started_at
            && $service->current_job_started_at->diffInMinutes($at, false) > config('automation_health.hung_job_minutes')) {
            return 'HUNG';
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
            $condition = $this->conditionFor($service);
            $status = $condition === 'HUNG' ? 'DEGRADED' : $condition;
            $counts[$status]++;
            $this->synchronizeIncident($service, $condition);
        });

        return $counts;
    }

    public function synchronizeIncident(AutomationService $service, string $status): void
    {
        if (in_array($status, ['HEALTHY', 'PAUSED'], true)) {
            $service->incidents()->where('status', 'OPEN')->get()->each(function (AutomationIncident $incident): void {
                $incident->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
                $this->alerts->record($incident, 'RECOVERED');
            });
            return;
        }

        $open = $service->incidents()->where('status', 'OPEN')->latest('started_at')->first();
        if ($open?->type === $status) {
            return;
        }
        if ($open) {
            $open->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
        }

        $incident = AutomationIncident::query()->create([
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
        $this->alerts->record($incident, 'OPENED');
    }

    private function incidentMessage(AutomationService $service, string $status): string
    {
        return match ($status) {
            'OFFLINE' => "{$service->name} không gửi heartbeat quá 5 phút.",
            'HUNG' => "{$service->name} giữ job {$service->current_job} quá ".config('automation_health.hung_job_minutes').' phút.',
            default => $service->last_error_message ?: "{$service->name} đang hoạt động không ổn định.",
        };
    }
}
