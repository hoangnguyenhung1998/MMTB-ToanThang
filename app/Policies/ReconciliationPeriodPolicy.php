<?php

namespace App\Policies;

use App\Models\ReconciliationPeriod;
use App\Models\User;

class ReconciliationPeriodPolicy
{
    public function appendMachines(User $user, ReconciliationPeriod $period): bool
    {
        return $period->type === 'MONTHLY' && in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReconciliationPeriod $period): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function generate(User $user, ReconciliationPeriod $period): bool
    {
        return in_array($period->status, ['DRAFT', 'GENERATED'], true);
    }

    public function startReview(User $user, ReconciliationPeriod $period): bool
    {
        return $period->status === 'GENERATED';
    }

    public function allocateTimes(User $user, ReconciliationPeriod $period): bool
    {
        return in_array($period->status, ['GENERATED', 'REVIEWING'], true);
    }

    public function delete(User $user, ReconciliationPeriod $period): bool
    {
        return $period->status === 'DRAFT';
    }

    public function confirm(User $user, ReconciliationPeriod $period): bool
    {
        return $period->status === 'REVIEWING';
    }

    public function lock(User $user, ReconciliationPeriod $period): bool
    {
        return $period->status === 'CONFIRMED';
    }

    public function export(User $user, ReconciliationPeriod $period): bool
    {
        return in_array($period->status, ['GENERATED', 'REVIEWING', 'CONFIRMED', 'EXPORTED'], true);
    }
}
