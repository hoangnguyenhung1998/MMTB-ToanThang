<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\OcrJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OcrJobService
{
    public function enqueue(int $attachmentId): OcrJob
    {
        return OcrJob::query()->firstOrCreate(
            ['zalo_attachment_id' => $attachmentId],
            ['status' => 'PENDING'],
        );
    }

    public function claim(string $workerId): ?OcrJob
    {
        return DB::transaction(function () use ($workerId): ?OcrJob {
            $job = OcrJob::query()
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
                'lease_expires_at' => now()->addSeconds((int) config('ocr.lease_seconds')),
                'attempts' => $job->attempts + 1,
                'error_message' => null,
            ]);

            return $job->load('attachment.message');
        }, 3);
    }

    public function complete(OcrJob $job, array $data): OcrJob
    {
        $this->ensureClaimOwner($job, $data['worker_id']);

        $assetCode = isset($data['asset_code'])
            ? strtoupper(trim((string) $data['asset_code']))
            : null;
        $machine = $assetCode
            ? Machine::query()->where('asset_code', $assetCode)->first()
            : null;
        $shift = isset($data['time']) ? $this->classifyShift($data['time']) : null;
        $exceptions = $this->detectExceptions($job, $data, $assetCode, $machine, $shift);

        $job->update([
            'machine_id' => $machine?->id,
            'status' => $exceptions === [] ? 'COMPLETED' : 'EXCEPTION',
            'extracted_date' => $data['date'] ?? null,
            'extracted_time' => $data['time'] ?? null,
            'asset_code' => $assetCode,
            'operator_name' => $data['operator_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'work_location' => $data['work_location'] ?? null,
            'shift' => $shift,
            'confidence' => $data['confidence'],
            'raw_text' => $data['raw_text'] ?? null,
            'exceptions' => $exceptions === [] ? null : $exceptions,
            'error_message' => null,
            'processed_at' => now(),
            'lease_expires_at' => null,
        ]);

        return $job->fresh(['attachment.message', 'machine']);
    }

    public function fail(OcrJob $job, array $data): OcrJob
    {
        $this->ensureClaimOwner($job, $data['worker_id']);

        $job->update([
            'status' => $data['retryable'] ? 'RETRY' : 'FAILED',
            'error_message' => $data['error'],
            'lease_expires_at' => null,
            'processed_at' => $data['retryable'] ? null : now(),
        ]);

        return $job->fresh();
    }

    public function ensureClaimOwner(OcrJob $job, string $workerId): void
    {
        if (
            $job->status !== 'PROCESSING'
            || ! hash_equals((string) $job->claimed_by, $workerId)
            || $job->lease_expires_at?->isPast()
        ) {
            throw ValidationException::withMessages([
                'worker_id' => 'This OCR job is not claimed by the supplied worker.',
            ]);
        }
    }

    private function detectExceptions(
        OcrJob $job,
        array $data,
        ?string $assetCode,
        ?Machine $machine,
        ?string $shift,
    ): array {
        $exceptions = [];

        if ((float) $data['confidence'] < (float) config('ocr.minimum_confidence')) {
            $exceptions[] = 'LOW_CONFIDENCE';
        }
        if (empty($data['date'])) {
            $exceptions[] = 'MISSING_DATE';
        }
        if (empty($data['time'])) {
            $exceptions[] = 'MISSING_TIME';
        } elseif ($shift === null) {
            $exceptions[] = 'UNCLASSIFIED_TIME';
        }
        if ($assetCode === null || $assetCode === '') {
            $exceptions[] = 'MISSING_ASSET_CODE';
        } elseif (! $machine) {
            $exceptions[] = 'UNKNOWN_ASSET_CODE';
        }

        if (! empty($data['date'])) {
            $messageDate = CarbonImmutable::parse($job->attachment->message->sent_at)
                ->setTimezone((string) config('app.timezone'))
                ->toDateString();

            if ($data['date'] !== $messageDate) {
                $exceptions[] = 'WRONG_DATE';
            }
        }

        return $exceptions;
    }

    private function classifyShift(string $time): ?string
    {
        $minutes = ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return match (true) {
            $minutes >= 420 && $minutes < 660 => 'MORNING',
            $minutes >= 660 && $minutes < 810 => 'MIDDAY',
            $minutes >= 810 && $minutes < 990 => 'AFTERNOON',
            $minutes >= 990 && $minutes <= 1050 => 'AFTERNOON_OT',
            $minutes > 1050 => 'EVENING_OT',
            default => null,
        };
    }
}
