<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * One-off expenses.
 *
 * - Owner and Accountant: add, view, and change any expense whose
 *   accounting month is still open.
 * - Staff: add expenses for themselves; view and change only their own,
 *   while its accounting month is not closed.
 * - Nobody changes an expense whose month is closed. (Subscription charges
 *   let the Owner enter late with a confirmation and a flag; that is not
 *   copied here until it is decided.)
 * - There is deliberately no delete.
 *
 * Every ability also requires the acting account itself to be active.
 * Listings and totals must also use Expense::visibleTo().
 * Checking that a new expense belongs to the person adding it, and that its
 * date is not in a closed month, is the job of the form (later phase).
 */
class ExpensePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive();
    }

    public function view(User $actor, Expense $expense): bool
    {
        return $actor->isActive()
            && ($actor->isOwner() || $actor->isAccountant() || $expense->isOwnedBy($actor));
    }

    public function create(User $actor): bool
    {
        return $actor->isActive();
    }

    public function update(User $actor, Expense $expense): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        $mayEdit = $actor->isOwner() || $actor->isAccountant() || $expense->isOwnedBy($actor);

        return $mayEdit && ! $expense->isInClosedPeriod();
    }
}
