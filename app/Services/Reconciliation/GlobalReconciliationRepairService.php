<?php

namespace App\Services\Reconciliation;

use App\Models\ActivityLog;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRepairRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GlobalReconciliationRepairService
{
    public function __construct(private readonly ReconciliationLinkRepairService $repair) {}

    /** No persisted run, cache, mutation or write-and-rollback preview. */
    public function preview(User $actor): array
    {
        $periods = [];
        foreach (ReconciliationPeriod::query()->orderBy('id')->cursor() as $period) {
            $entry = ['period_id' => $period->id, 'name' => $period->name, 'status' => 'SKIPPED'];
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                $entry['reason'] = 'PROTECTED_PERIOD';
                $entry['protected_rows'] = $period->rows()->count();
            } elseif (! Gate::forUser($actor)->allows('repairLinks', $period)) {
                $entry['reason'] = 'UNAUTHORIZED_PERIOD';
            } else {
                try {
                    $entry['plan'] = $this->repair->plan($period);
                    $entry['status'] = 'PENDING';
                } catch (Throwable $exception) {
                    Log::warning('Reconciliation repair preview failed', ['period_id' => $period->id, 'exception_type' => $exception::class]);
                    $entry['reason'] = 'PREVIEW_FAILED';
                }
            }
            $periods[$period->id] = $entry;
        }

        return ['id' => (string) Str::uuid(), 'actor' => $actor->id, 'expires' => now()->addMinutes(30)->timestamp, 'periods' => $periods];
    }

    public function start(User $actor, array $preview): ReconciliationRepairRun
    {
        if (($preview['actor'] ?? null) !== $actor->id || ($preview['expires'] ?? 0) < now()->timestamp
            || ! Str::isUuid($preview['id'] ?? '') || ! isset($preview['periods']) || ! is_array($preview['periods'])) {
            throw new \DomainException('INVALID_OR_EXPIRED_PREVIEW');
        }
        $existing = ReconciliationRepairRun::find($preview['id']);
        if ($existing) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($actor, $preview) {
                $periods = $preview['periods'];
                $pending = collect($periods)->contains(fn ($p) => $p['status'] === 'PENDING');
                $run = ReconciliationRepairRun::create(['id' => $preview['id'], 'user_id' => $actor->id,
                    'active_slot' => $pending ? 'global' : null, 'status' => $pending ? 'PENDING' : 'COMPLETED_WITH_REVIEW', 'periods' => $periods]);
                ActivityLog::create(['user_id' => $actor->id, 'event' => 'reconciliation.global_repair_requested',
                    'subject_type' => ReconciliationRepairRun::class, 'subject_id' => null,
                    'description' => 'Xếp lịch sửa liên kết các kỳ đã xem trước.',
                    'properties' => ['repair_run_id' => $run->id, 'period_ids' => array_keys($periods)], 'occurred_at' => now()]);

                return $run;
            });
        } catch (QueryException $exception) {
            if ($existing = ReconciliationRepairRun::find($preview['id'])) {
                return $existing;
            }
            if (ReconciliationRepairRun::where('active_slot', 'global')->exists()) {
                throw new \DomainException('GLOBAL_REPAIR_ALREADY_RUNNING');
            }
            throw $exception;
        }
    }

    /** One period per transaction; data, audits and progress commit together. */
    public function processNext(): bool
    {
        $runId = null;
        $periodId = null;
        try {
            return DB::transaction(function () use (&$runId, &$periodId) {
                $run = ReconciliationRepairRun::where('active_slot', 'global')->lockForUpdate()->first();
                if (! $run) {
                    return false;
                }
                $runId = $run->id;
                $periods = $run->periods;
                $periodId = array_key_first(array_filter($periods, fn ($p) => $p['status'] === 'PENDING'));
                if ($periodId === null) {
                    $this->finish($run, $periods);

                    return false;
                }
                $entry = $periods[$periodId];
                $period = ReconciliationPeriod::query()->lockForUpdate()->find($periodId);
                $actor = User::find($run->user_id);
                if (! $period || ! $actor || ! Gate::forUser($actor)->allows('repairAll', ReconciliationPeriod::class)
                    || ! Gate::forUser($actor)->allows('repairLinks', $period)) {
                    $entry['status'] = 'SKIPPED';
                    $entry['reason'] = ! $period ? 'PERIOD_MISSING' : 'PROTECTED_OR_UNAUTHORIZED';
                } else {
                    try {
                        $entry['result'] = $this->repair->repairExpected($period, $actor->id, $entry['plan']['snapshot_fingerprint'], $run->id);
                        $entry['status'] = 'COMPLETED';
                    } catch (\DomainException $exception) {
                        if ($exception->getMessage() !== 'STALE_PREVIEW') {
                            throw $exception;
                        }
                        $entry['status'] = 'SKIPPED';
                        $entry['reason'] = 'STALE_PREVIEW';
                    }
                }
                $periods[$periodId] = $entry;
                $this->finish($run, $periods);
                ActivityLog::create(['user_id' => $run->user_id, 'subject_type' => ReconciliationPeriod::class,
                    'subject_id' => $periodId, 'event' => 'reconciliation.global_repair_period',
                    'description' => 'Kết quả sửa liên kết một kỳ trong lượt toàn cục.', 'occurred_at' => now(),
                    'properties' => ['repair_run_id' => $run->id, 'period_id' => $periodId, 'status' => $entry['status'],
                        'reason' => $entry['reason'] ?? null, 'proof_fingerprint' => $entry['plan']['snapshot_fingerprint'],
                        'result' => $entry['result'] ?? null]]);

                return true;
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('Reconciliation repair period failed', ['repair_run_id' => $runId, 'period_id' => $periodId, 'exception_type' => $exception::class]);
            if ($runId === null || $periodId === null) {
                throw $exception;
            }
            DB::transaction(function () use ($runId, $periodId) {
                $run = ReconciliationRepairRun::query()->lockForUpdate()->findOrFail($runId);
                $periods = $run->periods;
                if ($periods[$periodId]['status'] === 'PENDING') {
                    $periods[$periodId]['status'] = 'FAILED';
                    $periods[$periodId]['reason'] = 'PERIOD_TRANSACTION_FAILED';
                    $this->finish($run, $periods);
                    ActivityLog::create(['user_id' => $run->user_id, 'event' => 'reconciliation.global_repair_period_failed',
                        'subject_type' => ReconciliationPeriod::class, 'subject_id' => $periodId,
                        'description' => 'Kỳ thất bại; transaction dữ liệu đã rollback.', 'occurred_at' => now(),
                        'properties' => ['repair_run_id' => $run->id, 'period_id' => $periodId, 'reason' => 'PERIOD_TRANSACTION_FAILED']]);
                }
            });

            return true;
        }
    }

    private function finish(ReconciliationRepairRun $run, array $periods): void
    {
        $pending = collect($periods)->contains(fn ($p) => $p['status'] === 'PENDING');
        $failed = collect($periods)->contains(fn ($p) => $p['status'] === 'FAILED');
        $review = collect($periods)->contains(fn ($p) => $p['status'] === 'SKIPPED' || ($p['result']['unresolved'] ?? 0) > 0);
        $run->update(['periods' => $periods, 'active_slot' => $pending ? 'global' : null,
            'status' => $pending ? 'RUNNING' : ($failed ? 'PARTIAL' : ($review ? 'COMPLETED_WITH_REVIEW' : 'COMPLETED'))]);
    }
}
