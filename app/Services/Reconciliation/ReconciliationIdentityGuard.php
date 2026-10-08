<?php

namespace App\Services\Reconciliation;

class ReconciliationIdentityGuard
{
    public static function occupied(array $rows, int $assignment, string $start, string $end): bool
    {
        // Callers supply every existing row for this machine/day, including legacy
        // malformed segments. Hours cannot establish a second daily owner.
        return $rows !== [];
    }
}
