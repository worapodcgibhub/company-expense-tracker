<?php

namespace App\Policies;

use App\Models\CreditCard;
use App\Models\User;

/**
 * Company payment cards.
 *
 * - Owner: view, add, edit, deactivate and reactivate.
 * - Accountant: view the list only.
 * - Staff: no access.
 *
 * Every ability also requires the acting account itself to be active.
 * There is deliberately no delete: cards are deactivated instead, so
 * subscriptions and charge history (Phase 2.3+) keep pointing at them.
 */
class CreditCardPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && ($actor->isOwner() || $actor->isAccountant());
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User $actor, CreditCard $card): bool
    {
        return $this->manages($actor);
    }

    public function deactivate(User $actor, CreditCard $card): bool
    {
        return $this->manages($actor) && $card->isActive();
    }

    public function reactivate(User $actor, CreditCard $card): bool
    {
        return $this->manages($actor) && ! $card->isActive();
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && $actor->isOwner();
    }
}
