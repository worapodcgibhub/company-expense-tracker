<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;

/**
 * Company bank accounts (same rules as CreditCardPolicy).
 *
 * - Owner: view, add, edit, deactivate and reactivate.
 * - Accountant: view the list only.
 * - Staff: no access.
 *
 * Every ability also requires the acting account itself to be active.
 * There is deliberately no delete: accounts are deactivated instead, so
 * payment methods and the expense history behind them keep pointing at them.
 */
class BankAccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && ($actor->isOwner() || $actor->isAccountant());
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User $actor, BankAccount $account): bool
    {
        return $this->manages($actor);
    }

    public function deactivate(User $actor, BankAccount $account): bool
    {
        return $this->manages($actor) && $account->isActive();
    }

    public function reactivate(User $actor, BankAccount $account): bool
    {
        return $this->manages($actor) && ! $account->isActive();
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && $actor->isOwner();
    }
}
