<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;

/**
 * Actual charges.
 *
 * - Owner: record, correct (with history) and view every charge.
 * - Accountant: view every charge; verify / take back verification.
 * - Staff: view only charges of subscriptions they are responsible for.
 *   No recording or changes.
 *
 * Every ability also requires the acting account itself to be active.
 * Listings and totals must also use SubscriptionCharge::visibleTo().
 * There is deliberately no delete.
 */
class SubscriptionChargePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive();
    }

    public function view(User $actor, SubscriptionCharge $charge): bool
    {
        return $actor->isActive()
            && ($actor->isOwner() || $actor->isAccountant() || $charge->subscription->responsible_user_id === $actor->id);
    }

    /**
     * Record the current cycle of $subscription (it must be active).
     */
    public function create(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor) && $subscription->isActive();
    }

    public function update(User $actor, SubscriptionCharge $charge): bool
    {
        return $this->manages($actor);
    }

    public function verify(User $actor, SubscriptionCharge $charge): bool
    {
        return $actor->isActive() && $actor->isAccountant() && ! $charge->isVerified();
    }

    public function unverify(User $actor, SubscriptionCharge $charge): bool
    {
        return $actor->isActive() && $actor->isAccountant() && $charge->isVerified();
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && $actor->isOwner();
    }
}
