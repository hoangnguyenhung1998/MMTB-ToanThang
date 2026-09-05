<?php

namespace App\Services\Reconciliation;

use App\Models\ActivityLog;
use App\Models\MachineAssignment;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationLinkRepairService
{
    public function repair(ReconciliationPeriod $period, ?int $userId): array
    {
        return DB::transaction(function () use ($period, $userId) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (!in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                throw new RuntimeException('Kỳ đã chốt hoặc khóa, không thể sửa liên kết.');
            }
            $repaired = 0;
            $removed = 0;
            $unresolved = 0;
            $rows = $period->rows()->lockForUpdate()->get();
            $assignments = MachineAssignment::query()
                ->with(['project', 'commandCenter', 'bchResolution.commandCenter'])
                ->whereIn('id', $rows->pluck('machine_assignment_id')->filter()->unique())
                ->get()
                ->keyBy('id');
            foreach ($rows as $row) {
                $assignment = $assignments->get($row->machine_assignment_id);
                $sourceBch = $assignment?->commandCenter ?: $assignment?->bchResolution?->commandCenter;
                $sourceBchId = $sourceBch?->id;
                $outsideAssignment = $assignment && (
                    $assignment->time_in->copy()->startOfDay()->gt($row->work_date)
                    || ($assignment->time_out && $assignment->time_out->copy()->endOfDay()->lt($row->work_date))
                );
                if ($outsideAssignment) {
                    $hasHumanChanges = in_array($row->status, ['REVIEWED', 'CONFIRMED'], true)
                        || $row->manually_edited_at;
                    $hasEvidence = !empty($row->daily_ocr_job_ids)
                        || !empty($row->journal_row_ids)
                        || $row->ai_reconciliation_job_id;
                    $evidenceIsPreserved = !$hasEvidence || $rows->contains(function ($candidate) use ($row, $assignments) {
                        if ($candidate->id === $row->id
                            || (int) $candidate->machine_id !== (int) $row->machine_id
                            || !$candidate->work_date->isSameDay($row->work_date)
                            || $candidate->segment_start !== $row->segment_start
                            || $candidate->segment_end !== $row->segment_end) {
                            return false;
                        }
                        $candidateAssignment = $assignments->get($candidate->machine_assignment_id);
                        $candidateBch = $candidateAssignment?->commandCenter ?: $candidateAssignment?->bchResolution?->commandCenter;
                        if (!$candidateAssignment || !$candidateAssignment->project || !$candidateBch
                            || $candidateAssignment->time_in->copy()->startOfDay()->gt($candidate->work_date)
                            || ($candidateAssignment->time_out && $candidateAssignment->time_out->copy()->endOfDay()->lt($candidate->work_date))) {
                            return false;
                        }
                        return collect($candidate->daily_ocr_job_ids)->sort()->values()->all() === collect($row->daily_ocr_job_ids)->sort()->values()->all()
                            && collect($candidate->journal_row_ids)->sort()->values()->all() === collect($row->journal_row_ids)->sort()->values()->all()
                            && (int) $candidate->ai_reconciliation_job_id === (int) $row->ai_reconciliation_job_id;
                    });
                    $hasProtectedData = $hasHumanChanges || !$evidenceIsPreserved;
                    if ($hasProtectedData) {
                        $unresolved++;
                        continue;
                    }
                    ActivityLog::create([
                        'user_id' => $userId, 'machine_id' => $row->machine_id,
                        'subject_type' => $row->getMorphClass(), 'subject_id' => $row->id,
                        'event' => 'reconciliation.stale_row_removed',
                        'description' => 'Xóa dòng nháp nằm ngoài thời gian của phân công nguồn.',
                        'properties' => ['row' => $row->only(['id', 'work_date', 'project_id', 'command_center_id', 'machine_assignment_id'])],
                        'occurred_at' => now(),
                    ]);
                    $row->delete();
                    $removed++;
                    continue;
                }
                $needsRepair = !$assignment || !$assignment->project || !$sourceBch
                    || (int) $row->project_id !== (int) $assignment->project_id
                    || (int) $row->command_center_id !== (int) $sourceBchId;
                if (!$needsRepair) {
                    continue;
                }
                if (in_array($row->status, ['REVIEWED', 'CONFIRMED'], true)) {
                    $unresolved++;
                    continue;
                }
                if (!$assignment || (int) $assignment->machine_id !== (int) $row->machine_id
                    || !$assignment->project || !$sourceBch
                    || $assignment->time_in->copy()->startOfDay()->gt($row->work_date)
                    || ($assignment->time_out && $assignment->time_out->copy()->endOfDay()->lt($row->work_date))) {
                    $unresolved++;
                    continue;
                }
                $old = $row->only(['project_id', 'command_center_id']);
                $new = ['project_id' => $assignment->project_id, 'command_center_id' => $sourceBchId];
                $row->update($new);
                ActivityLog::create([
                    'user_id' => $userId, 'machine_id' => $row->machine_id,
                    'subject_type' => $row->getMorphClass(), 'subject_id' => $row->id,
                    'event' => 'reconciliation.links_repaired',
                    'description' => 'Khôi phục liên kết từ đúng phân công nguồn của dòng đối chiếu.',
                    'properties' => ['old' => $old, 'new' => $new, 'machine_assignment_id' => $assignment->id],
                    'occurred_at' => now(),
                ]);
                $repaired++;
            }
            return compact('repaired', 'removed', 'unresolved');
        });
    }
}
