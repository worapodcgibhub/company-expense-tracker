<?php

namespace App\Models\Concerns;

use App\Exceptions\PaymentMethodMismatchException;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3.1b: keeps `payment_method_id` in step with the old `credit_card_id`
 * until the forms choose a payment method themselves (Phase 3.1e).
 *
 * On every save of a model that has a card:
 * - no payment method yet, or the card was changed without choosing a method
 *   -> take the EXISTING method of that card. It never creates a method and
 *   never guesses: no method for the card -> PaymentMethodMismatchException.
 * - a method is given together with the card -> it must be that card's
 *   method, otherwise PaymentMethodMismatchException.
 * - a value that is already set and not touched is never overwritten.
 * - no card (cash / transfer rows, later phases) -> nothing to derive.
 *
 * Models using it must have `credit_card_id` and `payment_method_id`.
 *
 * @mixin Model
 */
trait ResolvesPaymentMethod
{
    protected static function bootResolvesPaymentMethod(): void
    {
        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            $model->syncPaymentMethodWithCard();
        });
    }

    protected function syncPaymentMethodWithCard(): void
    {
        $cardId = $this->getAttribute('credit_card_id');

        if ($cardId === null) {
            return;
        }

        $methodId = $this->getAttribute('payment_method_id');
        $cardChanged = ! $this->exists || $this->isDirty('credit_card_id');
        $methodChanged = $this->isDirty('payment_method_id');

        if ($methodId === null || ($this->exists && $cardChanged && ! $methodChanged)) {
            $found = PaymentMethod::query()->where('credit_card_id', $cardId)->value('id');

            if ($found === null) {
                throw new PaymentMethodMismatchException("ไม่พบช่องทางชำระเงินของบัตร (รหัส {$cardId}) กรุณาตั้งค่าช่องทางชำระเงินของบัตรนี้ก่อน");
            }

            $this->setAttribute('payment_method_id', $found);

            return;
        }

        if (($cardChanged || $methodChanged)
            && ! PaymentMethod::query()->whereKey($methodId)->where('credit_card_id', $cardId)->exists()) {
            throw new PaymentMethodMismatchException("ช่องทางชำระเงิน (รหัส {$methodId}) ไม่ตรงกับบัตร (รหัส {$cardId})");
        }
    }
}
