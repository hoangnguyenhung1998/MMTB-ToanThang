<?php

namespace App\Services\Reconciliation;

/** Read-only, cross-period gap index. Positive assignment ranges are never filled. */
class AssignmentTimelineState
{
    private array $gaps = [];

    private array $invalid = [];

    private array $events = [];

    public function __construct(iterable $assignments, iterable $events = [])
    {
        $history = [];
        foreach ($assignments as $assignment) {
            $history[$assignment->machine_id][] = $assignment;
        }
        foreach ($history as $machine => $items) {
            usort($items, fn ($a, $b) => strcmp((string) $a->time_in, (string) $b->time_in));
            $end = null;
            foreach ($items as $i => $assignment) {
                if (! AssignmentInterval::valid($assignment)) {
                    $this->invalid[$machine] = true;

                    continue;
                }
                $start = (string) $assignment->time_in;
                if ($i === 0) {
                    $this->gaps[$machine][] = ['start' => '', 'end' => $start, 'state' => 'BEFORE_FIRST_HANDOVER'];
                } elseif ($end !== null && $end < $start) {
                    $this->gaps[$machine][] = ['start' => $end, 'end' => $start, 'state' => 'LEGITIMATE_UNASSIGNED_GAP'];
                }
                // Union of coverage: an overlapping/containing assignment cannot manufacture a gap.
                if ($i === 0 || $end !== null) {
                    $end = $assignment->time_out === null ? null : max($end ?? '', (string) $assignment->time_out);
                }
            }
            if ($end !== null) {
                $this->gaps[$machine][] = ['start' => $end, 'end' => '9999-12-31 23:59:59', 'state' => 'AFTER_LAST_ASSIGNMENT'];
            }
        }
        foreach ($events as $event) {
            if (in_array($event->type, ['RETURN', 'HANDOVER', 'TRANSFER'], true)) {
                $this->events[$event->machine_id][] = $event;
            }
        }
        foreach ($this->events as &$items) {
            usort($items, fn ($a, $b) => strcmp((string) $a->occurred_at, (string) $b->occurred_at));
        }
    }

    /** Null means the range intersects assignment coverage; existing effective-target rules apply. */
    public function context(int $machine, string $start, string $end): array
    {
        $context = ['timeline_context' => null, 'last_lifecycle_event' => null, 'last_lifecycle_at' => null];
        if ($start >= $end) {
            return $context + ['invalid_segment' => true];
        }
        if (isset($this->invalid[$machine])) {
            $context['timeline_context'] = 'INVALID_TIMELINE';

            return $context;
        }
        $gaps = $this->gaps[$machine] ?? [];
        $gapIndex = $this->lastAtOrBefore($gaps, $start, fn ($gap) => $gap['start']);
        if (! $gaps) {
            $context['timeline_context'] = 'ASSIGNMENT_HISTORY_MISSING';
        } elseif ($gapIndex >= 0 && $end <= $gaps[$gapIndex]['end']) {
            $context['timeline_context'] = $gaps[$gapIndex]['state'];
        }
        $events = $this->events[$machine] ?? [];
        $index = $this->lastAtOrBefore($events, $start, fn ($event) => (string) $event->occurred_at);
        $last = $events[$index] ?? null;
        $context['last_lifecycle_event'] = $last?->type;
        $context['last_lifecycle_at'] = $last?->occurred_at;
        if ($context['timeline_context'] !== null) {
            if (isset($events[$index + 1]) && $events[$index + 1]->occurred_at < $end) {
                $context['timeline_context'] = 'LIFECYCLE_AMBIGUITY';
            } elseif ($last?->type === 'RETURN') {
                $context['timeline_context'] = 'AFTER_RETURN';
            } elseif ($context['timeline_context'] === 'LEGITIMATE_UNASSIGNED_GAP' && $last !== null && $last->occurred_at >= $gaps[$gapIndex]['start']) {
                $context['timeline_context'] = 'LIFECYCLE_AMBIGUITY';
            }
        }

        return $context;
    }

    private function lastAtOrBefore(array $items, string $stamp, callable $key): int
    {
        $low = 0;
        $high = count($items) - 1;
        $found = -1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($key($items[$mid]) <= $stamp) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found;
    }
}
