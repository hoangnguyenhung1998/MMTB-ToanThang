<?php

namespace App\Services\Reconciliation;

use App\Models\MachineAssignment;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ReconciliationGenerator
{
    public function generate(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->date_from->gt($period->date_to)) {
            throw new InvalidArgumentException('Ngày bắt đầu kỳ đối chiếu phải nhỏ hơn hoặc bằng ngày kết thúc.');
        }

        if ($period->rows()->whereIn('status', ['REVIEWED', 'CONFIRMED'])->exists()) {
            throw new RuntimeException('Không thể tạo lại kỳ đã có dữ liệu được duyệt hoặc xác nhận.');
        }

        return DB::transaction(function () use ($period) {
            // Safe because reviewed/confirmed rows were blocked above.
            $period->rows()->delete();

            $assignments = MachineAssignment::query()
                ->where('time_in', '<=', $period->date_to->copy()->endOfDay())
                ->where(function ($query) use ($period) {
                    $query->whereNull('time_out')
                        ->orWhere('time_out', '>=', $period->date_from->copy()->startOfDay());
                })
                ->orderBy('machine_id')
                ->orderBy('time_in')
                ->orderBy('id')
                ->get();

            foreach ($assignments as $assignment) {
                $assignmentStart = Carbon::parse($assignment->time_in);
                $assignmentEnd = $assignment->time_out
                    ? Carbon::parse($assignment->time_out)
                    : $period->date_to->copy()->endOfDay();

                $from = $assignmentStart->copy()->startOfDay()
                    ->max($period->date_from->copy()->startOfDay());
                $to = $assignmentEnd->copy()->startOfDay()
                    ->min($period->date_to->copy()->startOfDay());

                if ($from->gt($to)) {
                    continue;
                }

                $isTransferIn = MachineAssignment::query()
                    ->where('machine_id', $assignment->machine_id)
                    ->where(function ($query) use ($assignment) {
                        $query->where('time_in', '<', $assignment->time_in)
                            ->orWhere(function ($sameTime) use ($assignment) {
                                $sameTime->where('time_in', $assignment->time_in)
                                    ->where('id', '<', $assignment->id);
                            });
                    })
                    ->exists();

                for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                    $driverId = DB::table('machine_driver_histories')
                        ->where('machine_id', $assignment->machine_id)
                        ->where('started_at', '<=', $date->copy()->endOfDay())
                        ->where(function ($query) use ($date) {
                            $query->whereNull('ended_at')
                                ->orWhere('ended_at', '>=', $date->copy()->startOfDay());
                        })
                        ->orderByDesc('started_at')
                        ->orderByDesc('id')
                        ->value('driver_id');

                    $changeType = null;
                    $changeNote = null;

                    if ($date->isSameDay($assignmentStart)) {
                        $changeType = $isTransferIn ? 'TRANSFER_IN' : 'HANDOVER';
                        $changeNote = $isTransferIn
                            ? 'Máy được điều chuyển vào BCH/dự án trong ngày.'
                            : 'Máy bắt đầu được bàn giao vào BCH/dự án trong ngày.';
                    }

                    if ($assignment->time_out && $date->isSameDay($assignmentEnd)) {
                        $changeType = $changeType ? $changeType.'_AND_END' : 'ASSIGNMENT_END';
                        $changeNote = trim(($changeNote ? $changeNote.' ' : '').'Máy kết thúc phân công trong ngày.');
                    }

                    // If several assignments overlap the same machine/day, the later
                    // assignment wins because assignments are processed chronologically.
                    ReconciliationRow::updateOrCreate(
                        [
                            'reconciliation_period_id' => $period->id,
                            'machine_id' => $assignment->machine_id,
                            'work_date' => $date->toDateString(),
                        ],
                        [
                            'machine_assignment_id' => $assignment->id,
                            'project_id' => $assignment->project_id,
                            'command_center_id' => $assignment->command_center_id,
                            'driver_id' => $driverId,
                            'change_type' => $changeType,
                            'change_note' => $changeNote,
                            'status' => 'DRAFT',
                        ]
                    );
                }
            }

            $period->update([
                'status' => 'GENERATED',
                'generated_at' => now(),
            ]);

            return $period->fresh(['rows']);
        });
    }
}
