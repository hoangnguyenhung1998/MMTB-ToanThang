<?php

namespace App\Services\Reconciliation;

use App\Models\ActivityLog;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;

class AssignmentRelationshipPropagation
{
    public function propagate(int $machineId, string $from, ?string $to, ?int $actor): array
    {
        return DB::transaction(fn () => $this->apply($machineId, $from, $to, $actor), 3);
    }

    private function apply(int $machineId, string $from, ?string $to, ?int $actor): array
    {
        $windowFrom = $from;
        $windowTo = $to;
        $from = substr($from, 0, 10);
        $to = $to ? substr($to, 0, 10) : '9999-12-31';
        $result = ['periods' => [], 'protected_periods' => [], 'canonical_review' => [], 'canonical_unassigned' => 0];
        $periods = ReconciliationPeriod::query()->where('date_to', '>=', $from)->where('date_from', '<=', $to)
            ->whereHas('rows', fn ($q) => $q->where('machine_id', $machineId)->whereBetween('work_date', [$from, $to.' 23:59:59']))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($periods as $period) {
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                $result['protected_periods'][] = ['period_id' => $period->id, 'reason' => 'PROTECTED_PERIOD'];

                continue;
            }
            $result['periods'][$period->id] = app(ReconciliationLinkRepairService::class)->repair($period, $actor, $machineId, $windowFrom, $windowTo);
        }
        // Canonical cases may exist before a reconciliation period is generated.
        $canonical = new CanonicalAssignmentRelinker([$machineId], $from, $to);
        $assignments = DB::table('machine_assignments')->where('machine_id', $machineId)->orderBy('id')->lockForUpdate()->get();
        $timeline = new AssignmentTimelineState($assignments, DB::table('machine_events')->where('machine_id', $machineId)
            ->whereIn('type', ['RETURN', 'HANDOVER', 'TRANSFER'])->lockForUpdate()->get(['id', 'machine_id', 'type', 'occurred_at']));
        $now = now()->toDateTimeString();
        $days = [];
        foreach ($canonical->caseRows() as $case) {
            if (! $canonical->touchesWindow($case, $windowFrom, $windowTo)) {
                continue;
            }
            $row = (object) ['id' => null, 'machine_id' => $machineId, 'work_date' => $case->work_date,
                'machine_assignment_id' => $case->machine_assignment_id, 'daily_intervals' => null];
            $context = $timeline->context($machineId, $case->work_date.' 00:00:00', $case->work_date.' 23:59:59');
            if (AssignmentTimelineState::isUnassigned($context['timeline_context'])) {
                if ($case->machine_assignment_id === null) {
                    continue;
                }
                $target = (object) ['id' => null, 'time_in' => $case->work_date.' 00:00:00', 'time_out' => $case->work_date.' 23:59:59'];
                if ($reason = $canonical->reason($row, $target)) {
                    $result['canonical_review'][] = ['case_id' => $case->id, 'reason' => $reason] + $context;
                } else {
                    $canonical->plan($row, $target, $actor, $now);
                    $result['canonical_unassigned']++;
                }

                continue;
            }
            if (in_array($context['timeline_context'], ['INVALID_TIMELINE', 'LIFECYCLE_AMBIGUITY', 'LIFECYCLE_ASSIGNMENT_CONFLICT'], true)) {
                $result['canonical_review'][] = ['case_id' => $case->id, 'reason' => $context['timeline_context']] + $context;

                continue;
            }
            if (! isset($days[$case->work_date])) {
                $days[$case->work_date] = $assignments->filter(fn ($a) => AssignmentInterval::valid($a) && AssignmentInterval::onDate($a, $case->work_date))->sortBy('time_in')->values();
            }
            $dayCandidates = $days[$case->work_date];
            $candidates = $dayCandidates->filter(fn ($a) => AssignmentInterval::valid($a) && $canonical->reason($row, $a) === null)->values();
            // Adjacent segments are valid; evidence must never resolve true overlap.
            $overlap = false;
            $previousEnd = null;
            foreach ($dayCandidates as $a) {
                [$start, $end] = AssignmentInterval::segment($a, $case->work_date);
                if (! AssignmentInterval::valid($a) || ($previousEnd !== null && $start < $previousEnd)) {
                    $overlap = true;
                }
                $previousEnd = max($previousEnd ?? $end, $end);
            }
            if ($candidates->count() !== 1 || $overlap) {
                $context = $timeline->context($machineId, $case->work_date.' 00:00:00', $case->work_date.' 23:59:59');
                $result['canonical_review'][] = ['case_id' => $case->id, 'reason' => $dayCandidates->isEmpty()
                    ? ($context['timeline_context'] ?? 'NO_EFFECTIVE_ASSIGNMENT') : 'CANONICAL_CONFLICT'] + $context;

                continue;
            }
            $target = $candidates->first();
            if ((int) $target->id !== (int) $case->machine_assignment_id) {
                $canonical->plan($row, $target, $actor, $now);
            }
        }
        $canonical->flush($now);
        if ($result['protected_periods'] || $result['canonical_review'] || collect($result['periods'])->contains(fn ($p) => $p['unresolved'] > 0)) {
            ActivityLog::create(['user_id' => $actor, 'machine_id' => $machineId, 'event' => 'reconciliation.propagation_review',
                'description' => 'Các kỳ/canonical bị khóa hoặc không thể relink an toàn sau thay đổi lịch.',
                'properties' => ['from' => $from, 'to' => $to, 'review' => $result], 'occurred_at' => $now]);
        }

        return $result;
    }
}
