<?php

namespace App\Services\Reconciliation;

use Carbon\CarbonImmutable;

/** Business-day ownership, independent of immutable physical IN/OUT timestamps. */
class DayBasedAssignmentOwnership
{
    private array $history = [];

    private array $cache = [];

    private array $malformed = [];

    private AssignmentTimelineState $timeline;

    public function __construct(iterable $assignments, iterable $events = [])
    {
        $raw = [];
        foreach ($assignments as $assignment) {
            $a = (object) ($assignment instanceof \Illuminate\Database\Eloquent\Model ? $assignment->getAttributes() : (array) $assignment);
            if ($assignment instanceof \App\Models\MachineAssignment && $assignment->relationLoaded('bchResolution')) {
                $a->source_bch_id = $a->command_center_id ?? $assignment->bchResolution?->command_center_id;
            }
            foreach (['time_in', 'time_out'] as $field) {
                $stamp = $a->$field ?? null;
                if ($stamp === null && $field === 'time_out') {
                    continue;
                }
                if (! is_string($stamp) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/D', $stamp)) {
                    $this->malformed[$a->machine_id][] = $a->id;

                    continue 2;
                }
                $normalized = strlen($stamp) === 10 ? $stamp.' 00:00:00' : $stamp;
                try {
                    if (CarbonImmutable::parse($normalized)->format('Y-m-d H:i:s') !== $normalized) {
                        $this->malformed[$a->machine_id][] = $a->id;

                        continue 2;
                    }
                } catch (\Throwable) {
                    $this->malformed[$a->machine_id][] = $a->id;

                    continue 2;
                }
                $a->$field = $normalized;
            }
            $raw[] = $a;
            $this->history[$a->machine_id][] = $a;
        }
        foreach ($this->history as &$items) {
            usort($items, fn ($a, $b) => strcmp((string) $a->time_in, (string) $b->time_in) ?: ($a->id <=> $b->id));
        }
        $this->timeline = new AssignmentTimelineState($raw, $events);
    }

    public function resolve(int $machine, string $date): array
    {
        $key = $machine.'|'.$date;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $start = $date.' 00:00:00';
        $end = CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00';
        $context = $this->timeline->context($machine, $start, $end);
        $candidates = array_values(array_filter($this->history[$machine] ?? [], fn ($a) => AssignmentInterval::valid($a)
            && AssignmentInterval::onDate($a, $date)));
        $reason = $context['timeline_context'] === 'INVALID_TIMELINE' ? 'INVALID_TIMELINE' : null;
        if (isset($this->malformed[$machine])) {
            $reason = 'INVALID_TIMELINE';
            $context['malformed_assignment_ids'] = $this->malformed[$machine];
        }
        $overlaps = [];
        for ($i = 0; $i < count($candidates); $i++) {
            for ($j = $i + 1; $j < count($candidates); $j++) {
                if (AssignmentInterval::overlaps((string) $candidates[$i]->time_in, $candidates[$i]->time_out ?? '9999-12-31 23:59:59',
                    (string) $candidates[$j]->time_in, $candidates[$j]->time_out ?? '9999-12-31 23:59:59')) {
                    $overlaps[] = [$candidates[$i]->id, $candidates[$j]->id];
                }
            }
        }
        if ($overlaps) {
            $reason ??= 'TRUE_ASSIGNMENT_OVERLAP';
            $context['source_overlap_pairs'] = $overlaps;
        }
        $owner = $candidates ? $candidates[array_key_last($candidates)] : null;
        if ($owner && $reason === null) {
            $rangeStart = max($start, (string) $owner->time_in);
            $rangeEnd = min($end, $owner->time_out ?? $end);
            // A return at midnight still belongs to its old BCH for that business day.
            if ($rangeStart < $rangeEnd) {
                $sourceContext = $this->timeline->context($machine, $rangeStart, $rangeEnd);
                if (in_array($sourceContext['timeline_context'], ['LIFECYCLE_AMBIGUITY', 'LIFECYCLE_ASSIGNMENT_CONFLICT'], true)) {
                    $reason = $sourceContext['timeline_context'];
                    $context = $sourceContext;
                }
            }
            if ((property_exists($owner, 'source_bch_id') ? $owner->source_bch_id : ($owner->command_center_id ?? null)) === null) {
                $reason ??= 'NO_BCH_RESOLUTION';
            }
            if ((property_exists($owner, 'source_project_id') ? $owner->source_project_id : ($owner->project_id ?? null)) === null) {
                $reason ??= 'NO_PROJECT_RESOLUTION';
            }
        }
        if ($reason !== null) {
            $context['timeline_context'] = $reason;
            $owner = null;
        } elseif ($owner) {
            $context['timeline_context'] = null;
            $context['ownership_policy'] = 'BUSINESS_DAY';
            $owner = $this->project($owner);
        }

        return $this->cache[$key] = ['assignment' => $owner, 'reason' => $reason, 'context' => $context, 'candidates' => $candidates];
    }

    private function project(object $source): object
    {
        $owner = clone $source;
        $owner->physical_time_in = $source->time_in;
        $owner->physical_time_out = $source->time_out;
        $owner->time_in = substr((string) $source->time_in, 0, 10).' 00:00:00';
        $owner->time_out = $source->time_out ? CarbonImmutable::parse($source->time_out)->addDay()->startOfDay()->toDateTimeString() : null;
        foreach ($this->history[$source->machine_id] as $next) {
            if (AssignmentInterval::valid($next) && (string) $next->time_in > (string) $source->time_in) {
                $boundary = substr((string) $next->time_in, 0, 10).' 00:00:00';
                if ($owner->time_out === null || $boundary < $owner->time_out) {
                    $owner->time_out = $boundary;
                }
                break;
            }
        }
        $owner->ownership_policy = 'BUSINESS_DAY';

        return $owner;
    }
}
