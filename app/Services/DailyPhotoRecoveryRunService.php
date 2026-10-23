<?php

namespace App\Services;

use App\Models\DailyPhotoRecoveryRun;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Database-backed minute scheduler; no resident queue worker or HTTP work. */
class DailyPhotoRecoveryRunService
{
    public function __construct(
        private readonly DailyPhotoBacklogService $backlog,
        private readonly DailyPhotoPairingService $pairing,
        private readonly DailyPhotoSyncService $sync,
        private readonly DailyPhotoCaseService $cases,
    ) {}

    public function start(User $actor, array $filters, ?string $requestId = null, string $mode = 'RECOVERY'): DailyPhotoRecoveryRun
    {
        abort_unless(in_array($mode, ['RECOVERY', 'DOWNSTREAM'], true), 422);
        ksort($filters);
        $id = $requestId ?? (string) Str::uuid();
        $slot = hash('sha256', (string) ($filters['sender_id'] ?? '*'));
        if ($run = DailyPhotoRecoveryRun::find($id)) {
            abort_unless($run->user_id === $actor->id && $run->filters == $filters && $run->mode === $mode, 409);

            return $run;
        }
        try {
            return DailyPhotoRecoveryRun::create([
                'id' => $id, 'user_id' => $actor->id, 'filters' => $filters, 'mode' => $mode,
                'active_slot' => $slot, 'job_ceiling' => OcrJob::max('id') ?? 0,
                'period_ceiling' => ReconciliationPeriod::max('id') ?? 0,
                'counters' => ['examined' => 0, 'recovered' => 0, 'queued_retry' => 0, 'manual' => 0,
                    'protected' => 0, 'changed' => 0, 'ignored' => 0, 'paired' => 0, 'synced' => 0, 'downstream_repaired' => 0],
            ]);
        } catch (QueryException $exception) {
            $run = DailyPhotoRecoveryRun::find($id) ?? DailyPhotoRecoveryRun::where('active_slot', $slot)->first();
            if (! $run) {
                throw $exception;
            }
            abort_unless($run->user_id === $actor->id && $run->filters == $filters && $run->mode === $mode, 409,
                'Người gửi đang có một yêu cầu khác. Theo dõi yêu cầu đó trước khi tạo phạm vi mới.');

            return $run;
        }
    }

    public function retry(DailyPhotoRecoveryRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = DailyPhotoRecoveryRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($run->status === 'FAILED', 409);
            $run->update(['status' => 'QUEUED', 'lease_token' => null, 'lease_until' => null, 'failures' => 0, 'error_code' => null, 'error_context' => null]);
        });
    }

    /** Each call commits ONE job, ONE case, or ONE machine/day/period and its progress. */
    public function processNext(?string $runId = null): bool
    {
        // Durable attempt starts BEFORE the business transaction. Fatal exit leaves a bounded lease;
        // three abandoned attempts stop instead of making the same unit crash forever.
        $claim = DB::transaction(function () use ($runId) {
            $run = DailyPhotoRecoveryRun::whereIn('status', ['QUEUED', 'RUNNING'])
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->when($runId, fn ($q) => $q->whereKey($runId))->orderBy('updated_at')->orderBy('id')
                ->lockForUpdate()->first();
            if (! $run) {
                return null;
            }
            if ($run->failures >= 3) {
                $run->update(['status' => 'FAILED', 'lease_token' => null, 'lease_until' => null,
                    'error_code' => 'ABANDONED_WORK_UNIT', 'error_context' => $run->error_context ?? ['job_cursor' => $run->job_cursor]]);

                return null;
            }
            $token = (string) Str::uuid();
            $run->update(['status' => 'RUNNING', 'lease_token' => $token, 'lease_until' => now()->addMinutes(2),
                'failures' => $run->failures + 1, 'error_context' => [
                    'stage' => $run->discovery_done ? 'DOWNSTREAM' : $run->mode, 'job_cursor' => $run->job_cursor]]);

            return ['id' => $run->id, 'token' => $token];
        }, 3);
        if (! $claim) {
            return false;
        }

        return DB::transaction(function () use ($claim) {
            $run = DailyPhotoRecoveryRun::lockForUpdate()->findOrFail($claim['id']);
            if ($run->lease_token !== $claim['token']) {
                return false;
            }
            $context = ['stage' => 'DISCOVERY', 'job_cursor' => $run->job_cursor];
            try {
                DB::transaction(function () use ($run, &$context) {
                    $this->step($run, $context);
                });
                $run->update(['lease_token' => null, 'lease_until' => null]);
            } catch (Throwable $exception) {
                $run->refresh();
                $run->update(['status' => $run->failures >= 3 ? 'FAILED' : 'RUNNING',
                    'lease_token' => null, 'lease_until' => null,
                    'error_code' => $exception instanceof \DomainException && preg_match('/^[A-Z_]+$/', $exception->getMessage())
                        ? $exception->getMessage() : class_basename($exception), 'error_context' => $context]);
                Log::error('Daily photo recovery step failed', ['run_id' => $run->id,
                    'step' => $context, 'exception_type' => $exception::class, 'failure' => $run->failures]);
            }

            return true;
        }, 3);
    }

    private function step(DailyPhotoRecoveryRun $run, array &$context): void
    {
        if (! User::whereKey($run->user_id)->exists()) {
            throw new \DomainException('RECOVERY_ACTOR_MISSING');
        }
        $counters = $run->counters;
        if (! $run->discovery_done) {
            $job = $this->backlog->query($run->filters, $run->mode === 'DOWNSTREAM' ? 'COMPLETED' : 'EXCEPTION')
                ->where('id', '>', $run->job_cursor)->where('id', '<=', $run->job_ceiling)
                ->lockForUpdate()->first();
            if (! $job) {
                $run->update(['discovery_done' => true, 'status' => 'RUNNING', 'failures' => 0, 'error_code' => null, 'error_context' => null]);

                return;
            }
            $context = ['stage' => $run->mode, 'job_id' => $job->id];
            $counters['examined']++;
            if ($run->mode === 'DOWNSTREAM') {
                if ($this->backlog->isProtected($job)) {
                    $counters['protected']++;
                } elseif (data_get($job->machine_resolution_metadata, 'backlog_recovered_at')
                    && $job->machine_id && $job->extracted_date && $job->extracted_time
                    && $this->matchesDownstreamFilters($job, $run->filters)) {
                    $old = [$job->daily_photo_case_id, $job->dailyPhotoCaseEvidence?->daily_photo_case_id];
                    $case = $this->cases->materialize($job, false);
                    $this->record($run, array_filter([...$old, $case?->id]), [$job->machine_id.'|'.$job->extracted_date->toDateString()]);
                    $counters['downstream_repaired']++;
                }
            } else {
                $result = $this->backlog->recover([...$run->filters, 'job' => $job->id],
                    fn ($caseIds, $days) => $this->record($run, $caseIds, $days));
                foreach (['recovered' => 'recovered', 'queued_retry' => 'queued_retry', 'manual' => 'still_exception',
                    'protected' => 'skipped_protected', 'changed' => 'eligibility_changed'] as $counter => $metric) {
                    $counters[$counter] += $result[$metric];
                }
                $counters['ignored'] += $result['ignored_hour_meter'] + $result['ignored_non_daily'];
            }
            $run->update(['job_cursor' => $job->id, 'counters' => $counters, 'status' => 'RUNNING', 'failures' => 0, 'error_code' => null, 'error_context' => null]);

            return;
        }
        $work = DB::table('daily_photo_recovery_work')->where('run_id', $run->id)->where('done', false)
            ->orderBy('stage')->orderBy('id')->lockForUpdate()->first();
        if (! $work) {
            $run->update(['status' => 'COMPLETED', 'active_slot' => null, 'finished_at' => now(), 'failures' => 0, 'error_code' => null, 'error_context' => null]);

            return;
        }
        $context = ['stage' => $work->stage, 'work_id' => $work->id, 'period_cursor' => $work->period_cursor];
        $payload = json_decode($work->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($work->stage === 'PAIR') {
            if (DB::table('daily_photo_case_evidence')->where('daily_photo_case_id', $payload['case_id'])->count() > 1000) {
                throw new \DomainException('PAIRING_CASE_EXCEEDS_SAFE_UNIT');
            }
            $this->pairing->recomputeMany([$payload['case_id']]);
            DB::table('daily_photo_recovery_work')->where('id', $work->id)->update(['done' => true]);
            $counters['paired']++;
        } else {
            $periodId = collect($payload['period_ids'])->filter(fn ($id) => $id > $work->period_cursor)->min();
            $period = $periodId ? ReconciliationPeriod::lockForUpdate()->find($periodId) : null;
            if ($periodId && (! $period || ! in_array($period->status, ['GENERATED', 'REVIEWING'], true))) {
                $context['period_id'] = $periodId;
                throw new \DomainException($period ? 'SYNC_PERIOD_BECAME_PROTECTED' : 'SYNC_PERIOD_MISSING');
            }
            if ($period) {
                $context['period_id'] = $period->id;
                $dateFrom = \Carbon\Carbon::parse($payload['date'])->subDay()->toDateString();
                if ($period->rows()->where('machine_id', $payload['machine_id'])->whereBetween('work_date', [$dateFrom, $payload['date'].' 23:59:59'])->count() > 1000
                    || OcrJob::where('machine_id', $payload['machine_id'])->where('document_type', 'DAILY_TIMEMARK')
                        ->whereBetween('extracted_date', [$dateFrom, $payload['date']])->count() > 1000) {
                    throw new \DomainException('SYNC_DAY_EXCEEDS_SAFE_UNIT');
                }
                $this->sync->sync($period, $payload['machine_id'], $payload['date']);
                DB::table('daily_photo_recovery_work')->where('id', $work->id)->update(['period_cursor' => $period->id]);
                $counters['synced']++;
            } else {
                DB::table('daily_photo_recovery_work')->where('id', $work->id)->update(['done' => true]);
            }
        }
        $run->update(['counters' => $counters, 'status' => 'RUNNING', 'failures' => 0, 'error_code' => null, 'error_context' => null]);
    }

    private function record(DailyPhotoRecoveryRun $run, array $caseIds, array $days): void
    {
        $caseIds = array_values(array_unique($caseIds));
        // Both sides of an evidence move need sync, not only recomputation of their cases.
        // This runs in the same transaction as mutation and the cursor/counter update.
        foreach (\App\Models\DailyPhotoCase::whereKey($caseIds)->orderBy('id')->lockForUpdate()->get() as $case) {
            $days[] = $case->machine_id.'|'.$case->work_date->toDateString();
        }
        $days = array_values(array_unique($days));
        foreach ($caseIds as $id) {
            DB::table('daily_photo_recovery_work')->insertOrIgnore(['run_id' => $run->id, 'work_key' => 'case:'.$id,
                'stage' => 'PAIR', 'payload' => json_encode(['case_id' => (int) $id], JSON_THROW_ON_ERROR)]);
        }
        foreach ($days as $day) {
            [$machine, $date] = explode('|', $day);
            // Snapshot exact downstream obligations INSIDE the recovery transaction. A period
            // becoming locked later must fail visibly, not disappear from a fresh eligibility query.
            $periodIds = ReconciliationPeriod::whereIn('status', ['GENERATED', 'REVIEWING'])
                ->where('id', '<=', $run->period_ceiling)->whereDate('date_from', '<=', $date)
                ->whereDate('date_to', '>=', \Carbon\Carbon::parse($date)->subDay()->toDateString())
                ->orderBy('id')->limit(101)->pluck('id')->all();
            if (count($periodIds) > 100) {
                throw new \DomainException('SYNC_PERIOD_SCOPE_EXCEEDS_SAFE_UNIT');
            }
            $existing = DB::table('daily_photo_recovery_work')->where('run_id', $run->id)
                ->where('work_key', 'day:'.$day)->lockForUpdate()->first();
            if ($existing) {
                $previous = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                $periodIds = array_values(array_unique([...$previous['period_ids'], ...$periodIds]));
                sort($periodIds);
                if (count($periodIds) > 100) {
                    throw new \DomainException('SYNC_PERIOD_SCOPE_EXCEEDS_SAFE_UNIT');
                }
            }
            $payload = json_encode(['machine_id' => (int) $machine, 'date' => $date, 'period_ids' => $periodIds], JSON_THROW_ON_ERROR);
            if ($existing) {
                DB::table('daily_photo_recovery_work')->where('id', $existing->id)->update(['payload' => $payload]);
            } else {
                DB::table('daily_photo_recovery_work')->insert(['run_id' => $run->id, 'work_key' => 'day:'.$day,
                    'stage' => 'SYNC', 'payload' => $payload]);
            }
        }
    }

    private function matchesDownstreamFilters(OcrJob $job, array $filters): bool
    {
        $row = $this->backlog->analyse(collect([$job->load('attachment.message', 'machine', 'dailyPhotoCaseEvidence.dailyPhotoCase')]), $filters)->first();

        return $row !== null && (empty($filters['reason']) || in_array($filters['reason'], $row['reasons'], true));
    }

    /** SELECT-only proof, restricted to jobs marked by the interrupted historical recovery. */
    public function diagnose(OcrJob $job, array $filters = []): array
    {
        $proof = ['job_id' => $job->id, 'missing' => false, 'protected' => $this->backlog->isProtected($job), 'reasons' => [], 'evidence' => []];
        if (! $this->backlog->query($filters, 'COMPLETED')->whereKey($job->id)->exists()
            || $proof['protected'] || $job->status !== 'COMPLETED' || $job->document_type !== 'DAILY_TIMEMARK'
            || ! data_get($job->machine_resolution_metadata, 'backlog_recovered_at')
            || ! $job->machine_id || ! $job->extracted_date || ! $job->extracted_time) {
            return $proof;
        }
        // Reuse receipt/ownership/filter rules, but NEVER apply extracted fields or requeue OCR.
        if (! $this->matchesDownstreamFilters($job, $filters)) {
            return $proof;
        }
        $membership = $job->dailyPhotoCaseEvidence;
        $case = $membership?->dailyPhotoCase;
        if (! $case || $job->daily_photo_case_id !== $case->id) {
            $proof['reasons'][] = 'MISSING_CASE_MEMBERSHIP';
        } elseif (! $case->pairing_computed_at || $membership->updated_at->gt($case->pairing_computed_at)
            || (int) data_get($case->pairing_diagnostics, 'evidence_count', -1) !== $case->evidenceMemberships()->count()) {
            $proof['reasons'][] = 'PAIRING_NOT_COMPUTED_AFTER_EVIDENCE';
        }
        $proof['evidence'][] = ['case_id' => $case?->id, 'membership_id' => $membership?->id,
            'pairing_computed_at' => $case?->pairing_computed_at?->toISOString(),
            'evidence_updated_at' => $membership?->updated_at?->toISOString(),
            'paired_evidence_count' => data_get($case?->pairing_diagnostics, 'evidence_count')];
        foreach (ReconciliationPeriod::whereIn('status', ['GENERATED', 'REVIEWING'])
            ->whereDate('date_from', '<=', $job->extracted_date)
            ->whereDate('date_to', '>=', $job->extracted_date->copy()->subDay())->cursor() as $period) {
            $rows = $period->rows()->where('machine_id', $job->machine_id)
                ->whereBetween('work_date', [$job->extracted_date->copy()->subDay()->toDateString(), $job->extracted_date->toDateString().' 23:59:59'])->get();
            if ($rows->isEmpty() && $case?->machine_assignment_id && $job->extracted_date->betweenIncluded($period->date_from, $period->date_to)) {
                $proof['reasons'][] = 'MISSING_RECONCILIATION_ROW:'.$period->id;
            }
            foreach ($rows as $row) {
                if ($row->manually_edited_at || $row->reviewed_at || $row->confirmed_at || $row->reviewed_by || $row->confirmed_by
                    || in_array($row->status, ['REVIEWED', 'CONFIRMED', 'REJECTED'], true)) {
                    continue;
                }
                $preview = $this->sync->preview($row);
                $expected = $this->sync->signatureForRow($row, $preview['sources']);
                if ($row->evidence_signature !== $expected) {
                    $proof['reasons'][] = 'STALE_EVIDENCE_SIGNATURE:'.$row->id;
                    $proof['evidence'][] = ['row_id' => $row->id, 'period_id' => $period->id,
                        'actual_signature' => $row->evidence_signature, 'expected_signature' => $expected];
                }
            }
        }
        $proof['missing'] = $proof['reasons'] !== [];

        return $proof;
    }
}
