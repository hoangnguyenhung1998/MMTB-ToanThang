<?php

namespace App\Services;

use App\Models\JournalDocument;
use App\Models\Machine;
use App\Models\OcrJob;
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

    public function claim(string $workerId, array $documentTypes = []): ?OcrJob
    {
        return DB::transaction(function () use ($workerId, $documentTypes): ?OcrJob {
            $job = OcrJob::query()
                ->when(
                    $documentTypes !== [],
                    fn ($query) => $query->whereIn('document_type', $documentTypes),
                )
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

        if ($job->document_type === 'WEEKLY_JOURNAL') {
            throw ValidationException::withMessages([
                'document_type' => 'A weekly journal must use the journal completion endpoint.',
            ]);
        }

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
            'document_type' => 'DAILY_TIMEMARK',
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

    public function classify(OcrJob $job, array $data): OcrJob
    {
        $this->ensureClaimOwner($job, $data['worker_id']);

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
            'status' => $isUnknown ? 'EXCEPTION' : 'PENDING',
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'error_message' => null,
            'exceptions' => $isUnknown ? ['UNCLASSIFIED_DOCUMENT'] : null,
            'processed_at' => $isUnknown ? now() : null,
        ]);

        return $job->fresh();
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
        $this->ensureClaimOwner($job, $data['worker_id']);

        if ($job->document_type !== 'WEEKLY_JOURNAL') {
            throw ValidationException::withMessages([
                'document_type' => 'This OCR job is not classified as a weekly journal.',
            ]);
        }

        return DB::transaction(function () use ($job, $data): OcrJob {
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

            return $job->fresh(['attachment.message', 'machine', 'journalDocument.rows']);
        }, 3);
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
