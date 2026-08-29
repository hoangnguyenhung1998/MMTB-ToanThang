<?php

namespace App\Services;

use App\Models\MachineHandoverCase;
use App\Models\MachineHandoverOcrJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MachineHandoverOcrService
{
    public function __construct(private readonly MachineHandoverAlertDispatcher $alerts) {}

    public function claim(string $workerId): ?MachineHandoverOcrJob
    {
        return DB::transaction(function () use ($workerId) {
            $job = MachineHandoverOcrJob::query()->where(function ($query) {
                $query->whereIn('status', ['PENDING', 'RETRY'])
                    ->orWhere(fn ($expired) => $expired->where('status', 'PROCESSING')->where('lease_expires_at', '<=', now()));
            })->oldest()->lockForUpdate()->first();
            if (! $job) return null;
            $job->update(['status' => 'PROCESSING', 'claimed_by' => $workerId, 'claimed_at' => now(), 'lease_expires_at' => now()->addMinutes(10), 'attempts' => $job->attempts + 1, 'error_message' => null]);
            $job->document->update(['extraction_status' => 'PROCESSING']);
            return $job->load('document.handoverCase.machine');
        }, 3);
    }

    public function complete(MachineHandoverOcrJob $job, array $data): MachineHandoverOcrJob
    {
        $this->ensureOwner($job, $data['worker_id']);
        $flags = array_values(array_unique($data['review_flags'] ?? []));
        $job->update(['status' => $flags ? 'EXCEPTION' : 'COMPLETED', 'confidence' => $data['confidence'], 'extraction' => $data['extraction'], 'review_flags' => $flags ?: null, 'raw_text' => $data['raw_text'] ?? null, 'processed_at' => now(), 'lease_expires_at' => null]);
        $job->document->update(['extraction_status' => $job->status, 'extraction_json' => $data['extraction'], 'confidence' => $data['confidence']]);
        $case = $this->aggregate($job->document->handoverCase);
        if ($case->review_flags) $this->alerts->missingData($case);
        return $job->fresh('document.handoverCase');
    }

    public function fail(MachineHandoverOcrJob $job, array $data): MachineHandoverOcrJob
    {
        $this->ensureOwner($job, $data['worker_id']);
        $status = $data['retryable'] && $job->attempts < 3 ? 'RETRY' : 'FAILED';
        $job->update(['status' => $status, 'error_message' => $data['error'], 'lease_expires_at' => null, 'processed_at' => $status === 'FAILED' ? now() : null]);
        $job->document->update(['extraction_status' => $status]);
        return $job->fresh();
    }

    public function ensureOwner(MachineHandoverOcrJob $job, string $workerId): void
    {
        if ($job->status !== 'PROCESSING' || ! hash_equals((string) $job->claimed_by, $workerId) || $job->lease_expires_at?->isPast()) {
            throw ValidationException::withMessages(['worker_id' => 'This handover OCR job is not claimed by the supplied worker.']);
        }
    }

    private function aggregate(MachineHandoverCase $case): MachineHandoverCase
    {
        $case->load('documents.ocrJob', 'machine', 'intakeCase.project');
        $jobs = $case->documents->pluck('ocrJob')->filter();
        $ordered = $jobs->sortByDesc('confidence');
        $best = $ordered->first();
        $data = [];
        foreach (['asset_code', 'handover_date', 'project_text', 'command_center_text', 'machine_type', 'model_name', 'meter_hours', 'gps_status'] as $field) {
            $value = $ordered->map(fn ($job) => $job->extraction[$field] ?? null)->first(fn ($candidate) => filled($candidate));
            if (filled($value)) $data[$field] = $value;
        }
        foreach (['handover_people', 'technical_findings'] as $field) {
            $data[$field] = $ordered->flatMap(fn ($job) => $job->extraction[$field] ?? [])->filter()->unique()->values()->all();
        }
        $flags = $jobs->flatMap(fn ($job) => $job->review_flags ?? [])->all();
        $asset = $data['asset_code'] ?? null;
        if (! $asset) $flags[] = 'MISSING_ASSET_CODE';
        else {
            $flags = array_values(array_diff($flags, ['MISSING_ASSET_CODE']));
            if ($this->normalize($asset) !== $this->normalize($case->machine->asset_code)) $flags[] = 'ASSET_CODE_MISMATCH';
        }
        if (blank($data['handover_date'] ?? null)) $flags[] = 'MISSING_HANDOVER_DATE';
        else $flags = array_values(array_diff($flags, ['MISSING_HANDOVER_DATE']));
        if (! $case->project_id) $flags[] = 'MISSING_PROJECT';
        if (! $case->command_center_id) $flags[] = 'MISSING_COMMAND_CENTER';
        $projectText = $data['project_text'] ?? null;
        if ($projectText && $case->intakeCase?->project && ! str_contains($this->normalize($projectText), $this->normalize($case->intakeCase->project->name))) $flags[] = 'PROJECT_TEXT_MISMATCH';
        $case->update([
            'status' => 'REVIEW', 'handover_date' => $data['handover_date'] ?? $case->handover_date,
            'extracted_asset_code' => $asset, 'extracted_project_text' => $projectText,
            'extracted_command_center_text' => $data['command_center_text'] ?? null,
            'extraction' => $data, 'review_flags' => array_values(array_unique($flags)), 'confidence' => $best?->confidence,
        ]);
        return $case->fresh(['machine', 'project', 'commandCenter', 'documents']);
    }

    private function normalize(string $value): string { return strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $value)); }
}
