<?php

namespace App\Services;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Services\Reconciliation\CanonicalAssignmentRelinker;
use App\Services\Reconciliation\DayBasedAssignmentOwnership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyPhotoCaseService
{
    public function __construct(private readonly DailyPhotoPairingService $pairing) {}

    public function materialize(OcrJob $job, bool $recompute = true, ?Collection $candidateAssignments = null, ?DayBasedAssignmentOwnership $ownership = null): ?DailyPhotoCase
    {
        if (! config('daily_photos.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($job, $recompute, $candidateAssignments, $ownership): ?DailyPhotoCase {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->document_type !== 'DAILY_TIMEMARK'
                || $job->status !== 'COMPLETED'
                || $job->review_status === 'REJECTED'
                || ! $job->machine_id
                || ! $job->extracted_date) {
                $this->detach($job);

                return null;
            }

            $workDate = $job->extracted_date->format('Y-m-d');
            $captureAt = $job->extracted_time ? $workDate.' '.substr((string) $job->extracted_time, 0, 8) : null;
            if ($captureAt !== null && strlen($captureAt) === 16) {
                $captureAt .= ':00';
            }
            $assignments = $candidateAssignments ?? MachineAssignment::query()
                ->where('machine_id', $job->machine_id)
                ->with('bchResolution')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // The day is authoritative, including photos before the physical transfer time.
            $ownership ??= new DayBasedAssignmentOwnership($assignments, DB::table('machine_events')->where('machine_id', $job->machine_id)->get(['id', 'machine_id', 'type', 'occurred_at']));
            $day = $ownership->resolve((int) $job->machine_id, $workDate);
            $assignment = $day['assignment'];
            $assignmentStatus = $assignment ? 'MATCHED' : ($day['reason'] ? 'AMBIGUOUS' : 'NOT_FOUND');
            $scopeKey = $assignment
                ? "assignment:{$assignment->id}|date:{$workDate}"
                : "machine:{$job->machine_id}|date:{$workDate}|assignment:unresolved";
            // Stored canonical identity wins over potentially stale OCR relationship metadata.
            $existingCase = $job->daily_photo_case_id ? DailyPhotoCase::query()->find($job->daily_photo_case_id) : null;
            if ($existingCase && $existingCase->scope_key !== $scopeKey) {
                if ($existingCase && $existingCase->machine_id === $job->machine_id && $existingCase->work_date->toDateString() === $workDate) {
                    $links = new CanonicalAssignmentRelinker([$job->machine_id], $workDate, $workDate);
                    $row = (object) ['machine_id' => $job->machine_id, 'work_date' => $workDate,
                        'machine_assignment_id' => $existingCase->machine_assignment_id, 'daily_intervals' => null];
                    if ($assignment && $links->reason($row, $assignment) === null) {
                        $links->plan($row, $assignment, auth()->id(), now()->toDateTimeString());
                        $links->flush(now()->toDateTimeString());

                        // Preserve member/interval IDs and the existing pairing result during ownership correction.
                        return $existingCase->fresh(['evidenceMemberships', 'intervals']);
                    }

                    // Conflicting/populated/protected cases need explicit review, not rematerialization.
                    return $existingCase;
                }
            }
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
            if ($membership && ! $recompute
                && $membership->daily_photo_case_id === $case->id
                && $membership->capture_datetime?->format('Y-m-d H:i:s') === $captureAt) {
                $metadataLinks = $job->daily_metadata['case_materialization'] ?? [];
                if ($assignment && (($metadataLinks['machine_assignment_id'] ?? null) !== $assignment->id
                    || ($metadataLinks['scope_key'] ?? null) !== $scopeKey
                    || (isset($metadataLinks['daily_photo_case_id']) && $metadataLinks['daily_photo_case_id'] !== $case->id))) {
                    $links = new CanonicalAssignmentRelinker([$job->machine_id], $workDate, $workDate);
                    $row = (object) ['machine_id' => $job->machine_id, 'work_date' => $workDate,
                        'machine_assignment_id' => $case->machine_assignment_id, 'daily_intervals' => null];
                    if ($links->reason($row, $assignment) === null) {
                        $links->plan($row, $assignment, auth()->id(), now()->toDateTimeString());
                        $links->flush(now()->toDateTimeString());
                    }
                }

                return $case;
            }
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
                'candidate_machine_assignment_ids' => $assignment ? [$assignment->id] : collect($day['candidates'])->pluck('id')->all(),
                'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                'capture_timezone' => config('daily_photos.capture_timezone'),
            ];
            $job->update([
                'daily_photo_case_id' => $case->id,
                'daily_metadata' => $metadata,
            ]);

            if (! $recompute) {
                return $case;
            }

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
