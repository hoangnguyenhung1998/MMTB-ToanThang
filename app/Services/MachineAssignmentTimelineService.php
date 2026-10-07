<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineEvent;
use App\Services\Reconciliation\AssignmentRelationshipPropagation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MachineAssignmentTimelineService
{
    public function assertInterval(int $machineId, Carbon $start, ?Carbon $end, array $ignore = []): void
    {
        if ($end && $start->gte($end)) {
            throw new BusinessRuleException('Khoảng phân công phải có bắt đầu trước kết thúc.');
        }
        $overlap = MachineAssignment::query()->where('machine_id', $machineId)->whereNotIn('id', $ignore)
            ->where('time_in', '<', $end ?? '9999-12-31 23:59:59')
            ->where(fn ($q) => $q->whereNull('time_out')->orWhere('time_out', '>', $start))
            ->lockForUpdate()->exists();
        if ($overlap) {
            throw new BusinessRuleException('Lịch phân công chồng lấn; cần kiểm tra mốc điều chuyển/trả máy.');
        }
    }

    public function reviseTransfer(int $machineId, int $targetId, string $timeOut, string $timeIn, ?int $actor): array
    {
        return DB::transaction(function () use ($machineId, $targetId, $timeOut, $timeIn, $actor) {
            Machine::query()->lockForUpdate()->findOrFail($machineId);
            $history = MachineAssignment::query()->where('machine_id', $machineId)->orderBy('time_in')->orderBy('id')->lockForUpdate()->get();
            $target = $history->firstWhere('id', $targetId);
            if (! $target) {
                throw new BusinessRuleException('Phân công không thuộc máy này.');
            }
            $event = MachineEvent::query()->where('machine_id', $machineId)->where('type', 'TRANSFER')
                ->where('occurred_at', $target->time_in)->where('to_project_id', $target->project_id)
                ->where('to_command_center_id', $target->command_center_id)->lockForUpdate()->get();
            $sourceCandidates = $history->filter(fn ($a) => $a->id !== $target->id && $a->time_out
                && $a->time_out->lte($target->time_in))->sortByDesc('time_out');
            $source = $sourceCandidates->first();
            if ($event->count() !== 1 || ! $source || $source->project_id !== (int) $event->first()->from_project_id
                || $source->command_center_id !== (int) $event->first()->from_command_center_id) {
                throw new BusinessRuleException('Không xác định duy nhất điều chuyển nguồn; không sửa qua boundary bàn giao/trả máy.');
            }
            if (MachineEvent::query()->where('machine_id', $machineId)->whereIn('type', ['RETURN', 'HANDOVER'])
                ->whereBetween('occurred_at', [$source->time_out, $target->time_in])->lockForUpdate()->exists()) {
                throw new BusinessRuleException('Không sửa điều chuyển qua boundary bàn giao/trả máy.');
            }
            $out = Carbon::parse($timeOut);
            $in = Carbon::parse($timeIn);
            if ($out->gt($in)) {
                throw new BusinessRuleException('Giờ ra nguồn không được sau giờ vào đích.');
            }
            $this->assertInterval($machineId, $source->time_in, $out, [$source->id, $target->id]);
            $this->assertInterval($machineId, $in, $target->time_out, [$source->id, $target->id]);
            if ($source->time_out->eq($out) && $target->time_in->eq($in)) {
                return ['changed' => false, 'propagation' => []];
            }
            $from = min($source->time_out->toDateTimeString(), $target->time_in->toDateTimeString(), $out->toDateTimeString(), $in->toDateTimeString());
            $source->update(['time_out' => $out]);
            $target->update(['time_in' => $in]);
            $eventChanges = ['occurred_at' => $in];
            if ($event->first()->event_date !== null) {
                $eventChanges['event_date'] = $in->toDateString();
            }
            $event->first()->update($eventChanges);
            $result = app(AssignmentRelationshipPropagation::class)->propagate($machineId, $from, $target->time_out?->toDateTimeString(), $actor);
            \App\Models\ActivityLog::create(['user_id' => $actor, 'machine_id' => $machineId, 'event' => 'machine.transfer_boundary_revised',
                'description' => 'Sửa mốc điều chuyển hồi tố và propagate relationship có kiểm soát.',
                'properties' => ['source_assignment_id' => $source->id, 'target_assignment_id' => $target->id,
                    'time_out' => $out->toDateTimeString(), 'time_in' => $in->toDateTimeString(), 'affected_from' => $from, 'propagation' => $result], 'occurred_at' => now()]);

            return ['changed' => true, 'propagation' => $result];
        }, 3);
    }
}
