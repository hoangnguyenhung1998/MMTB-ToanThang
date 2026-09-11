<?php

namespace App\Services;

use App\Models\JournalDocument;
use App\Models\Machine;
use App\Models\OcrJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OcrJobService
{
    public function __construct(
        private readonly OcrProcessingRunService $processingRuns,
        private readonly DailyPhotoMachineResolutionService $machineResolver,
        private readonly DailyPhotoCaseService $dailyPhotoCases,
    ) {}

    public function enqueue(int $attachmentId): OcrJob
    {
        return OcrJob::query()->firstOrCreate(
            ['zalo_attachment_id' => $attachmentId],
            ['status' => 'PENDING'],
        );
    }

    public function claim(string $workerId, array $documentTypes = []): ?OcrJob
    {
        return DB::transaction(function () use ($workerId, $documentTypes): ?OcrJob {
            $maxAttempts = max(1, (int) config('ocr.max_attempts'));
            $this->materializeExpiredLeases($maxAttempts);

            $job = OcrJob::query()
                ->when(config('daily_photos.enabled'), fn ($query) => $query->whereIn('document_type', ['UNKNOWN', 'DAILY_TIMEMARK']))
                ->when(
                    $documentTypes !== [],
                    fn ($query) => $query->whereIn('document_type', $documentTypes),
                )
                ->where('attempts', '<', $maxAttempts)
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

            if (! $job) {
                return null;
            }

            $job->update([
                'status' => 'PROCESSING',
                'claimed_by' => $workerId,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds(max(1, (int) config('ocr.lease_seconds'))),
                'attempts' => $job->attempts + 1,
                'error_message' => null,
            ]);

            $this->processingRuns->start($job, $workerId);

            return $job->load('attachment.message');
        }, 3);
    }

    public function renew(OcrJob $job, array $data): OcrJob
    {
        return DB::transaction(function () use ($job, $data): OcrJob {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            $this->ensureClaimOwner($job, $data['worker_id'], (int) $data['attempt']);
            $job->update([
                'lease_expires_at' => now()->addSeconds(max(1, (int) config('ocr.lease_seconds'))),
            ]);

            return $job->fresh();
        }, 3);
    }

    public function expireLeases(): int
    {
        return DB::transaction(
            fn (): int => $this->materializeExpiredLeases(max(1, (int) config('ocr.max_attempts'))),
            3,
        );
    }

    public function complete(OcrJob $job, array $data): OcrJob
    {
        $completed = DB::transaction(function () use ($job, $data): OcrJob {
            $job = OcrJob::query()->with('attachment.message')->lockForUpdate()->findOrFail($job->id);
            $attempt = (int) ($data['attempt'] ?? $job->attempts);
            $this->ensureClaimOwner($job, $data['worker_id'], isset($data['attempt']) ? (int) $data['attempt'] : null);

            if ($job->document_type === 'WEEKLY_JOURNAL') {
                throw ValidationException::withMessages([
                    'document_type' => 'A weekly journal must use the journal completion endpoint.',
                ]);
            }

            $isTargetedRetry = (int) $job->ocr_retry_attempts > 0 && filled($job->ocr_retry_reason);
            $retryExtraction = $isTargetedRetry ? $this->extractionSnapshot($data) : null;
            if ($isTargetedRetry) {
                $data = $this->mergeTargetedRetryResult($job, $data);
            }

            $observedAssetCode = isset($data['asset_code'])
                ? strtoupper(trim((string) $data['asset_code']))
                : null;
            $observedAssetCode = $observedAssetCode === '' ? null : $observedAssetCode;
            $resolution = config('daily_photos.enabled')
                ? $this->machineResolver->resolve(
                    $job,
                    $observedAssetCode,
                    $data['date'] ?? null,
                    $data['time'] ?? null,
                    true,
                )
                : ($legacyAsset = app(AssetCodeResolver::class)->resolve($observedAssetCode)) + [
                    'observed_asset_code' => $observedAssetCode,
                    'legacy_asset_code' => $observedAssetCode,
                    'asset_resolution_status' => $legacyAsset['status'],
                    'image_machine' => $legacyAsset['machine'],
                    'machine' => $legacyAsset['machine'],
                    'method' => $legacyAsset['machine'] ? DailyPhotoMachineResolutionService::IMAGE_ASSET : null,
                    'sender_driver_link_id' => null,
                    'machine_driver_history_id' => null,
                    'metadata' => [
                        'version' => config('daily_photos.foundation_version'),
                        'image_asset_resolved_machine_id' => $legacyAsset['machine']?->id,
                    ],
                ];
            $machine = $resolution['machine'];
            $shift = isset($data['time']) ? $this->classifyShift($data['time']) : null;
            $exceptions = $this->detectExceptions($data, $resolution, $shift);
            $retryFocus = $this->retryFocus($job, $data, $resolution, $exceptions);
            $willRetry = config('daily_photos.enabled') && ! $isTargetedRetry && $retryFocus !== [];
            if ($isTargetedRetry && $exceptions !== []) {
                $exceptions[] = 'OCR_RETRY_FAILED';
                $exceptions = array_values(array_unique($exceptions));
            }
            $finalSource = match (true) {
                $willRetry => null,
                $resolution['method'] === DailyPhotoMachineResolutionService::HUMAN => 'MANUAL',
                $resolution['method'] === DailyPhotoMachineResolutionService::SENDER_MAPPING => 'SENDER_MAPPING',
                $isTargetedRetry => 'OCR_RETRY',
                default => 'OCR_INITIAL',
            };
            $metadata = [
                'machine_source' => match ($resolution['method']) {
                    DailyPhotoMachineResolutionService::IMAGE_ASSET => 'IMAGE',
                    DailyPhotoMachineResolutionService::SENDER_DRIVER_HISTORY => 'SENDER_ASSIGNMENT',
                    DailyPhotoMachineResolutionService::SENDER_MAPPING => 'SENDER_MAPPING',
                    default => 'UNRESOLVED',
                },
                'image_fingerprint' => $data['image_fingerprint'] ?? null,
                'ocr_recovery' => [
                    'initial_extraction' => $job->ocr_initial_extraction ?? $this->extractionSnapshot($data),
                    'retry_reason' => $willRetry ? implode(',', $retryFocus) : $job->ocr_retry_reason,
                    'retry_attempt' => $willRetry || $isTargetedRetry ? 1 : 0,
                    'retry_extraction' => $retryExtraction,
                    'final_chosen_result' => $willRetry ? null : $this->extractionSnapshot($data),
                    'source' => $finalSource,
                ],
            ];
            if (config('daily_photos.enabled') && $machine && ! empty($data['date']) && ! empty($metadata['image_fingerprint'])) {
                $metadata['near_duplicate_ids'] = OcrJob::query()->where('machine_id', $machine->id)->whereDate('extracted_date', $data['date'])
                    ->whereKeyNot($job->id)->whereNotNull('daily_metadata')->get()->filter(function ($other) use ($metadata) {
                        $hash = data_get($other->daily_metadata, 'image_fingerprint');
                        if (! $hash || strlen($hash) !== 16) {
                            return false;
                        }
                        $distance = 0;
                        for ($i = 0; $i < 16; $i++) {
                            $distance += substr_count(decbin(hexdec($hash[$i]) ^ hexdec($metadata['image_fingerprint'][$i])), '1');
                        }

                        return $distance <= 4;
                    })->pluck('id')->all();
            }

            $job->update([
                'daily_metadata' => $metadata,
                'machine_id' => $machine?->id,
                'document_type' => 'DAILY_TIMEMARK',
                'status' => $willRetry ? 'RETRY' : ($exceptions === [] ? 'COMPLETED' : 'EXCEPTION'),
                'extracted_date' => $data['date'] ?? null,
                'extracted_time' => $data['time'] ?? null,
                'asset_code' => $resolution['legacy_asset_code'],
                'observed_asset_code' => $resolution['observed_asset_code'],
                'machine_resolution_method' => $resolution['method'],
                'machine_resolution_metadata' => $resolution['metadata'],
                'sender_driver_link_id' => $resolution['sender_driver_link_id'],
                'machine_driver_history_id' => $resolution['machine_driver_history_id'],
                'machine_resolved_at' => $machine ? now() : null,
                'daily_photo_case_id' => null,
                'operator_name' => $data['operator_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'work_location' => $data['work_location'] ?? null,
                'shift' => $shift,
                'confidence' => $data['confidence'],
                'raw_text' => $data['raw_text'] ?? null,
                'exceptions' => $exceptions === [] ? null : $exceptions,
                'ocr_initial_extraction' => $job->ocr_initial_extraction ?? $this->extractionSnapshot($data),
                'ocr_retry_reason' => $willRetry ? implode(',', $retryFocus) : $job->ocr_retry_reason,
                'ocr_retry_attempts' => $willRetry ? 1 : (int) $job->ocr_retry_attempts,
                'ocr_final_source' => $finalSource,
                'error_message' => null,
                'processed_at' => $willRetry ? null : now(),
                'lease_expires_at' => null,
            ]);

            $this->processingRuns->finish($job, $data['worker_id'], $attempt, 'COMPLETED');

            $completed = $job->fresh(['attachment.message', 'machine']);
            if (config('daily_photos.enabled') && ! $willRetry) {
                $this->dailyPhotoCases->materialize($completed);
            }

            return $job->fresh(['attachment.message', 'machine', 'dailyPhotoCase']);
        }, 3);

        if (config('daily_photos.enabled') && $completed->status === 'COMPLETED' && $completed->machine_id && $completed->extracted_date) {
            try {
                \App\Models\ReconciliationPeriod::query()->whereIn('status', ['GENERATED', 'REVIEWING'])
                    ->whereDate('date_from', '<=', $completed->extracted_date)->whereDate('date_to', '>=', $completed->extracted_date->copy()->subDay())->get()
                    ->each(fn ($period) => app(\App\Services\Reconciliation\DailyPhotoSyncService::class)->sync($period, $completed->machine_id, $completed->extracted_date->format('Y-m-d')));
            } catch (\Throwable $exception) {
                // OCR completion is durable. Scheduled/manual sync can retry independently.
                report($exception);
            }
        }

        return $completed;
    }

    public function classify(OcrJob $job, array $data): OcrJob
    {
        return DB::transaction(function () use ($job, $data): OcrJob {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            $attempt = (int) ($data['attempt'] ?? $job->attempts);
            $this->ensureClaimOwner($job, $data['worker_id'], isset($data['attempt']) ? (int) $data['attempt'] : null);

            if ($job->document_type !== 'UNKNOWN') {
                throw ValidationException::withMessages([
                    'document_type' => 'This OCR job has already been classified.',
                ]);
            }

            $isUnknown = $data['document_type'] === 'UNKNOWN';
            $job->update([
                'document_type' => $data['document_type'],
                'classification_confidence' => $data['confidence'],
                'classified_by' => $data['worker_id'],
                'classified_at' => now(),
                'status' => config('daily_photos.enabled') && $data['document_type'] === 'WEEKLY_JOURNAL' ? 'PAUSED' : ($isUnknown ? 'EXCEPTION' : 'PENDING'),
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'error_message' => null,
                'exceptions' => $isUnknown ? ['UNCLASSIFIED_DOCUMENT'] : null,
                'processed_at' => $isUnknown ? now() : null,
            ]);

            $this->processingRuns->finish($job, $data['worker_id'], $attempt, 'COMPLETED');

            return $job->fresh();
        }, 3);
    }

    public function machineCatalog(): array
    {
        return Machine::query()
            ->select(['id', 'asset_code', 'status'])
            ->orderBy('asset_code')
            ->get()
            ->map(fn (Machine $machine): array => [
                'id' => $machine->id,
                'asset_code' => $machine->asset_code,
                'status' => $machine->status,
            ])
            ->all();
    }

    public function completeJournal(OcrJob $job, array $data): OcrJob
    {
        abort_if(config('daily_photos.enabled'), 409, 'OCR nhật trình tuần đã tạm dừng.');

        return DB::transaction(function () use ($job, $data): OcrJob {
            $job = OcrJob::query()->with('attachment.message')->lockForUpdate()->findOrFail($job->id);
            $attempt = (int) ($data['attempt'] ?? $job->attempts);
            $this->ensureClaimOwner($job, $data['worker_id'], isset($data['attempt']) ? (int) $data['attempt'] : null);

            if ($job->document_type !== 'WEEKLY_JOURNAL') {
                throw ValidationException::withMessages([
                    'document_type' => 'This OCR job is not classified as a weekly journal.',
                ]);
            }

            $assetCode = isset($data['asset_code'])
                ? strtoupper(trim((string) $data['asset_code']))
                : null;
            $machine = $assetCode
                ? Machine::query()->where('asset_code', $assetCode)->first()
                : null;
            $documentExceptions = $this->detectJournalDocumentExceptions($data, $assetCode, $machine);
            $hasRowExceptions = false;

            $document = JournalDocument::query()->create([
                'ocr_job_id' => $job->id,
                'machine_id' => $machine?->id,
                'asset_code' => $assetCode,
                'confidence' => $data['confidence'],
                'raw_text' => $data['raw_text'] ?? null,
                'exceptions' => $documentExceptions === [] ? null : $documentExceptions,
            ]);

            foreach ($data['rows'] as $rowData) {
                $rowExceptions = $this->detectJournalRowExceptions($rowData);
                $hasRowExceptions = $hasRowExceptions || $rowExceptions !== [];

                $document->rows()->create([
                    ...$rowData,
                    'exceptions' => $rowExceptions === [] ? null : $rowExceptions,
                ]);
            }

            $exceptions = $documentExceptions;
            if ($hasRowExceptions) {
                $exceptions[] = 'JOURNAL_ROW_EXCEPTION';
            }

            $job->update([
                'machine_id' => $machine?->id,
                'asset_code' => $assetCode,
                'status' => $exceptions === [] ? 'COMPLETED' : 'EXCEPTION',
                'confidence' => $data['confidence'],
                'raw_text' => $data['raw_text'] ?? null,
                'exceptions' => $exceptions === [] ? null : array_values(array_unique($exceptions)),
                'error_message' => null,
                'processed_at' => now(),
                'lease_expires_at' => null,
            ]);

            $this->processingRuns->finish($job, $data['worker_id'], $attempt, 'COMPLETED');

            return $job->fresh(['attachment.message', 'machine', 'journalDocument.rows']);
        }, 3);
    }

    public function fail(OcrJob $job, array $data): OcrJob
    {
        return DB::transaction(function () use ($job, $data): OcrJob {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            $attempt = (int) ($data['attempt'] ?? $job->attempts);
            $this->ensureClaimOwner($job, $data['worker_id'], isset($data['attempt']) ? (int) $data['attempt'] : null);
            $retryable = (bool) $data['retryable'] && $job->attempts < max(1, (int) config('ocr.max_attempts'));

            $job->update([
                'status' => $retryable ? 'RETRY' : 'FAILED',
                'error_message' => $data['error'],
                'lease_expires_at' => null,
                'processed_at' => $retryable ? null : now(),
            ]);

            $this->processingRuns->finish($job, $data['worker_id'], $attempt, 'FAILED', $data['error']);

            return $job->fresh();
        }, 3);
    }

    public function ensureClaimOwner(OcrJob $job, string $workerId, ?int $attempt = null): void
    {
        if (
            $job->status !== 'PROCESSING'
            || ! hash_equals((string) $job->claimed_by, $workerId)
            || ! $job->lease_expires_at
            || $job->lease_expires_at->isPast()
            || ($attempt !== null && $attempt !== (int) $job->attempts)
            || ($attempt === null && (bool) config('ocr.enforce_attempt_fencing'))
        ) {
            Log::warning('OCR claim operation rejected.', [
                'job_id' => $job->id,
                'worker_id' => $workerId,
                'attempt' => $attempt,
                'current_worker_id' => $job->claimed_by,
                'current_attempt' => $job->attempts,
                'job_status' => $job->status,
                'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
                'operation' => request()?->path(),
            ]);
            throw ValidationException::withMessages([
                'attempt' => 'This OCR job claim is stale or is not owned by the supplied worker.',
            ]);
        }
    }

    private function materializeExpiredLeases(int $maxAttempts): int
    {
        $jobs = OcrJob::query()
            ->where('status', 'PROCESSING')
            ->where('lease_expires_at', '<=', now())
            ->lockForUpdate()
            ->limit(100)
            ->get();

        $jobs->each(function (OcrJob $job) use ($maxAttempts): void {
            $terminal = $job->attempts >= $maxAttempts;
            $error = $terminal
                ? "Worker lease expired after {$maxAttempts} OCR attempts."
                : 'Worker lease expired before completion.';
            $this->processingRuns->timeoutExpired($job, $error);
            $job->update([
                'status' => $terminal ? 'FAILED' : 'RETRY',
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'error_message' => $error,
                'processed_at' => $terminal ? now() : null,
            ]);
            Log::log($terminal ? 'error' : 'warning', 'OCR job lease expiry materialized.', [
                'job_id' => $job->id,
                'attempt' => $job->attempts,
                'max_attempts' => $maxAttempts,
                'terminal' => $terminal,
            ]);
        });

        $exhausted = OcrJob::query()
            ->whereIn('status', ['PENDING', 'RETRY'])
            ->where('attempts', '>=', $maxAttempts)
            ->lockForUpdate()
            ->limit(100)
            ->get();

        $exhausted->each(function (OcrJob $job) use ($maxAttempts): void {
            $error = "Maximum of {$maxAttempts} OCR attempts reached.";
            $job->update([
                'status' => 'FAILED',
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'error_message' => $error,
                'processed_at' => now(),
            ]);
            Log::error('Exhausted OCR job moved to terminal failure.', [
                'job_id' => $job->id,
                'attempt' => $job->attempts,
                'max_attempts' => $maxAttempts,
            ]);
        });

        return $jobs->count() + $exhausted->count();
    }

    private function detectExceptions(array $data, array $resolution, ?string $shift): array
    {
        $exceptions = [];

        if (empty($data['date'])) {
            $exceptions[] = 'CAPTURE_DATE_MISSING';
        }
        if (empty($data['time'])) {
            $exceptions[] = 'CAPTURE_TIME_MISSING';
        } elseif ($shift === null) {
            $exceptions[] = 'CAPTURE_TIME_MISSING';
        }
        if (! $resolution['machine']) {
            $exceptions[] = match ($resolution['asset_resolution_status'] ?? null) {
                'AMBIGUOUS' => 'MACHINE_AMBIGUOUS',
                'NOT_FOUND' => 'MACHINE_OCR_INVALID',
                default => data_get($resolution, 'metadata.sender_resolution_status') === 'AMBIGUOUS_MAPPING'
                    ? 'MACHINE_AMBIGUOUS'
                    : 'SENDER_MAPPING_MISSING',
            };
        }

        return array_values(array_unique($exceptions));
    }

    private function retryFocus(OcrJob $job, array $data, array $resolution, array $exceptions): array
    {
        if ((int) $job->ocr_retry_attempts >= 1
            || (int) $job->attempts >= max(1, (int) config('ocr.max_attempts'))) {
            return [];
        }

        if (! $resolution['machine'] && in_array($resolution['asset_resolution_status'] ?? null, ['MISSING', 'NOT_FOUND'], true)) {
            return ['machine'];
        }

        return collect([
            empty($data['date']) ? 'date' : null,
            empty($data['time']) ? 'time' : null,
        ])->filter()->values()->all();
    }

    private function mergeTargetedRetryResult(OcrJob $job, array $data): array
    {
        $initial = $job->ocr_initial_extraction ?? [];
        $focus = collect(explode(',', (string) $job->ocr_retry_reason));
        $targetFields = collect([
            'machine' => 'asset_code',
            'date' => 'date',
            'time' => 'time',
        ])->only($focus->all())->values()->all();
        foreach (['date', 'time', 'asset_code', 'operator_name', 'phone', 'work_location', 'raw_text', 'image_fingerprint'] as $field) {
            if ((! in_array($field, $targetFields, true) || blank($data[$field] ?? null)) && filled($initial[$field] ?? null)) {
                $data[$field] = $initial[$field];
            }
        }

        $data['confidence'] = min((float) ($data['confidence'] ?? 0), (float) ($initial['confidence'] ?? 0));

        return $data;
    }

    private function extractionSnapshot(array $data): array
    {
        return collect($data)->only([
            'date', 'time', 'asset_code', 'operator_name', 'phone', 'work_location',
            'confidence', 'raw_text', 'image_fingerprint',
        ])->all();
    }

    private function classifyShift(string $time): ?string
    {
        $minutes = ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return match (true) {
            $minutes >= (config('daily_photos.enabled') ? 0 : 420) && $minutes < 660 => 'MORNING',
            $minutes >= 660 && $minutes < 810 => 'MIDDAY',
            $minutes >= 810 && $minutes < 990 => 'AFTERNOON',
            $minutes >= 990 && $minutes <= 1050 => 'AFTERNOON_OT',
            $minutes > 1050 => 'EVENING_OT',
            default => null,
        };
    }

    private function detectJournalDocumentExceptions(
        array $data,
        ?string $assetCode,
        ?Machine $machine,
    ): array {
        $exceptions = [];

        if ((float) $data['confidence'] < (float) config('ocr.minimum_confidence')) {
            $exceptions[] = 'LOW_CONFIDENCE';
        }
        if ($assetCode === null || $assetCode === '') {
            $exceptions[] = 'MISSING_ASSET_CODE';
        } elseif (! $machine) {
            $exceptions[] = 'UNKNOWN_ASSET_CODE';
        }

        return $exceptions;
    }

    private function detectJournalRowExceptions(array $row): array
    {
        $exceptions = [];

        if ((float) $row['confidence'] < (float) config('ocr.minimum_confidence')) {
            $exceptions[] = 'LOW_CONFIDENCE';
        }
        if (empty($row['work_date'])) {
            $exceptions[] = 'MISSING_DATE';
        }
        if (empty($row['work_content'])) {
            $exceptions[] = 'MISSING_WORK_CONTENT';
        }

        $normalizationFlags = $row['raw_data']['normalization_flags'] ?? [];
        if (in_array('MISSING_DATE', $normalizationFlags, true)) {
            $exceptions[] = 'MISSING_DATE';
        }
        if (in_array('NEW_JOB', $normalizationFlags, true)) {
            $exceptions[] = 'NEW_JOB';
        }

        return array_values(array_unique($exceptions));
    }
}
