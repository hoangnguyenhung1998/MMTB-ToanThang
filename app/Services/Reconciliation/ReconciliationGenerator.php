<?php

namespace App\Services\Reconciliation;

use App\Models\MachineAssignment;
use App\Models\MachineDailyAssignment;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationGenerator
{
    public function generate(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->date_from->gt($period->date_to)) {
            throw new InvalidArgumentException('Ngày bắt đầu kỳ đối chiếu phải nhỏ hơn hoặc bằng ngày kết thúc.');
        }

        return DB::transaction(function () use ($period) {
            $period->dailyAssignments()->delete();

            $assignments = MachineAssignment::query()
                ->with('machine')
                ->whereDate('time_in', '<=', $period->date_to)
                ->where(function ($query) use ($period) {
                    $query->whereNull('time_out')
                        ->orWhereDate('time_out', '>=', $period->date_from);
                })
                ->orderBy('machine_id')
                ->orderBy('time_in')
                ->get();

            foreach ($assignments as $assignment) {
                $from = Carbon::parse($assignment->time_in)
                    ->startOfDay()
                    ->max($period->date_from->copy()->startOfDay());

                $to = $assignment->time_out
                    ? Carbon::parse($assignment->time_out)->startOfDay()
                    : $period->date_to->copy()->startOfDay();

                $to = $to->min($period->date_to->copy()->startOfDay());

                for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                    $changeType = null;
                    $changeNote = null;

                    if ($date->isSameDay(Carbon::parse($assignment->time_in))) {
                        $changeType = 'ASSIGNED';
                        $changeNote = 'Máy bắt đầu phân công tại BCH/dự án trong ngày.';
                    }

                    if ($assignment->time_out && $date->isSameDay(Carbon::parse($assignment->time_out))) {
                        $changeType = $changeType ? 'ASSIGNED_AND_RETURNED' : 'RETURNED';
                        $changeNote = 'Máy kết thúc phân công hoặc trả trong ngày.';
                    }

                    $snapshot = MachineDailyAssignment::updateOrCreate(
                        [
                            'reconciliation_period_id' => $period->id,
                            'machine_id' => $assignment->machine_id,
                            'work_date' => $date->toDateString(),
                        ],
                        [
                            'machine_assignment_id' => $assignment->id,
                            'project_id' => $assignment->project_id,
                            'command_center_id' => $assignment->command_center_id,
                            'driver_id' => $assignment->machine?->current_driver_id,
                            'machine_state' => $assignment->time_out && $date->isSameDay(Carbon::parse($assignment->time_out))
                                ? 'RETURNED'
                                : 'ACTIVE',
                            'change_type' => $changeType,
                            'change_note' => $changeNote,
                        ]
                    );

                    ReconciliationRow::updateOrCreate(
                        [
                            'reconciliation_period_id' => $period->id,
                            'machine_id' => $assignment->machine_id,
                            'work_date' => $date->toDateString(),
                        ],
                        [
                            'machine_daily_assignment_id' => $snapshot->id,
                            'project_id' => $snapshot->project_id,
                            'command_center_id' => $snapshot->command_center_id,
                            'driver_id' => $snapshot->driver_id,
                        ]
                    );
                }
            }

            $period->update([
                'status' => 'GENERATED',
                'generated_at' => now(),
            ]);

            return $period->fresh(['dailyAssignments', 'rows']);
        });
    }
}
