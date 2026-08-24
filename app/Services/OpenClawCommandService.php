<?php

namespace App\Services;

use App\Models\AiReconciliationJob;
use App\Models\OpenClawCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenClawCommandService
{
    public function __construct(private readonly AiReconciliationService $reconciliation)
    {
    }

    public function create(AiReconciliationJob $job, int $userId, array $data): OpenClawCommand
    {
        $active = $job->commands()
            ->whereIn('status', ['PENDING', 'PROCESSING', 'RETRY'])
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'instruction' => 'Job này đang có một yêu cầu OpenClaw chờ xử lý.',
            ]);
        }

        return $job->commands()->create([
            'user_id' => $userId,
            'action' => $data['action'],
            'instruction' => $data['instruction'],
            'status' => 'PENDING',
        ]);
    }

    public function claim(string $workerId, int $limit = 3): Collection
    {
        return DB::transaction(function () use ($workerId, $limit): Collection {
            $commands = OpenClawCommand::query()
                ->whereNotNull('ai_reconciliation_job_id')
                ->where(function ($query): void {
                    $query->whereIn('status', ['PENDING', 'RETRY'])
                        ->orWhere(function ($expired): void {
                            $expired->where('status', 'PROCESSING')
                                ->where('lease_expires_at', '<=', now());
                        });
                })
                ->oldest('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($commands as $command) {
                $command->update([
                    'status' => 'PROCESSING',
                    'claimed_by' => $workerId,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds((int) config('openclaw.lease_seconds')),
                    'attempts' => $command->attempts + 1,
                    'failed_at' => null,
                    'error_message' => null,
                ]);
            }

            return $commands->load('reconciliationJob.machine:id,asset_code,status');
        }, 3);
    }

    public function payload(OpenClawCommand $command): array
    {
        $job = $command->reconciliationJob;

        return [
            'id' => $command->id,
            'action' => $command->action,
            'instruction' => $command->instruction,
            'attempts' => $command->attempts,
            'reconciliation_job' => $this->reconciliation->payload($job),
            'previous_submissions' => $job->submissions()
                ->with('findings')
                ->latest('submitted_at')
                ->limit(5)
                ->get()
                ->toArray(),
        ];
    }

    public function complete(OpenClawCommand $command, string $workerId, array $data): OpenClawCommand
    {
        $this->ensureClaimOwner($command, $workerId);

        $command->update([
            'status' => 'COMPLETED',
            'result_summary' => $data['summary'],
            'result' => $data['result'] ?? null,
            'completed_at' => now(),
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'error_message' => null,
        ]);

        return $command->fresh();
    }

    public function fail(OpenClawCommand $command, string $workerId, string $error, bool $retryable): OpenClawCommand
    {
        $this->ensureClaimOwner($command, $workerId);

        $command->update([
            'status' => $retryable ? 'RETRY' : 'FAILED',
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'failed_at' => $retryable ? null : now(),
            'error_message' => $error,
        ]);

        return $command->fresh();
    }

    private function ensureClaimOwner(OpenClawCommand $command, string $workerId): void
    {
        if ($command->status !== 'PROCESSING' || $command->claimed_by !== $workerId) {
            throw ValidationException::withMessages([
                'worker_id' => 'This command is not claimed by the requesting worker.',
            ]);
        }
    }
}
