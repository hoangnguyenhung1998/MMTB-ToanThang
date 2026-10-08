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
            $seen = false;
            foreach ($items as $assignment) {
                if (! AssignmentInterval::valid($assignment)) {
                    $a = (string) $assignment->time_in;
                    $b = (string) $assignment->time_out;
                    // Empty legacy boundary records have no duration or coverage.
                    if ($a !== '' && $a === $b) {
                        continue;
                    }
                    $this->invalid[$machine][] = ['start' => $a === '' ? '' : min($a, $b),
                        'end' => $a === '' ? '9999-12-31 23:59:59' : \Carbon\Carbon::parse(max($a, $b))->addSecond()->toDateTimeString(),
                        'assignment_id' => $assignment->id, 'time_in' => $assignment->time_in,
                        'time_out' => $assignment->time_out,
                        'issue' => $a === '' ? 'MISSING_START' : 'REVERSED_INTERVAL'];

                    continue;
                }
                $start = (string) $assignment->time_in;
                if (! $seen) {
                    $this->gaps[$machine][] = ['start' => '', 'end' => $start, 'state' => 'BEFORE_FIRST_HANDOVER'];
                } elseif ($end !== null && $end < $start) {
                    $this->gaps[$machine][] = ['start' => $end, 'end' => $start, 'state' => 'LEGITIMATE_UNASSIGNED_GAP'];
                }
                // Union of coverage: an overlapping/containing assignment cannot manufacture a gap.
                if (! $seen || $end !== null) {
                    $end = $assignment->time_out === null ? null : max($end ?? '', (string) $assignment->time_out);
                }
                $seen = true;
            }
            if ($end !== null) {
                $this->gaps[$machine][] = ['start' => $end, 'end' => '9999-12-31 23:59:59', 'state' => 'AFTER_LAST_ASSIGNMENT'];
            }
        }
        foreach ($this->invalid as $machine => $issues) {
            usort($issues, fn ($a, $b) => strcmp($a['start'], $b['start']));
            $blocks = [];
            foreach ($issues as $issue) {
                $last = count($blocks) - 1;
                if ($last >= 0 && $issue['start'] <= $blocks[$last]['end']) {
                    $blocks[$last]['end'] = max($blocks[$last]['end'], $issue['end']);
                    $blocks[$last]['issues'][] = $issue;
                } else {
                    $blocks[] = ['start' => $issue['start'], 'end' => $issue['end'], 'issues' => [$issue]];
                }
            }
            $this->invalid[$machine] = $blocks;
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
        // Scope corruption to the affected interval, rather than poisoning every historical period.
        $blocks = $this->invalid[$machine] ?? [];
        $index = max(0, $this->lastAtOrBefore($blocks, $start, fn ($block) => $block['start']));
        $issues = [];
        for (; isset($blocks[$index]) && $blocks[$index]['start'] < $end; $index++) {
            if ($blocks[$index]['end'] > $start) {
                foreach ($blocks[$index]['issues'] as $issue) {
                    if ($issue['start'] < $end && $start < $issue['end']) {
                        $issues[] = $issue;
                    }
                }
            }
        }
        if ($issues) {
            $context['timeline_context'] = 'INVALID_TIMELINE';

            return $context + ['assignment_issues' => $issues];
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
        $context['last_lifecycle_event_id'] = $last->id ?? null;
        $context['last_lifecycle_event'] = $last?->type;
        $context['last_lifecycle_at'] = $last?->occurred_at;
        if ($context['timeline_context'] === null && $last?->type === 'RETURN') {
            $context['timeline_context'] = 'LIFECYCLE_ASSIGNMENT_CONFLICT';
        }
        if ($context['timeline_context'] !== null) {
            $boundary = null;
            for ($next = $index + 1; isset($events[$next]) && $events[$next]->occurred_at < $end; $next++) {
                // RETURN does not assign a BCH. Proven uncovered time on both sides remains unassigned.
                if ($events[$next]->type === 'RETURN' && self::isUnassigned($context['timeline_context'])) {
                    $context['crossed_return_event_ids'][] = $events[$next]->id ?? null;

                    continue;
                }
                $boundary = $events[$next];
                break;
            }
            if ($boundary) {
                $context['timeline_context'] = 'LIFECYCLE_AMBIGUITY';
                $context['next_lifecycle_event_id'] = $boundary->id ?? null;
                $context['next_lifecycle_at'] = $boundary->occurred_at;
            } elseif ($last?->type === 'RETURN' && $context['timeline_context'] !== 'LIFECYCLE_ASSIGNMENT_CONFLICT') {
                $context['timeline_context'] = 'AFTER_RETURN';
            } elseif ($context['timeline_context'] === 'LEGITIMATE_UNASSIGNED_GAP' && $last !== null && $last->occurred_at >= $gaps[$gapIndex]['start']) {
                $context['timeline_context'] = 'LIFECYCLE_AMBIGUITY';
            }
        }

        return $context;
    }

    public static function isUnassigned(?string $state): bool
    {
        return in_array($state, ['LEGITIMATE_UNASSIGNED_GAP', 'AFTER_RETURN', 'BEFORE_FIRST_HANDOVER', 'AFTER_LAST_ASSIGNMENT'], true);
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
