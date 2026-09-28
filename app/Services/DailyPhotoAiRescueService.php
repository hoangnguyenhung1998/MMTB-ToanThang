<?php

namespace App\Services;

use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DailyPhotoAiRescueService
{
    public const ACTIVE_KEY = 'ACTIVE';

    private const PROTECTED_REVIEW_STATUSES = ['APPROVED', 'CORRECTED', 'REJECTED'];

    public function __construct(
        private readonly DailyPhotoBacklogService $backlog,
        private readonly AssetCodeResolver $assetCodes,
        private readonly DailyPhotoCaseService $dailyPhotoCases,
        private readonly DailyPhotoSyncService $dailyPhotoSync,
    ) {}

    public function request(OcrJob $job): DailyPhotoAiRescueAttempt
    {
        return DB::transaction(function () use ($job): DailyPhotoAiRescueAttempt {
            $job = $this->lockedJob($job->id);
            $active = DailyPhotoAiRescueAttempt::query()
                ->where('ocr_job_id', $job->id)
                ->where('active_key', self::ACTIVE_KEY)
                ->first();

            if ($active) {
                return $active;
            }

            if ($reason = $this->ineligibilityReason($job)) {
                throw ValidationException::withMessages([
                    'ocr_job' => "Daily Photo không đủ điều kiện AI Rescue: {$reason}.",
                ]);
            }

            return DailyPhotoAiRescueAttempt::query()->create([
                'ocr_job_id' => $job->id,
                'zalo_attachment_id' => $job->zalo_attachment_id,
                'source_sha256' => $job->attachment->sha256,
                'status' => 'PENDING',
                'active_key' => self::ACTIVE_KEY,
                'prompt_version' => (string) config('daily_photos.ai_rescue.prompt_version'),
                'schema_version' => (string) config('daily_photos.ai_rescue.schema_version'),
                'requested_at' => now(),
            ]);
        }, 3);
    }

    public function eligibilityReason(OcrJob $job, ?array $analysedRow = null): ?string
    {
        $job->loadMissing(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase']);

        return $this->ineligibilityReason($job, null, $analysedRow);
    }

    public function claim(string $workerId): ?DailyPhotoAiRescueAttempt
    {
        return DB::transaction(function () use ($workerId): ?DailyPhotoAiRescueAttempt {
            $maxAttempts = max(1, (int) config('daily_photos.ai_rescue.max_attempts', 3));

            for ($scan = 0; $scan < 20; $scan++) {
                $attempt = DailyPhotoAiRescueAttempt::query()
                    ->where('active_key', self::ACTIVE_KEY)
                    ->where(function ($query): void {
                        $query->whereIn('status', ['PENDING', 'RETRY'])
                            ->orWhere(function ($expired): void {
                                $expired->where('status', 'PROCESSING')
                                    ->where('lease_expires_at', '<=', now());
                            });
                    })
                    ->oldest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $attempt) {
                    return null;
                }

                if ((int) $attempt->attempts >= $maxAttempts) {
                    $attempt->update([
                        'status' => 'FAILED',
                        'active_key' => null,
                        'claimed_by' => null,
                        'claimed_at' => null,
                        'lease_expires_at' => null,
                        'error_message' => "Maximum of {$maxAttempts} AI Rescue attempts reached.",
                        'final_resolution' => 'FAILED',
                        'processed_at' => now(),
                    ]);

                    continue;
                }

                $attempt->update([
                    'status' => 'PROCESSING',
                    'claimed_by' => $workerId,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds(max(1, (int) config('daily_photos.ai_rescue.lease_seconds', 600))),
                    'attempts' => (int) $attempt->attempts + 1,
                    'error_message' => null,
                ]);

                return $attempt->fresh(['ocrJob.attachment.message']);
            }

            return null;
        }, 3);
    }

    public function complete(DailyPhotoAiRescueAttempt $attempt, array $data): DailyPhotoAiRescueAttempt
    {
        $resolvedJob = null;
        $completed = DB::transaction(function () use ($attempt, $data, &$resolvedJob): DailyPhotoAiRescueAttempt {
            $ocrJobId = (int) $attempt->ocr_job_id;
            $job = $this->lockedJob($ocrJobId);
            $attempt = DailyPhotoAiRescueAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($this->isIdempotentTerminalCallback($attempt, (int) $data['attempt'])) {
                return $attempt->fresh(['ocrJob.dailyPhotoCase']);
            }
            $this->ensureOwner($attempt, $data['worker_id'], (int) $data['attempt']);
            if (! hash_equals((string) $attempt->prompt_version, (string) $data['prompt_version'])
                || ! hash_equals((string) $attempt->schema_version, (string) $data['schema_version'])) {
                throw ValidationException::withMessages([
                    'prompt_version' => 'AI Rescue prompt/schema version does not match the claimed attempt.',
                ]);
            }

            $result = $data['result'];
            $classification = $result['classification'];
            $machineText = $result['machine'] ?? null;
            $captureDate = $result['capture_date'] ?? null;
            $captureTime = $result['capture_time'] ?? null;
            $ambiguities = array_values($result['ambiguities'] ?? []);
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            $attempt->update([
                'provider' => $data['provider'],
                'model' => $data['model'],
                'classification' => $classification,
                'extracted_machine' => $machineText,
                'extracted_date' => $captureDate,
                'extracted_time' => $captureTime ? $captureTime.':00' : null,
                'ambiguities' => $ambiguities ?: null,
                'structured_response' => $result,
                'raw_response' => $data['raw_response'] ?? null,
                'usage' => $usage ?: null,
                'prompt_tokens' => $this->usageInteger($usage, 'prompt_tokens'),
                'completion_tokens' => $this->usageInteger($usage, 'completion_tokens'),
                'total_tokens' => $this->usageInteger($usage, 'total_tokens'),
            ]);

            if ($reason = $this->ineligibilityReason($job, $attempt)) {
                $protected = str_starts_with($reason, 'PROTECTED') || $reason === 'CANONICAL';

                return $this->finish($attempt, 'SKIPPED', $protected ? 'SKIPPED_PROTECTED' : 'SKIPPED_STALE', [
                    'status' => 'SKIPPED',
                    'reasons' => [$reason],
                ]);
            }

            if ($classification === 'UNKNOWN') {
                return $this->finish($attempt, 'COMPLETED', 'HUMAN_REQUIRED', [
                    'status' => 'HUMAN_REQUIRED',
                    'reasons' => ['UNKNOWN_CLASSIFICATION'],
                ]);
            }

            if (in_array($classification, ['NON_DAILY_HOUR_METER', 'NON_DAILY_OTHER'], true)) {
                $documentType = $classification === 'NON_DAILY_HOUR_METER'
                    ? 'IGNORED_HOUR_METER'
                    : 'IGNORED_NON_DAILY_PHOTO';
                $metadata = $this->aiMetadata($job, $attempt, $classification);
                $job->update([
                    'document_type' => $documentType,
                    'status' => 'COMPLETED',
                    'review_status' => 'AUTO_APPROVED',
                    'machine_id' => null,
                    'asset_code' => null,
                    'observed_asset_code' => null,
                    'machine_resolution_method' => null,
                    'machine_resolution_metadata' => null,
                    'machine_resolved_at' => null,
                    'sender_driver_link_id' => null,
                    'machine_driver_history_id' => null,
                    'daily_photo_case_id' => null,
                    'extracted_date' => null,
                    'extracted_time' => null,
                    'shift' => null,
                    'exceptions' => null,
                    'daily_metadata' => $metadata,
                    'ocr_final_source' => 'AI_RESCUE',
                    'error_message' => null,
                    'processed_at' => now(),
                ]);

                return $this->finish($attempt, 'COMPLETED', 'NON_DAILY', [
                    'status' => 'NON_DAILY',
                    'reasons' => [],
                    'document_type' => $documentType,
                ]);
            }

            $validation = $this->validateDaily($machineText, $captureDate, $captureTime, $ambiguities);
            if ($validation['reasons'] !== []) {
                return $this->finish($attempt, 'COMPLETED', 'HUMAN_REQUIRED', $validation);
            }

            $machine = $validation['machine'];
            $metadata = $this->aiMetadata($job, $attempt, $classification);
            $job->update([
                'document_type' => 'DAILY_TIMEMARK',
                'status' => 'COMPLETED',
                'review_status' => 'AUTO_APPROVED',
                'machine_id' => $machine->id,
                'asset_code' => $machine->asset_code,
                'observed_asset_code' => $machineText,
                'machine_resolution_method' => 'AI_VISION',
                'machine_resolution_metadata' => [
                    'version' => config('daily_photos.foundation_version'),
                    'ai_rescue_attempt_id' => $attempt->id,
                    'prompt_version' => $attempt->prompt_version,
                    'schema_version' => $attempt->schema_version,
                    'observed_asset_code' => $machineText,
                    'observed_asset_normalized_key' => $validation['asset']['normalized_key'],
                    'image_asset_resolution_status' => $validation['asset']['status'],
                    'image_asset_resolved_machine_id' => $machine->id,
                    'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                    'capture_timezone' => config('daily_photos.capture_timezone'),
                ],
                'machine_resolved_at' => now(),
                'sender_driver_link_id' => null,
                'machine_driver_history_id' => null,
                'daily_photo_case_id' => null,
                'extracted_date' => $captureDate,
                'extracted_time' => $captureTime.':00',
                'shift' => $this->classifyShift($captureTime),
                'exceptions' => null,
                'daily_metadata' => $metadata,
                'ocr_final_source' => 'AI_RESCUE',
                'error_message' => null,
                'processed_at' => now(),
            ]);

            $resolvedJob = $job->fresh(['attachment.message', 'machine']);
            $this->dailyPhotoCases->materialize($resolvedJob);

            return $this->finish($attempt, 'COMPLETED', 'RESOLVED', $validation);
        }, 3);

        if ($resolvedJob) {
            $this->syncRelatedPeriods($resolvedJob);
        }

        return $completed;
    }

    public function fail(DailyPhotoAiRescueAttempt $attempt, array $data): DailyPhotoAiRescueAttempt
    {
        return DB::transaction(function () use ($attempt, $data): DailyPhotoAiRescueAttempt {
            $this->lockedJob((int) $attempt->ocr_job_id);
            $attempt = DailyPhotoAiRescueAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($this->isIdempotentFailureCallback($attempt, (int) $data['attempt'])) {
                return $attempt->fresh();
            }
            $this->ensureOwner($attempt, $data['worker_id'], (int) $data['attempt']);
            $retryable = (bool) $data['retryable']
                && (int) $attempt->attempts < max(1, (int) config('daily_photos.ai_rescue.max_attempts', 3));

            $attempt->update([
                'status' => $retryable ? 'RETRY' : 'FAILED',
                'active_key' => $retryable ? self::ACTIVE_KEY : null,
                'provider' => $data['provider'] ?? $attempt->provider,
                'model' => $data['model'] ?? $attempt->model,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'error_message' => $data['error'],
                'final_resolution' => $retryable ? null : 'FAILED',
                'processed_at' => $retryable ? null : now(),
            ]);

            return $attempt->fresh();
        }, 3);
    }

    public function ensureOwner(DailyPhotoAiRescueAttempt $attempt, string $workerId, int $workerAttempt): void
    {
        if ($attempt->status !== 'PROCESSING'
            || ! hash_equals((string) $attempt->claimed_by, $workerId)
            || ! $attempt->lease_expires_at
            || $attempt->lease_expires_at->isPast()
            || (int) $attempt->attempts !== $workerAttempt) {
            throw ValidationException::withMessages([
                'attempt' => 'This AI Rescue claim is stale or is not owned by the supplied worker.',
            ]);
        }
    }

    private function lockedJob(int $id): OcrJob
    {
        return OcrJob::query()
            ->with(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase'])
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function ineligibilityReason(
        OcrJob $job,
        ?DailyPhotoAiRescueAttempt $attempt = null,
        ?array $analysedRow = null,
    ): ?string {
        if ($job->machine_resolution_method === DailyPhotoMachineResolutionService::HUMAN
            || is_array(data_get($job->machine_resolution_metadata, 'human_resolution'))) {
            return 'PROTECTED_HUMAN';
        }
        if ($job->reviewed_at !== null || in_array($job->review_status, self::PROTECTED_REVIEW_STATUSES, true)) {
            return 'PROTECTED_REVIEWED';
        }
        if ($job->daily_photo_case_id || $job->dailyPhotoCaseEvidence) {
            return 'CANONICAL';
        }
        if ($job->document_type !== 'DAILY_TIMEMARK' || $job->status !== 'EXCEPTION') {
            return 'NOT_MANUAL';
        }
        if (! $this->hasOriginalImage($job)) {
            return 'SOURCE_MISSING';
        }
        if ($attempt && ((int) $attempt->zalo_attachment_id !== (int) $job->zalo_attachment_id
            || ! hash_equals((string) $attempt->source_sha256, (string) $job->attachment->sha256))) {
            return 'SOURCE_CHANGED';
        }

        $row = $analysedRow ?? $this->backlog->analyse(collect([$job]))->first();
        if (! $row || $row['protected']) {
            return 'PROTECTED';
        }
        if ($row['auto_recoverable']) {
            return 'NO_LONGER_MANUAL';
        }

        return null;
    }

    private function isIdempotentTerminalCallback(DailyPhotoAiRescueAttempt $attempt, int $workerAttempt): bool
    {
        return $attempt->processed_at !== null
            && $attempt->active_key === null
            && (int) $attempt->attempts === $workerAttempt
            && in_array($attempt->status, ['COMPLETED', 'FAILED', 'SKIPPED'], true);
    }

    private function isIdempotentFailureCallback(DailyPhotoAiRescueAttempt $attempt, int $workerAttempt): bool
    {
        return $this->isIdempotentTerminalCallback($attempt, $workerAttempt)
            || ($attempt->status === 'RETRY'
                && $attempt->active_key === self::ACTIVE_KEY
                && $attempt->claimed_by === null
                && (int) $attempt->attempts === $workerAttempt);
    }

    private function hasOriginalImage(OcrJob $job): bool
    {
        $attachment = $job->attachment;
        if (! $attachment
            || $attachment->status !== 'STORED'
            || ! str_starts_with((string) $attachment->mime_type, 'image/')
            || blank($attachment->storage_disk)
            || blank($attachment->storage_path)) {
            return false;
        }

        try {
            return Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
        } catch (Throwable) {
            return false;
        }
    }

    private function validateDaily(?string $machine, ?string $date, ?string $time, array $ambiguities): array
    {
        $reasons = [];
        if ($ambiguities !== []) {
            $reasons[] = 'AMBIGUOUS_RESULT';
        }
        if (blank($machine)) {
            $reasons[] = 'MACHINE_MISSING';
        }
        if (blank($date)) {
            $reasons[] = 'CAPTURE_DATE_MISSING';
        }
        if (blank($time)) {
            $reasons[] = 'CAPTURE_TIME_MISSING';
        }

        $asset = $this->assetCodes->resolve($machine);
        if (filled($machine) && $asset['status'] !== 'MATCHED') {
            $reasons[] = $asset['status'] === 'AMBIGUOUS' ? 'MACHINE_AMBIGUOUS' : 'MACHINE_INVALID';
        }

        $timezone = (string) config('daily_photos.capture_timezone', 'Asia/Ho_Chi_Minh');
        $today = CarbonImmutable::now($timezone)->toDateString();
        if ($date && $date > $today) {
            $reasons[] = 'CAPTURE_DATE_FUTURE';
        }

        return [
            'status' => $reasons === [] ? 'RESOLVED' : 'HUMAN_REQUIRED',
            'reasons' => array_values(array_unique($reasons)),
            'timezone' => $timezone,
            'evaluated_on' => $today,
            'asset_resolution_status' => $asset['status'],
            'asset' => $asset,
            'machine' => $asset['machine'],
        ];
    }

    private function aiMetadata(OcrJob $job, DailyPhotoAiRescueAttempt $attempt, string $classification): array
    {
        $metadata = $job->daily_metadata ?? [];
        $metadata['ai_rescue'] = [
            'latest_attempt_id' => $attempt->id,
            'classification' => $classification,
            'prompt_version' => $attempt->prompt_version,
            'schema_version' => $attempt->schema_version,
            'applied_at' => now()->toIso8601String(),
        ];

        return $metadata;
    }

    private function finish(
        DailyPhotoAiRescueAttempt $attempt,
        string $status,
        string $resolution,
        array $validation,
    ): DailyPhotoAiRescueAttempt {
        unset($validation['asset'], $validation['machine']);
        $attempt->update([
            'status' => $status,
            'active_key' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'validation_outcome' => $validation,
            'final_resolution' => $resolution,
            'error_message' => null,
            'processed_at' => now(),
        ]);

        return $attempt->fresh(['ocrJob.dailyPhotoCase']);
    }

    private function usageInteger(array $usage, string $key): ?int
    {
        $value = $usage[$key] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function classifyShift(string $time): string
    {
        $minutes = ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return match (true) {
            $minutes < 660 => 'MORNING',
            $minutes < 810 => 'MIDDAY',
            $minutes < 990 => 'AFTERNOON',
            $minutes <= 1050 => 'AFTERNOON_OT',
            default => 'EVENING_OT',
        };
    }

    private function syncRelatedPeriods(OcrJob $job): void
    {
        try {
            ReconciliationPeriod::query()
                ->whereIn('status', ['GENERATED', 'REVIEWING'])
                ->whereDate('date_from', '<=', $job->extracted_date)
                ->whereDate('date_to', '>=', $job->extracted_date->copy()->subDay())
                ->get()
                ->each(fn (ReconciliationPeriod $period) => $this->dailyPhotoSync->sync(
                    $period,
                    $job->machine_id,
                    $job->extracted_date->format('Y-m-d'),
                ));
        } catch (Throwable $exception) {
            Log::warning('AI Rescue completed but reconciliation sync must be retried.', [
                'ocr_job_id' => $job->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
