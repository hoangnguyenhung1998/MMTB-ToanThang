<?php

namespace App\Policies;

use App\Models\ReconciliationPeriod;
use App\Models\User;

class ReconciliationPeriodPolicy
{
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
}
