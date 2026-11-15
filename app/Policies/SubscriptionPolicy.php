<?php

namespace App\Policies;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;

/**
 * Subscriptions.
 *
 * - Owner: everything (add, edit, pause, resume, cancel, reactivate).
 * - Accountant: view every subscription; no changes in this phase.
 * - Staff: view only the subscriptions they are responsible for, and edit
 *   only their notes — never amount, card, cycle, dates or status.
 *
 * Every ability also requires the acting account itself to be active.
 * Listings must also use Subscription::visibleTo() so the same rule is
 * applied in the database query. There is deliberately no delete.
 */
class SubscriptionPolicy
{
    /**
     * The list page. Staff can open it too; it then shows only their own.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isActive();
    }

    public function view(User $actor, Subscription $subscription): bool
    {
        return $actor->isActive()
            && ($actor->isOwner() || $actor->isAccountant() || $this->isResponsible($actor, $subscription));
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    /**
     * Full edit (amount, card, cycle, dates, ...).
     */
    public function update(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor);
    }

    /**
     * Notes only: the Owner, or the Staff member responsible for it.
     */
    public function updateNotes(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor)
            || ($actor->isActive() && $actor->isStaff() && $this->isResponsible($actor, $subscription));
    }

    public function pause(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor) && $subscription->canChangeTo(SubscriptionStatus::Paused);
    }

    public function resume(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor) && $subscription->status === SubscriptionStatus::Paused;
    }

    public function cancel(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor) && $subscription->canChangeTo(SubscriptionStatus::Cancelled);
    }

    public function reactivate(User $actor, Subscription $subscription): bool
    {
        return $this->manages($actor) && $subscription->isCancelled();
    }

    private function manages(User $actor): bool
    {
        return $actor->isActive() && $actor->isOwner();
    }

    private function isResponsible(User $actor, Subscription $subscription): bool
    {
        return $subscription->responsible_user_id === $actor->id;
    }
}
