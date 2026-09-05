<?php

namespace App\Services\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\MachineAssignment;
use App\Models\MachineAssignmentBchResolution;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReconciliationAssignmentBchResolutionService
{
    public function resolve(ReconciliationPeriod $period, MachineAssignment $assignment, CommandCenter $bch, ?int $userId): array
    {
        if (!in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
            throw new RuntimeException('Kỳ đã chốt hoặc khóa, không thể phục hồi BCH lịch sử.');
        }

        return DB::transaction(function () use ($period, $assignment, $bch, $userId) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $assignment = MachineAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (!$period->rows()->where('machine_assignment_id', $assignment->id)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'Phân công không thuộc kỳ đối chiếu này.']);
            }
            if ($assignment->command_center_id) {
                throw ValidationException::withMessages(['assignment' => 'Phân công nguồn đã có BCH, không cần phục hồi.']);
            }

            $previous = MachineAssignmentBchResolution::query()->where('machine_assignment_id', $assignment->id)->first();
            $resolution = MachineAssignmentBchResolution::query()->updateOrCreate(
                ['machine_assignment_id' => $assignment->id],
                ['command_center_id' => $bch->id, 'confirmed_by' => $userId]
            );
            $updated = 0;
            $protected = 0;
            $dates = [];
            foreach ($period->rows()->where('machine_assignment_id', $assignment->id)->lockForUpdate()->get() as $row) {
                if (in_array($row->status, ['REVIEWED', 'CONFIRMED'], true) || $row->manually_edited_at) {
                    $protected++;
                    continue;
                }
                $changes = ['project_id' => $assignment->project_id, 'command_center_id' => $bch->id];
                if ($row->work_date->isSameDay($assignment->time_in)) {
                    $changes['segment_start'] = $assignment->time_in->format('H:i:s');
                }
                if ($assignment->time_out && $row->work_date->isSameDay($assignment->time_out)) {
                    $changes['segment_end'] = $assignment->time_out->format('H:i:s');
                }
                $row->update($changes);
                $dates[] = $row->work_date->format('Y-m-d');
                $updated++;
            }

            // Legacy rows may still have all-day segments after an assignment boundary changed.
            $affectedDates = array_values(array_unique($dates));
            $siblingRows = $period->rows()
                ->where('machine_id', $assignment->machine_id)
                ->with('assignment')
                ->lockForUpdate()
                ->get();
            foreach ($siblingRows as $row) {
                if (!in_array($row->work_date->format('Y-m-d'), $affectedDates, true)
                    || !$row->assignment
                    || in_array($row->status, ['REVIEWED', 'CONFIRMED'], true)
                    || $row->manually_edited_at) {
                    continue;
                }
                $segment = [];
                if ($row->work_date->isSameDay($row->assignment->time_in)) {
                    $segment['segment_start'] = $row->assignment->time_in->format('H:i:s');
                }
                if ($row->assignment->time_out && $row->work_date->isSameDay($row->assignment->time_out)) {
                    $segment['segment_end'] = $row->assignment->time_out->format('H:i:s');
                }
                if ($segment) {
                    $row->update($segment);
                }
            }

            ActivityLog::create([
                'user_id' => $userId,
                'machine_id' => $assignment->machine_id,
                'subject_type' => $assignment->getMorphClass(),
                'subject_id' => $assignment->id,
                'event' => 'reconciliation.assignment_bch_resolved',
                'description' => 'Xác nhận BCH cho phân công lịch sử bị mất liên kết.',
                'properties' => [
                    'old_command_center_id' => $previous?->command_center_id,
                    'new_command_center_id' => $resolution->command_center_id,
                    'reconciliation_period_id' => $period->id,
                    'updated_rows' => $updated,
                    'protected_rows' => $protected,
                ],
                'occurred_at' => now(),
            ]);

            return ['updated' => $updated, 'protected' => $protected, 'machine_id' => $assignment->machine_id, 'dates' => $affectedDates];
        });
    }
}
