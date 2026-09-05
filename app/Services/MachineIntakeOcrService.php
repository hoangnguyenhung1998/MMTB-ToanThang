<?php

namespace App\Services;

use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeDocument;
use App\Models\MachineIntakeEvent;
use App\Models\MachineIntakeOcrJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MachineIntakeOcrService
{
    private const FIELDS = ['company', 'chassis_no', 'engine_no', 'machine_type', 'brand', 'model_name', 'plate_no', 'capacity_class', 'vehicle_axles', 'manufacture_year'];

    public function enqueueCase(MachineIntakeCase $case, bool $retry = false): int
    {
        $count = 0;
        foreach ($case->documents as $document) {
            $job = MachineIntakeOcrJob::firstOrCreate(['machine_intake_document_id' => $document->id], ['status' => 'PENDING']);
            if ($retry && in_array($job->status, ['FAILED', 'COMPLETED', 'EXCEPTION'], true)) {
                $job->update(['status' => 'PENDING', 'claimed_by' => null, 'lease_expires_at' => null, 'error_message' => null]);
            }
            if ($job->status === 'PENDING') {
                $document->update(['extraction_status' => 'QUEUED']);
                $count++;
            }
        }
        return $count;
    }

    public function claim(string $workerId): ?MachineIntakeOcrJob
    {
        return DB::transaction(function () use ($workerId) {
            $job = MachineIntakeOcrJob::query()->where(function ($query) {
                $query->whereIn('status', ['PENDING', 'RETRY'])
                    ->orWhere(fn ($expired) => $expired->where('status', 'PROCESSING')->where('lease_expires_at', '<=', now()));
            })->oldest()->lockForUpdate()->first();
            if (! $job) return null;
            $job->update(['status' => 'PROCESSING', 'claimed_by' => $workerId, 'claimed_at' => now(), 'lease_expires_at' => now()->addMinutes(10), 'attempts' => $job->attempts + 1, 'error_message' => null]);
            $job->document->update(['extraction_status' => 'PROCESSING']);
            return $job->load('document.intakeCase');
        }, 3);
    }

    public function complete(MachineIntakeOcrJob $job, array $data): MachineIntakeOcrJob
    {
        $this->ensureOwner($job, $data['worker_id']);
        $flags = array_values(array_unique($data['review_flags'] ?? []));
        $job->update(['status' => $flags ? 'EXCEPTION' : 'COMPLETED', 'confidence' => $data['confidence'], 'extraction' => $data['extraction'], 'review_flags' => $flags ?: null, 'raw_text' => $data['raw_text'] ?? null, 'processed_at' => now(), 'lease_expires_at' => null]);
        $job->document->update(['extraction_status' => $job->status, 'extraction_json' => $data['extraction'], 'confidence' => $data['confidence']]);
        $this->aggregate($job->document->intakeCase);
        return $job->fresh('document.intakeCase');
    }

    public function fail(MachineIntakeOcrJob $job, array $data): MachineIntakeOcrJob
    {
        $this->ensureOwner($job, $data['worker_id']);
        $status = $data['retryable'] && $job->attempts < 3 ? 'RETRY' : 'FAILED';
        $job->update(['status' => $status, 'error_message' => $data['error'], 'lease_expires_at' => null, 'processed_at' => $status === 'FAILED' ? now() : null]);
        $job->document->update(['extraction_status' => $status]);
        return $job->fresh();
    }

    public function ensureOwner(MachineIntakeOcrJob $job, string $workerId): void
    {
        if ($job->status !== 'PROCESSING' || ! hash_equals((string) $job->claimed_by, $workerId) || $job->lease_expires_at?->isPast()) {
            throw ValidationException::withMessages(['worker_id' => 'This intake OCR job is not claimed by the supplied worker.']);
        }
    }

    private function aggregate(MachineIntakeCase $case): void
    {
        $case->load('documents.ocrJob');
        $summary = []; $flags = [];
        foreach (self::FIELDS as $field) {
            $candidates = [];
            foreach ($case->documents as $document) {
                $extraction = $document->ocrJob?->extraction ?? [];
                $value = $extraction[$field] ?? null;
                if ($value !== null && $value !== '') $candidates[] = ['value' => $value, 'confidence' => (float) ($document->ocrJob->confidence ?? 0), 'document_id' => $document->id];
            }
            usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
            if ($candidates) {
                $normalized = array_unique(array_map(fn ($item) => $this->normalizeForComparison((string) $item['value']), $candidates));
                if (count($normalized) > 1) $flags[] = 'CONFLICT_'.strtoupper($field);
                $summary[$field] = $candidates[0];
            } else $flags[] = 'MISSING_'.strtoupper($field);
        }
        foreach ($case->documents as $document) foreach (($document->ocrJob?->review_flags ?? []) as $flag) $flags[] = $flag;
        $updates = ['extraction_summary' => $summary, 'review_flags' => array_values(array_unique($flags))];
        if (! $case->confirmed_at && ! $case->machine_id) $updates['status'] = 'EXTRACTED';
        foreach (self::FIELDS as $field) if (! $case->confirmed_at && isset($summary[$field]['value'])) $updates[$field] = $summary[$field]['value'];
        if (isset($updates['company'])) {
            $code = strtoupper(trim((string) $updates['company']));
            if (\App\Models\Company::where('code', $code)->where('is_active', true)->exists()) {
                $updates['company'] = $code;
            } else {
                // Preserve the raw OCR candidate in extraction_summary; never invent a catalog entry.
                unset($updates['company']);
                $updates['review_flags'][] = 'UNKNOWN_COMPANY';
            }
        }
        if (isset($updates['chassis_no'])) { $updates['chassis_no_raw'] = $updates['chassis_no']; $updates['chassis_no'] = $this->normalizeIdentifier($updates['chassis_no']); }
        if (isset($updates['engine_no'])) { $updates['engine_no_raw'] = $updates['engine_no']; $updates['engine_no'] = $this->normalizeIdentifier($updates['engine_no']); }
        $case->update(app(MachineSpecificationNormalizer::class)->normalize($updates));
        MachineIntakeEvent::create(['machine_intake_case_id' => $case->id, 'event' => 'intake.ocr_aggregated', 'properties' => ['flags' => $updates['review_flags']], 'occurred_at' => now()]);
    }

    private function normalizeForComparison(string $value): string { return strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $value)); }
    private function normalizeIdentifier(string $value): string { return strtoupper((string) preg_replace('/[^A-Z0-9-]/', '', $value)); }
}
