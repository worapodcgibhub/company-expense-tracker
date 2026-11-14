<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only an active Owner manages user accounts.
 *
 * Invariant: an Owner can never change their own role or deactivate
 * themselves, and every action here requires an active Owner. Together this
 * guarantees there is always at least one active Owner left.
 *
 * Users are never deleted from the app (their history must be kept for
 * accounting); they are deactivated instead.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isOwner() && $actor->isActive();
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->viewAny($actor);
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && ! $actor->is($target);
    }

    /**
     * Owners change their own password in Settings → Security instead.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && ! $actor->is($target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && ! $actor->is($target) && $target->isActive();
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && ! $target->isActive();
    }
}
