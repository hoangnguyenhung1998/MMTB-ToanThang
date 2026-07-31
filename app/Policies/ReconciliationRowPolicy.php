<?php

namespace App\Policies;

use App\Models\ReconciliationRow;
use App\Models\User;

class ReconciliationRowPolicy
{
    public function view(User $user, ReconciliationRow $row): bool
    {
        return true;
    }
}
