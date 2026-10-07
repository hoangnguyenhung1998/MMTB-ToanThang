<?php

namespace App\Services\Reconciliation;

/** Shared date/segment semantics; raw snapshots and Eloquent casts are supported. */
class AssignmentInterval
{
    public static function stamp(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public static function valid(object $assignment): bool
    {
        $start = self::stamp($assignment->time_in);
        $end = self::stamp($assignment->time_out);

        return $start !== null && ($end === null || $start < $end);
    }

    public static function onDate(object $assignment, string $date): bool
    {
        return substr(self::stamp($assignment->time_in) ?? '', 0, 10) <= $date
            && (! $assignment->time_out || substr(self::stamp($assignment->time_out), 0, 10) >= $date);
    }

    public static function segment(object $assignment, string $date): array
    {
        $start = self::stamp($assignment->time_in);
        $end = self::stamp($assignment->time_out);

        return [substr($start ?? '', 0, 10) === $date ? substr($start, 11, 8) : '00:00:00',
            $end && substr($end, 0, 10) === $date ? substr($end, 11, 8) : '23:59:59'];
    }

    public static function contains(object $assignment, object $row, string $date): bool
    {
        [$start, $end] = self::segment($assignment, $date);

        return self::valid($assignment) && self::onDate($assignment, $date)
            && $row->segment_start && $row->segment_end && $row->segment_start < $row->segment_end
            && $row->segment_start >= $start && $row->segment_end <= $end;
    }

    public static function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return $start < $end && $otherStart < $otherEnd && $start < $otherEnd && $otherStart < $end;
    }
}
