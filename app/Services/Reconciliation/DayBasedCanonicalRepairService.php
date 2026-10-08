<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Repair existing materialized days even when a new period has no rows yet. */
class DayBasedCanonicalRepairService
{
    public function repair(ReconciliationPeriod $period, ?int $machineId = null, ?string $date = null, ?int $bchId = null): array
    {
        return DB::transaction(function () use ($period, $machineId, $date, $bchId) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                throw new RuntimeException('Kỳ đã chốt hoặc khóa.');
            }
            if ($date !== null && ($date < $period->date_from->toDateString() || $date > $period->date_to->toDateString())) {
                throw new \InvalidArgumentException('Ngày nằm ngoài kỳ.');
            }
            $from = $date ?? $period->date_from->toDateString();
            $to = $date ?? $period->date_to->toDateString();
            $ids = DB::table('daily_photo_cases')->whereBetween('work_date', [$from, $to.' 23:59:59'])
                ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->distinct()->orderBy('machine_id')->pluck('machine_id')->all();
            $blocked = [];
            foreach (array_chunk($ids, 100) as $chunk) {
                $history = DB::table('machine_assignments as a')
                    ->leftJoin('machine_assignment_bch_resolutions as r', 'r.machine_assignment_id', '=', 'a.id')
                    ->leftJoin('projects as p', 'p.id', '=', 'a.project_id')
                    ->leftJoin('command_centers as b', 'b.id', '=', DB::raw('COALESCE(a.command_center_id, r.command_center_id)'))
                    ->whereIn('a.machine_id', $chunk)->lockForUpdate()->get(['a.*', 'p.id as source_project_id', 'b.id as source_bch_id']);
                $ownership = new DayBasedAssignmentOwnership($history, DB::table('machine_events')->whereIn('machine_id', $chunk)->get(['id', 'machine_id', 'type', 'occurred_at']));
                $canonical = new CanonicalAssignmentRelinker($chunk, $from, $to);
                foreach ($canonical->caseRows() as $case) {
                    $day = $ownership->resolve($case->machine_id, $case->work_date);
                    $owner = $day['assignment'];
                    if ($bchId && (! $owner || (int) $owner->source_bch_id !== $bchId)) {
                        continue;
                    }
                    $row = (object) ['id' => null, 'machine_id' => $case->machine_id, 'work_date' => $case->work_date,
                        'machine_assignment_id' => $case->machine_assignment_id];
                    if (! $owner || $day['reason']) {
                        $blocked[$case->machine_id.'|'.$case->work_date] = $day['reason'] ?? 'NO_EFFECTIVE_ASSIGNMENT';
                    } elseif ($reason = $canonical->reason($row, $owner)) {
                        $blocked[$case->machine_id.'|'.$case->work_date] = $reason;
                    } elseif ($canonical->needsRelink($row, $owner)) {
                        $canonical->plan($row, $owner, auth()->id(), now()->toDateTimeString());
                    }
                }
                $canonical->flush(now()->toDateTimeString());
            }

            return $blocked;
        });
    }
}
