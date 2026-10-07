<?php

namespace App\Services\Reconciliation;

class ReconciliationIdentityGuard
{
    public static function occupied(array $rows, int $assignment, string $start, string $end): bool
    {
        foreach ($rows as $row) {
            if ((int) $row->machine_assignment_id === $assignment || ! $row->segment_start || ! $row->segment_end
                || AssignmentInterval::overlaps($start, $end, $row->segment_start, $row->segment_end)) {
                return true;
            }
        }

        return false;
    }
}
