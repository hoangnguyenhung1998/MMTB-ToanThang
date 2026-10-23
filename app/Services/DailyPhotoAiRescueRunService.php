<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DailyPhotoAiRescueRun;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Enqueue orchestration only; provider/attempt lifecycle stays in existing AI Rescue. */
class DailyPhotoAiRescueRunService
{
    public function __construct(private readonly DailyPhotoAiRescueBatchService $batch, private readonly DailyPhotoAiRescueService $rescue) {}

    public function start(array $payload, User $actor): DailyPhotoAiRescueRun
    {
        $run = DailyPhotoAiRescueRun::find($payload['id']);
        if (! $run) {
            try {
                $run = DB::transaction(function () use ($payload, $actor) {
                    $created = DailyPhotoAiRescueRun::create(['id' => $payload['id'], 'user_id' => $actor->id,
                        'job_ids' => $payload['job_ids'], 'reason_groups' => $payload['reason_groups'],
                        'selected_count' => count($payload['job_ids'])]);
                    ActivityLog::create(['user_id' => $actor->id, 'event' => 'ocr.ai_rescue_bulk_requested',
                        'description' => 'Đã ghi nhận yêu cầu AI Rescue; chưa đồng nghĩa OCR hoàn tất.',
                        'properties' => ['run_id' => $created->id, 'selected_count' => $created->selected_count, 'reason_groups' => $created->reason_groups], 'occurred_at' => now()]);

                    return $created;
                }, 3);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $error) {
                // Read AFTER rollback, outside the losing REPEATABLE READ snapshot.
                $run = DailyPhotoAiRescueRun::find($payload['id']);
                if (! $run) {
                    throw $error;
                }
            }
        }
        abort_unless((int) $run->user_id === (int) $actor->id && $run->job_ids === $payload['job_ids'], 403);

        return $run;
    }

    public function retry(DailyPhotoAiRescueRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = DailyPhotoAiRescueRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($run->status === 'FAILED', 409);
            $run->update(['status' => 'QUEUED', 'lease_until' => null, 'lease_token' => null, 'failures' => 0, 'error_code' => null, 'error_job_id' => null]);
        });
    }

    public function processNext(?string $id = null): bool
    {
        $claim = DB::transaction(function () use ($id) {
            $run = DailyPhotoAiRescueRun::whereIn('status', ['QUEUED', 'ENQUEUEING', 'OCR_RUNNING'])
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->when($id, fn ($q) => $q->whereKey($id))->orderBy('updated_at')->orderBy('id')->lockForUpdate()->first();
            if (! $run) {
                return null;
            }
            if ($run->failures >= 3) {
                $run->update(['status' => 'FAILED', 'error_code' => 'ABANDONED_ENQUEUE_UNIT', 'lease_token' => null, 'lease_until' => null]);

                return null;
            }
            $token = (string) Str::uuid();
            $run->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(2), 'failures' => $run->failures + 1]);

            return [$run->id, $token];
        }, 3);
        if (! $claim) {
            return false;
        }

        return DB::transaction(function () use ($claim) {
            $run = DailyPhotoAiRescueRun::lockForUpdate()->findOrFail($claim[0]);
            if ($run->lease_token !== $claim[1]) {
                return false;
            }
            try {
                DB::transaction(function () use ($run) {
                    $this->step($run);
                });
                $run->update(['failures' => 0, 'error_code' => null, 'error_job_id' => null, 'lease_token' => null, 'lease_until' => null]);
            } catch (Throwable $error) {
                $run->refresh();
                $run->update(['status' => $run->failures >= 3 ? 'FAILED' : 'ENQUEUEING', 'lease_token' => null, 'lease_until' => null,
                    'error_code' => class_basename($error), 'error_job_id' => $run->job_ids[$run->cursor] ?? null]);
                Log::error('AI Rescue enqueue unit failed', ['run_id' => $run->id, 'job_id' => $run->error_job_id, 'type' => $error::class]);
            }

            return true;
        }, 3);
    }

    private function step(DailyPhotoAiRescueRun $run): void
    {
        if ($run->enqueue_done) {
            $stats = $this->progress($run);
            $run->update(['status' => $stats['status']]);

            return;
        }
        $ids = $run->job_ids;
        if ($run->cursor >= count($ids)) {
            $run->update(['enqueue_done' => true, 'status' => 'OCR_RUNNING']);

            return;
        }
        $job = OcrJob::with(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase'])
            ->lockForUpdate()->find($ids[$run->cursor]);
        $attempt = null;
        $category = 'other_skipped';
        $created = false;
        if ($job) {
            $latest = $job->latestAiRescueAttempt()->first();
            $category = $this->batch->category($job, null, $latest);
            if ($category === 'eligible') {
                try {
                    $attempt = $this->rescue->request($job); // Same row lock, original domain validation.
                    $created = $attempt->wasRecentlyCreated;
                    $category = $created ? 'enqueued' : 'ai_processing';
                } catch (\Illuminate\Validation\ValidationException) {
                    // Source/eligibility may change between checks (e.g. storage disappears).
                    $category = 'other_skipped';
                }
            } elseif ($category === 'ai_processing') {
                $attempt = $latest; // Track shared existing work without another attempt/provider call.
            }
        }
        DB::table('daily_photo_ai_rescue_run_items')->insert(['run_id' => $run->id, 'ocr_job_id' => $ids[$run->cursor],
            'attempt_id' => $attempt?->id, 'category' => $category]);
        $run->update(['cursor' => $run->cursor + 1, 'enqueued' => $run->enqueued + (int) $created,
            'skipped' => $run->skipped + (int) ($attempt === null), 'status' => 'ENQUEUEING']);
    }

    /** Indexed aggregate of THIS run only. No image/model/backlog analysis on polling. */
    public function progress(DailyPhotoAiRescueRun $run): array
    {
        $row = DB::table('daily_photo_ai_rescue_run_items as i')->leftJoin('daily_photo_ai_rescue_attempts as a', 'a.id', '=', 'i.attempt_id')
            ->where('i.run_id', $run->id)
            ->selectRaw("SUM(CASE WHEN i.category='ai_processing' THEN 1 ELSE 0 END) shared")
            ->selectRaw("SUM(CASE WHEN a.status IN ('PENDING','RETRY') THEN 1 ELSE 0 END) queued")
            ->selectRaw("SUM(CASE WHEN a.status='PROCESSING' THEN 1 ELSE 0 END) processing")
            ->selectRaw("SUM(CASE WHEN a.final_resolution IN ('RESOLVED','NON_DAILY') THEN 1 ELSE 0 END) succeeded")
            ->selectRaw("SUM(CASE WHEN a.final_resolution='HUMAN_REQUIRED' OR a.final_resolution LIKE 'SKIPPED%' THEN 1 ELSE 0 END) review")
            ->selectRaw("SUM(CASE WHEN a.status='FAILED' THEN 1 ELSE 0 END) failed")->first();
        $counts = [];
        foreach (['queued', 'processing', 'succeeded', 'review', 'failed'] as $key) {
            $counts[$key] = (int) ($row->$key ?? 0);
        }
        $status = $run->status;
        if ($status !== 'FAILED' && $run->enqueue_done) {
            $status = $counts['queued'] + $counts['processing'] > 0 ? 'OCR_RUNNING' : ($counts['review'] + $counts['failed'] + $run->skipped > 0 ? 'COMPLETED_WITH_REVIEW' : 'COMPLETED');
        }

        return ['id' => $run->id, 'status' => $status, 'reason_groups' => $run->reason_groups, 'selected' => (int) $run->selected_count,
            'selection_done' => (int) $run->cursor, 'enqueued' => (int) $run->enqueued, 'skipped' => (int) $run->skipped,
            'already_queued' => (int) ($row->shared ?? 0), 'enqueue_done' => $run->enqueue_done, 'ocr' => $counts, 'error_code' => $run->error_code, 'error_job_id' => $run->error_job_id];
    }
}
