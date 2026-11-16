<?php

namespace App\Policies;

use App\Models\User;

/**
 * Accounting periods (months). Owner and Accountant may see, close and
 * reopen them (reopening needs a reason). Staff have no access.
 * Nothing closes or reopens a period automatically.
 */
class AccountingPeriodClosurePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function close(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function reopen(User $actor): bool
    {
        return $this->manages($actor);
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && ($actor->isOwner() || $actor->isAccountant());
    }
}
