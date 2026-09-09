<?php

namespace App\Services;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use Illuminate\Support\Facades\DB;

class DailyPhotoCaseService
{
    public function __construct(private readonly DailyPhotoPairingService $pairing) {}

    public function materialize(OcrJob $job): ?DailyPhotoCase
    {
        if (! config('daily_photos.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($job): ?DailyPhotoCase {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->document_type !== 'DAILY_TIMEMARK'
                || $job->status !== 'COMPLETED'
                || ! $job->machine_id
                || ! $job->extracted_date
                || ! $job->extracted_time) {
                $this->detach($job);

                return null;
            }

            $workDate = $job->extracted_date->format('Y-m-d');
            $captureAt = $workDate.' '.substr((string) $job->extracted_time, 0, 8);
            $assignments = MachineAssignment::query()
                ->where('machine_id', $job->machine_id)
                ->where('time_in', '<=', $captureAt)
                ->where(fn ($query) => $query->whereNull('time_out')->orWhere('time_out', '>', $captureAt))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assignment = $assignments->count() === 1 ? $assignments->first() : null;
            $scopeKey = $assignment
                ? "assignment:{$assignment->id}|date:{$workDate}"
                : "machine:{$job->machine_id}|date:{$workDate}|assignment:unresolved";
            $assignmentStatus = match ($assignments->count()) {
                0 => 'NOT_FOUND',
                1 => 'MATCHED',
                default => 'AMBIGUOUS',
            };

            $case = DailyPhotoCase::query()->firstOrCreate(
                ['scope_key' => $scopeKey],
                [
                    'machine_id' => $job->machine_id,
                    'machine_assignment_id' => $assignment?->id,
                    'work_date' => $workDate,
                    'status' => DailyPhotoCase::STATUS_COLLECTING,
                    'source_version' => config('daily_photos.foundation_version'),
                    'source_metadata' => [
                        'identity_strategy' => $assignment ? 'ASSIGNMENT_WORK_DATE' : 'MACHINE_WORK_DATE_UNRESOLVED_ASSIGNMENT',
                        'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                        'capture_timezone' => config('daily_photos.capture_timezone'),
                    ],
                ],
            );

            $membership = DailyPhotoCaseEvidence::query()->where('ocr_job_id', $job->id)->first();
            $oldCaseId = $membership?->daily_photo_case_id;
            $caseIds = collect([$oldCaseId, $case->id])->filter()->unique()->sort()->values();
            DailyPhotoCase::query()->whereKey($caseIds->all())->orderBy('id')->lockForUpdate()->get();
            $membership = DailyPhotoCaseEvidence::query()->where('ocr_job_id', $job->id)->lockForUpdate()->first();
            if ($membership) {
                if ($membership->daily_photo_case_id !== $case->id) {
                    DailyPhotoInterval::query()
                        ->where('start_evidence_id', $membership->id)
                        ->orWhere('end_evidence_id', $membership->id)
                        ->delete();
                }
                $membership->update([
                    'daily_photo_case_id' => $case->id,
                    'capture_datetime' => $captureAt,
                    'assignment_resolution_status' => $assignmentStatus,
                    'pairing_state' => DailyPhotoCaseEvidence::STATE_UNMATCHED,
                    'pairing_diagnostic' => null,
                ]);
            } else {
                $membership = DailyPhotoCaseEvidence::query()->create([
                    'daily_photo_case_id' => $case->id,
                    'ocr_job_id' => $job->id,
                    'capture_datetime' => $captureAt,
                    'assignment_resolution_status' => $assignmentStatus,
                    'pairing_state' => DailyPhotoCaseEvidence::STATE_UNMATCHED,
                ]);
            }

            $metadata = $job->daily_metadata ?? [];
            $metadata['case_materialization'] = [
                'version' => config('daily_photos.foundation_version'),
                'daily_photo_case_id' => $case->id,
                'daily_photo_case_evidence_id' => $membership->id,
                'membership_status' => 'ACTIVE',
                'scope_key' => $scopeKey,
                'assignment_resolution_status' => $assignmentStatus,
                'machine_assignment_id' => $assignment?->id,
                'candidate_machine_assignment_ids' => $assignments->modelKeys(),
                'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                'capture_timezone' => config('daily_photos.capture_timezone'),
            ];
            $job->update([
                'daily_photo_case_id' => $case->id,
                'daily_metadata' => $metadata,
            ]);

            $this->pairing->recomputeMany($caseIds->all());

            return $case->fresh(['evidenceMemberships', 'intervals']);
        }, 3);
    }

    public function detach(OcrJob $job): void
    {
        if (! config('daily_photos.enabled')) {
            return;
        }

        DB::transaction(function () use ($job): void {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            $membership = DailyPhotoCaseEvidence::query()->where('ocr_job_id', $job->id)->first();
            $oldCaseId = $membership?->daily_photo_case_id;
            if ($oldCaseId) {
                DailyPhotoCase::query()->whereKey($oldCaseId)->lockForUpdate()->first();
                $membership = DailyPhotoCaseEvidence::query()->where('ocr_job_id', $job->id)->lockForUpdate()->first();
                $membership?->delete();
            }

            $metadata = $job->daily_metadata ?? [];
            if (isset($metadata['case_materialization'])) {
                $metadata['case_materialization']['daily_photo_case_id'] = null;
                $metadata['case_materialization']['daily_photo_case_evidence_id'] = null;
                $metadata['case_materialization']['membership_status'] = 'DETACHED';
            }
            $job->update([
                'daily_photo_case_id' => null,
                'daily_metadata' => $metadata,
            ]);

            if ($oldCaseId) {
                $this->pairing->recomputeMany([$oldCaseId]);
            }
        }, 3);
    }
}
