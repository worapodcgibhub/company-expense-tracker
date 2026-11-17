<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Payment methods (same rules as CreditCardPolicy and BankAccountPolicy).
 *
 * - Owner: view, add, edit, deactivate and reactivate.
 * - Accountant: view the list only.
 * - Staff: no access.
 *
 * Every ability also requires the acting account itself to be active.
 * There is deliberately no delete: methods are deactivated instead, so the
 * subscriptions and charges that point at them keep their history.
 */
class PaymentMethodPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && ($actor->isOwner() || $actor->isAccountant());
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User $actor, PaymentMethod $method): bool
    {
        return $this->manages($actor);
    }

    public function deactivate(User $actor, PaymentMethod $method): bool
    {
        return $this->manages($actor) && $method->isActive();
    }

    public function reactivate(User $actor, PaymentMethod $method): bool
    {
        return $this->manages($actor) && ! $method->isActive();
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && $actor->isOwner();
    }
}
