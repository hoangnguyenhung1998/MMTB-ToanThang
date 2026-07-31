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

    public function update(User $user, ReconciliationRow $row): bool
    {
        return in_array($row->status, ['DRAFT', 'REJECTED', 'REVIEWED'], true);
    }

    public function review(User $user, ReconciliationRow $row): bool
    {
        return in_array($row->status, ['DRAFT', 'REJECTED'], true);
    }

    public function confirm(User $user, ReconciliationRow $row): bool
    {
        return $row->status === 'REVIEWED';
    }
}
