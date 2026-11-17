<?php

namespace App\Policies;

use App\Models\ChargeReceipt;
use App\Models\User;

/**
 * Opening / downloading a document file. Same rule as viewing its charge
 * (SubscriptionChargePolicy::view): Owner and Accountant every document,
 * anyone else only documents of subscriptions they are responsible for.
 * Removed documents stay viewable for audit.
 *
 * Changes to documents are authorized on the charge
 * (SubscriptionChargePolicy::uploadReceipt / manageReceipts).
 */
class ChargeReceiptPolicy
{
    public function view(User $actor, ChargeReceipt $receipt): bool
    {
        return $actor->can('view', $receipt->charge);
    }
}
