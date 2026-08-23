<?php

namespace App\Services;

use App\Models\AiReconciliationJob;
use App\Models\AiReconciliationSubmission;
use App\Models\JournalRow;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiReconciliationService
{
    private const DAILY_REVIEW_STATUSES = ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'];

    private const JOURNAL_REVIEW_STATUSES = ['APPROVED', 'CORRECTED'];

    public function claim(string $workerId, string $workDate, int $limit = 5): Collection
    {
        $this->enqueueCandidates($workDate);

        return DB::transaction(function () use ($workerId, $workDate, $limit): Collection {
            $jobs = AiReconciliationJob::query()
                ->whereHas('machine')
                ->whereDate('work_date', $workDate)
                ->where(function ($query): void {
                    $query->whereIn('status', ['PENDING', 'RETRY'])
                        ->orWhere(function ($expired): void {
                            $expired->where('status', 'PROCESSING')
                                ->where('lease_expires_at', '<=', now());
                        });
                })
                ->oldest('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $job->update([
                    'status' => 'PROCESSING',
                    'claimed_by' => $workerId,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds((int) config('openclaw.lease_seconds')),
                    'attempts' => $job->attempts + 1,
                    'failed_at' => null,
                    'error_message' => null,
                ]);
            }

            return $jobs->load('machine:id,asset_code,status');
        }, 3);
    }

    public function payload(AiReconciliationJob $job): array
    {
        $dailyJobs = $this->dailyJobs($job);
        $journalRows = $this->journalRows($job);
        $sourceJobs = $dailyJobs
            ->merge($journalRows->map(fn (JournalRow $row) => $row->document->ocrJob))
            ->unique('id')
            ->values();
        $assignment = $this->assignmentForDate($job);

        return [
            'id' => $job->id,
            'work_date' => $job->work_date->format('Y-m-d'),
            'status' => $job->status,
            'attempts' => $job->attempts,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'machine' => [
                'id' => $job->machine->id,
                'asset_code' => $job->machine->asset_code,
                'status' => $job->machine->status,
            ],
            'assignment' => $assignment ? [
                'project' => $assignment->project?->name,
                'command_center' => $assignment->commandCenter?->name,
                'time_in' => $assignment->time_in?->toIso8601String(),
                'time_out' => $assignment->time_out?->toIso8601String(),
            ] : null,
            'daily_images' => $dailyJobs->map(fn (OcrJob $ocrJob): array => [
                'ocr_job_id' => $ocrJob->id,
                'message_id' => $ocrJob->attachment->message->message_id,
                'sent_at' => $ocrJob->attachment->message->sent_at?->toIso8601String(),
                'sender_id' => $ocrJob->attachment->message->sender_id,
                'sender_name' => $ocrJob->attachment->message->sender_name,
                'time' => $ocrJob->extracted_time,
                'operator_name' => $ocrJob->operator_name,
                'phone' => $ocrJob->phone,
                'work_location' => $ocrJob->work_location,
                'confidence' => $ocrJob->confidence,
                'review_status' => $ocrJob->review_status,
                'image_url' => route('api.openclaw.reconciliation-jobs.images.show', [$job, $ocrJob], false),
            ])->all(),
            'journal_rows' => $journalRows->map(fn (JournalRow $row): array => [
                'journal_row_id' => $row->id,
                'ocr_job_id' => $row->document->ocr_job_id,
                'row_number' => $row->row_number,
                'work_date' => $row->work_date?->format('Y-m-d'),
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'total_minutes' => $row->total_minutes,
                'work_content' => $row->work_content,
                'work_location' => $row->work_location,
                'confidence' => $row->confidence,
                'image_url' => route('api.openclaw.reconciliation-jobs.images.show', [$job, $row->document->ocrJob], false),
            ])->all(),
            'source_images' => $sourceJobs->map(fn (OcrJob $ocrJob): array => [
                'ocr_job_id' => $ocrJob->id,
                'document_type' => $ocrJob->document_type,
                'image_url' => route('api.openclaw.reconciliation-jobs.images.show', [$job, $ocrJob], false),
            ])->all(),
        ];
    }

    public function complete(AiReconciliationJob $job, array $data): AiReconciliationSubmission
    {
        $existing = AiReconciliationSubmission::query()
            ->where('submission_uuid', $data['submission_uuid'])
            ->first();

        if ($existing) {
            if ($existing->ai_reconciliation_job_id !== $job->id) {
                throw ValidationException::withMessages([
                    'submission_uuid' => 'This submission UUID belongs to another reconciliation job.',
                ]);
            }

            return $existing->load('findings');
        }

        $this->ensureClaimOwner($job, $data['worker_id']);

        return DB::transaction(function () use ($job, $data): AiReconciliationSubmission {
            $submission = $job->submissions()->create([
                'submission_uuid' => $data['submission_uuid'],
                'outcome' => $data['outcome'],
                'summary' => $data['summary'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'agent_name' => $data['agent_name'],
                'model' => $data['model'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'submitted_at' => now(),
            ]);

            foreach ($data['findings'] as $finding) {
                $submission->findings()->create($finding);
            }

            $job->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'error_message' => null,
            ]);

            return $submission->load('findings');
        }, 3);
    }

    public function fail(AiReconciliationJob $job, array $data): AiReconciliationJob
    {
        $this->ensureClaimOwner($job, $data['worker_id']);

        $job->update([
            'status' => $data['retryable'] ? 'RETRY' : 'FAILED',
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'failed_at' => $data['retryable'] ? null : now(),
            'error_message' => $data['error'],
        ]);

        return $job->fresh('machine:id,asset_code,status');
    }

    public function sourceImage(AiReconciliationJob $job, OcrJob $sourceJob): OcrJob
    {
        $allowed = $this->dailyJobs($job)->contains('id', $sourceJob->id)
            || $this->journalRows($job)->contains(
                fn (JournalRow $row) => $row->document->ocr_job_id === $sourceJob->id,
            );

        abort_unless($allowed, 404);

        return $sourceJob->load('attachment');
    }

    private function enqueueCandidates(string $workDate): void
    {
        $dailyEvidence = OcrJob::query()
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereDate('extracted_date', $workDate)
            ->whereIn('review_status', self::DAILY_REVIEW_STATUSES)
            ->whereNotNull('machine_id')
            ->get([
                'id', 'machine_id', 'review_status', 'extracted_date', 'extracted_time',
                'operator_name', 'phone', 'work_location', 'confidence', 'updated_at',
            ])
            ->groupBy('machine_id');

        $journalEvidence = JournalRow::query()
            ->whereDate('work_date', $workDate)
            ->whereHas('document.ocrJob', fn ($query) => $query
                ->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
            ->whereHas('document', fn ($query) => $query->whereNotNull('machine_id'))
            ->with('document:id,machine_id,ocr_job_id')
            ->get()
            ->groupBy('document.machine_id');

        $machineIds = $dailyEvidence->keys()
            ->merge($journalEvidence->keys())
            ->unique()
            ->filter();

        foreach ($machineIds as $machineId) {
            $signatureParts = collect($dailyEvidence->get($machineId, []))
                ->map(fn (OcrJob $job): string => 'daily:'.json_encode([
                    $job->id,
                    $job->review_status,
                    $job->extracted_date?->format('Y-m-d'),
                    $job->extracted_time,
                    $job->operator_name,
                    $job->phone,
                    $job->work_location,
                    $job->confidence,
                    $job->updated_at?->format('Y-m-d H:i:s.u'),
                ]))
                ->merge(collect($journalEvidence->get($machineId, []))
                    ->map(fn (JournalRow $row): string => 'journal:'.json_encode([
                        $row->id,
                        $row->work_date?->format('Y-m-d'),
                        $row->start_time,
                        $row->end_time,
                        $row->total_minutes,
                        $row->work_content,
                        $row->work_location,
                        $row->confidence,
                        $row->updated_at?->format('Y-m-d H:i:s.u'),
                    ])))
                ->sort()
                ->values()
                ->all();
            $sourceSignature = hash('sha256', implode('|', $signatureParts));

            $job = AiReconciliationJob::query()->firstOrCreate(
                ['machine_id' => $machineId, 'work_date' => $workDate],
                ['status' => 'PENDING', 'source_signature' => $sourceSignature],
            );

            if ($job->source_signature !== $sourceSignature && $job->status !== 'PROCESSING') {
                $job->update([
                    'source_signature' => $sourceSignature,
                    'status' => 'PENDING',
                    'completed_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                ]);
            }
        }
    }

    private function dailyJobs(AiReconciliationJob $job): Collection
    {
        return OcrJob::query()
            ->with('attachment.message')
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('machine_id', $job->machine_id)
            ->whereDate('extracted_date', $job->work_date)
            ->whereIn('review_status', self::DAILY_REVIEW_STATUSES)
            ->orderBy('extracted_time')
            ->get();
    }

    private function journalRows(AiReconciliationJob $job): Collection
    {
        return JournalRow::query()
            ->with('document.ocrJob.attachment.message')
            ->whereDate('work_date', $job->work_date)
            ->whereHas('document', fn ($query) => $query->where('machine_id', $job->machine_id))
            ->whereHas('document.ocrJob', fn ($query) => $query
                ->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
            ->orderBy('start_time')
            ->get();
    }

    private function assignmentForDate(AiReconciliationJob $job): ?MachineAssignment
    {
        $startOfDay = $job->work_date->copy()->startOfDay();
        $endOfDay = $job->work_date->copy()->endOfDay();

        return MachineAssignment::query()
            ->with(['project:id,name', 'commandCenter:id,name'])
            ->where('machine_id', $job->machine_id)
            ->where('time_in', '<=', $endOfDay)
            ->where(function ($query) use ($startOfDay): void {
                $query->whereNull('time_out')
                    ->orWhere('time_out', '>=', $startOfDay);
            })
            ->latest('time_in')
            ->first();
    }

    private function ensureClaimOwner(AiReconciliationJob $job, string $workerId): void
    {
        if (
            $job->status !== 'PROCESSING'
            || ! hash_equals((string) $job->claimed_by, $workerId)
            || $job->lease_expires_at?->isPast()
        ) {
            throw ValidationException::withMessages([
                'worker_id' => 'This reconciliation job is not claimed by the supplied worker.',
            ]);
        }
    }
}
