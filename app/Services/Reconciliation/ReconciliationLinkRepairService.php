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
            $unresolved = 0;
            $rows = $period->rows()->lockForUpdate()->get();
            $assignments = MachineAssignment::query()
                ->with(['project', 'commandCenter'])
                ->whereIn('id', $rows->pluck('machine_assignment_id')->filter()->unique())
                ->get()
                ->keyBy('id');
            foreach ($rows as $row) {
                $assignment = $assignments->get($row->machine_assignment_id);
                $needsRepair = !$assignment
                    || (int) $row->project_id !== (int) $assignment->project_id
                    || (int) $row->command_center_id !== (int) $assignment->command_center_id;
                if (!$needsRepair) {
                    continue;
                }
                if (in_array($row->status, ['REVIEWED', 'CONFIRMED'], true)) {
                    $unresolved++;
                    continue;
                }
                if (!$assignment || (int) $assignment->machine_id !== (int) $row->machine_id
                    || !$assignment->project || !$assignment->commandCenter
                    || $assignment->time_in->copy()->startOfDay()->gt($row->work_date)
                    || ($assignment->time_out && $assignment->time_out->copy()->endOfDay()->lt($row->work_date))) {
                    $unresolved++;
                    continue;
                }
                $old = $row->only(['project_id', 'command_center_id']);
                $new = ['project_id' => $assignment->project_id, 'command_center_id' => $assignment->command_center_id];
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
            return compact('repaired', 'unresolved');
        });
    }
}
