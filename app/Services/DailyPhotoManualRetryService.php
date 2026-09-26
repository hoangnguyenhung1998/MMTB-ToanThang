<?php

namespace App\Services;

use App\Models\OcrJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DailyPhotoManualRetryService
{
    private const CHUNK_SIZE = 200;

    private const VERSION = '16.10.9.1';

    private const PROTECTED_REVIEW_STATUSES = ['APPROVED', 'CORRECTED', 'REJECTED'];

    public function __construct(private readonly DailyPhotoBacklogService $backlog) {}

    public function preview(int $sampleLimit = 20, ?string $retryVersion = null): array
    {
        return $this->scan(false, max(0, min(100, $sampleLimit)), null, $this->normalizeRetryVersion($retryVersion));
    }

    /**
     * The callback is a concurrency test seam. The command never supplies it.
     */
    public function execute(int $sampleLimit = 20, ?callable $afterScan = null, ?string $retryVersion = null): array
    {
        return $this->scan(true, max(0, min(100, $sampleLimit)), $afterScan, $this->normalizeRetryVersion($retryVersion));
    }

    private function scan(bool $execute, int $sampleLimit, ?callable $afterScan = null, ?string $retryVersion = null): array
    {
        $summary = $this->emptySummary();
        $samples = collect();
        $afterScanCalled = false;

        $this->query()->chunkById(self::CHUNK_SIZE, function (Collection $jobs) use (
            $execute,
            $sampleLimit,
            $afterScan,
            $retryVersion,
            &$afterScanCalled,
            &$summary,
            $samples,
        ): void {
            $rows = $this->manualRows($jobs);

            foreach ($rows as $row) {
                $summary['total_manual_considered']++;
                $decision = $this->decision($row['job'], $row, $retryVersion);
                $this->recordDecision($summary, $decision);
                if ($samples->count() < $sampleLimit) {
                    $samples->push($this->sample($row, $decision));
                }
            }

            if (! $execute) {
                return;
            }

            $eligibleRows = $rows->filter(
                fn (array $row): bool => $this->decision($row['job'], $row, $retryVersion) === 'ELIGIBLE'
            )->values();
            if ($eligibleRows->isEmpty()) {
                return;
            }

            if (! $afterScanCalled && $afterScan) {
                $afterScanCalled = true;
                $afterScan($eligibleRows->pluck('job.id')->all());
            }

            DB::transaction(function () use ($eligibleRows, $retryVersion, &$summary): void {
                $locked = OcrJob::query()
                    ->with(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase'])
                    ->whereKey($eligibleRows->pluck('job.id')->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $currentRows = $this->manualRows($locked)->keyBy('job.id');

                foreach ($eligibleRows as $scannedRow) {
                    $current = $currentRows->get($scannedRow['job']->id);
                    if (! $current
                        || $this->fingerprint($current['job']) !== $scannedRow['state_fingerprint']
                        || $this->decision($current['job'], $current, $retryVersion) !== 'ELIGIBLE') {
                        $summary['eligible_reocr']--;
                        $summary['eligibility_changed']++;

                        continue;
                    }

                    $this->requeue($current['job'], $retryVersion);
                    $summary['requeued']++;
                }
            }, 3);
        });

        $summary['still_ineligible'] = $summary['total_manual_considered']
            - $summary['eligible_reocr']
            - $summary['eligibility_changed'];

        return [...$summary, 'samples' => $samples->all()];
    }

    private function query(): Builder
    {
        return OcrJob::query()
            ->with(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase'])
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('status', 'EXCEPTION')
            ->orderBy('id');
    }

    private function manualRows(Collection $jobs): Collection
    {
        if ($jobs->isEmpty()) {
            return collect();
        }

        return $this->backlog->analyse($jobs)
            ->filter(fn (array $row): bool => ! $row['auto_recoverable'])
            ->map(function (array $row): array {
                $row['state_fingerprint'] = $this->fingerprint($row['job']);

                return $row;
            })
            ->values();
    }

    private function decision(OcrJob $job, array $row, ?string $retryVersion = null): string
    {
        if ($this->isHumanCorrected($job)) {
            return 'HUMAN_CORRECTED';
        }
        if ($job->reviewed_at !== null || in_array($job->review_status, self::PROTECTED_REVIEW_STATUSES, true)) {
            return 'REVIEWED_CONFIRMED';
        }
        if ($row['protected']) {
            return 'PROTECTED';
        }
        if ($job->document_type === 'IGNORED_HOUR_METER') {
            return 'IGNORED_HOUR_METER';
        }
        if ($job->document_type === 'IGNORED_NON_DAILY_PHOTO') {
            return 'IGNORED_NON_DAILY';
        }
        if (in_array($job->status, ['PENDING', 'RETRY', 'QUEUED'], true)) {
            return filled($job->ocr_retry_reason) ? 'TARGETED_RETRY' : 'PENDING_QUEUED';
        }
        if ($job->status === 'PROCESSING' || $this->hasActiveLease($job)) {
            return 'ACTIVE_PROCESSING';
        }
        if ($job->daily_photo_case_id || $job->dailyPhotoCaseEvidence) {
            return 'ALREADY_RESOLVED';
        }
        if ($retryVersion !== null && $this->hasVersionAttempt($job, $retryVersion)) {
            return 'ALREADY_ATTEMPTED_FOR_VERSION';
        }
        if ($retryVersion === null && data_get($job->daily_metadata, 'manual_reocr.attempted_at')) {
            return 'ALREADY_ATTEMPTED';
        }
        if ($job->status !== 'EXCEPTION' || $job->document_type !== 'DAILY_TIMEMARK') {
            return 'OTHER_INELIGIBLE';
        }
        if (! $this->hasSourceImage($job)) {
            return 'MISSING_SOURCE';
        }

        return 'ELIGIBLE';
    }

    private function isHumanCorrected(OcrJob $job): bool
    {
        return $job->machine_resolution_method === DailyPhotoMachineResolutionService::HUMAN
            || $job->review_status === 'CORRECTED'
            || is_array(data_get($job->machine_resolution_metadata, 'human_resolution'));
    }

    private function hasActiveLease(OcrJob $job): bool
    {
        return $job->lease_expires_at !== null && $job->lease_expires_at->isFuture();
    }

    private function hasSourceImage(OcrJob $job): bool
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

    private function requeue(OcrJob $job, ?string $retryVersion = null): void
    {
        $metadata = $job->daily_metadata ?? [];
        $requestedAt = now()->toIso8601String();
        if ($retryVersion === null) {
            data_set($metadata, 'manual_reocr', [
                'version' => self::VERSION,
                'attempted_at' => $requestedAt,
                'claimable' => true,
                'requested_pipeline' => 'PHASE_16_10_9_OR_LATER',
                'previous_state' => $this->historicalSnapshot($job),
            ]);
        } else {
            $manualReocr = data_get($metadata, 'manual_reocr', []);
            $manualReocr = is_array($manualReocr) ? $manualReocr : [];
            $versionAttempts = $manualReocr['version_attempts'] ?? [];
            $versionAttempts = is_array($versionAttempts) ? $versionAttempts : [];

            // The version is a direct array key, never a data_set path. Validation also rejects path syntax.
            $versionAttempts[$retryVersion] = [
                'requested_at' => $requestedAt,
                'requested_pipeline' => $retryVersion,
                'claimable' => true,
                'previous_state' => $this->historicalSnapshot($job),
            ];
            $manualReocr['version_attempts'] = $versionAttempts;
            $manualReocr['active_version'] = $retryVersion;
            $manualReocr['claimable'] = true;
            $manualReocr['attempted_at'] ??= $requestedAt;
            $manualReocr['version'] ??= self::VERSION;
            $metadata['manual_reocr'] = $manualReocr;
        }

        $job->update([
            'status' => 'RETRY',
            'review_status' => 'PENDING',
            'ocr_retry_reason' => null,
            // Prevent the completion endpoint from scheduling a second targeted OCR.
            'ocr_retry_attempts' => max(1, (int) $job->ocr_retry_attempts),
            'ocr_final_source' => null,
            'daily_metadata' => $metadata,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'processed_at' => null,
            'error_message' => null,
        ]);
    }

    private function historicalSnapshot(OcrJob $job): array
    {
        return [
            'status' => $job->status,
            'review_status' => $job->review_status,
            'machine_id' => $job->machine_id,
            'asset_code' => $job->asset_code,
            'observed_asset_code' => $job->observed_asset_code,
            'machine_resolution_method' => $job->machine_resolution_method,
            'extracted_date' => $job->extracted_date?->format('Y-m-d'),
            'extracted_time' => $job->extracted_time,
            'confidence' => $job->confidence,
            'raw_text' => $job->raw_text,
            'exceptions' => $job->exceptions,
            'attempts' => $job->attempts,
            'ocr_retry_attempts' => $job->ocr_retry_attempts,
            'ocr_retry_reason' => $job->ocr_retry_reason,
            'ocr_final_source' => $job->ocr_final_source,
            'ocr_initial_extraction' => $job->ocr_initial_extraction,
            'daily_metadata' => $job->daily_metadata,
        ];
    }

    private function fingerprint(OcrJob $job): string
    {
        return hash('sha256', json_encode([
            $job->document_type,
            $job->status,
            $job->review_status,
            $job->reviewed_at?->toIso8601String(),
            $job->machine_id,
            $job->machine_resolution_method,
            $job->daily_photo_case_id,
            $job->claimed_by,
            $job->lease_expires_at?->toIso8601String(),
            $job->ocr_retry_reason,
            $job->ocr_retry_attempts,
            data_get($job->daily_metadata, 'manual_reocr.attempted_at'),
            $job->updated_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function recordDecision(array &$summary, string $decision): void
    {
        $metric = match ($decision) {
            'ELIGIBLE' => 'eligible_reocr',
            'HUMAN_CORRECTED' => 'human_corrected_skipped',
            'REVIEWED_CONFIRMED' => 'reviewed_confirmed_skipped',
            'PROTECTED' => 'protected_only_skipped',
            'ALREADY_RESOLVED' => 'already_resolved_skipped',
            'ALREADY_ATTEMPTED' => 'already_reocr_attempted_skipped',
            'ALREADY_ATTEMPTED_FOR_VERSION' => 'already_attempted_for_version',
            'PENDING_QUEUED', 'TARGETED_RETRY' => 'pending_queued_skipped',
            'ACTIVE_PROCESSING' => 'active_processing_skipped',
            'IGNORED_HOUR_METER' => 'ignored_hour_meter_skipped',
            'IGNORED_NON_DAILY' => 'ignored_non_daily_skipped',
            'MISSING_SOURCE' => 'missing_source_image',
            default => 'other_ineligible',
        };
        $summary[$metric]++;
        if (in_array($decision, ['HUMAN_CORRECTED', 'REVIEWED_CONFIRMED', 'PROTECTED'], true)) {
            $summary['protected_skipped']++;
        }
    }

    private function sample(array $row, string $decision): array
    {
        $job = $row['job'];

        return [
            'job_id' => $job->id,
            'machine' => $job->machine?->asset_code ?? $job->asset_code,
            'date' => $job->extracted_date?->format('Y-m-d'),
            'time' => $job->extracted_time,
            'status' => $job->status,
            'manual_state' => $row['diagnostic_subtype'].'; '.implode(',', $row['reasons']),
            'eligible' => $decision === 'ELIGIBLE' ? 'YES' : 'NO',
            'skip_reason' => $decision === 'ELIGIBLE' ? '-' : $decision,
            'source_image' => $this->hasSourceImage($job) ? 'YES' : 'NO',
            'previously_attempted' => data_get($job->daily_metadata, 'manual_reocr.attempted_at') ? 'YES' : 'NO',
        ];
    }

    private function emptySummary(): array
    {
        return array_fill_keys([
            'total_manual_considered',
            'eligible_reocr',
            'requeued',
            'protected_skipped',
            'protected_only_skipped',
            'human_corrected_skipped',
            'reviewed_confirmed_skipped',
            'already_resolved_skipped',
            'already_reocr_attempted_skipped',
            'already_attempted_for_version',
            'pending_queued_skipped',
            'active_processing_skipped',
            'ignored_hour_meter_skipped',
            'ignored_non_daily_skipped',
            'missing_source_image',
            'other_ineligible',
            'eligibility_changed',
            'still_ineligible',
        ], 0);
    }

    private function hasVersionAttempt(OcrJob $job, string $retryVersion): bool
    {
        $attempts = data_get($job->daily_metadata, 'manual_reocr.version_attempts', []);

        return is_array($attempts) && array_key_exists($retryVersion, $attempts);
    }

    private function normalizeRetryVersion(?string $retryVersion): ?string
    {
        if ($retryVersion === null) {
            return null;
        }

        $retryVersion = trim($retryVersion);
        if (strlen($retryVersion) > 32 || preg_match('/\A\d+(?:\.\d+){1,3}\z/D', $retryVersion) !== 1) {
            throw new \InvalidArgumentException('Giá trị --retry-version không hợp lệ; dùng dạng số như 16.10.10.');
        }

        return $retryVersion;
    }
}
