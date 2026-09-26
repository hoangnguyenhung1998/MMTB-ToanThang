<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\OcrRegressionCase;
use App\Models\User;

class OcrRegressionCaseService
{
    public function snapshot(OcrJob $job): array
    {
        $job->loadMissing(['attachment.message', 'machine']);
        $mappingMachineId = data_get($job->machine_resolution_metadata, 'sender_resolution_machine_id');

        return [
            'raw_text' => $job->raw_text,
            'observed_asset_code' => $job->observed_asset_code,
            'asset_code' => $job->asset_code,
            'extracted_date' => $job->extracted_date?->format('Y-m-d'),
            'extracted_time' => $job->extracted_time,
            'ocr_initial_extraction' => $job->ocr_initial_extraction,
            'daily_metadata' => $job->daily_metadata,
            'candidate_metadata' => data_get($job->daily_metadata, 'ocr_candidate_summary'),
            'received_at' => $job->attachment?->message?->received_at?->toIso8601String(),
            'mapping_asset_code' => $mappingMachineId ? Machine::query()->find($mappingMachineId)?->asset_code : null,
            'source_status' => $job->status,
            'source_review_status' => $job->review_status,
            'source_machine_resolution_method' => $job->machine_resolution_method,
            'source_machine_asset_code' => $job->machine?->asset_code,
            'source_exceptions' => $job->exceptions,
        ];
    }

    public function captureVerified(
        OcrJob $job,
        array $inputSnapshot,
        array $data,
        User $user,
    ): OcrRegressionCase {
        $category = (string) ($data['case_category'] ?? 'OTHER');
        $disposition = (string) ($data['expected_disposition'] ?? 'DAILY_TIMEMARK');
        $isDaily = $disposition === 'DAILY_TIMEMARK';

        $case = OcrRegressionCase::query()->firstOrNew([
            'source_ocr_job_id' => $job->id,
            'case_category' => $category,
        ]);
        if (! $case->exists) {
            $case->fill([
                'source_attachment_id' => $job->zalo_attachment_id,
                'document_type' => 'DAILY_TIMEMARK',
                'input_snapshot' => $inputSnapshot,
                'source_metadata' => [
                    'attachment_disk' => $job->attachment?->storage_disk,
                    'attachment_path' => $job->attachment?->storage_path,
                    'attachment_sha256' => $job->attachment?->sha256,
                    'candidate_metadata' => $inputSnapshot['candidate_metadata'] ?? null,
                ],
                'current_machine' => $inputSnapshot['source_machine_asset_code'] ?? $inputSnapshot['asset_code'] ?? null,
                'current_date' => $inputSnapshot['extracted_date'] ?? null,
                'current_time' => $this->normalizeTime($inputSnapshot['extracted_time'] ?? null),
            ]);
        }
        $case->fill([
            'expected_machine' => $isDaily ? $job->machine?->asset_code : null,
            'expected_date' => $isDaily ? $job->extracted_date?->format('Y-m-d') : null,
            'expected_time' => $isDaily ? $this->normalizeTime($job->extracted_time) : null,
            'expected_disposition' => $disposition,
            'expected_status' => $isDaily ? 'AUTO' : 'IGNORED',
            'notes' => $data['review_notes'] ?? null,
            'verification_status' => 'VERIFIED',
            'verified_at' => now(),
            'verified_by' => $user->id,
        ])->save();

        return $case->fresh();
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
