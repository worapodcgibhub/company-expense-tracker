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
 * - a value that is already set and not touched is never overwritten (and
 *   not re-checked, so old rows that point at a method switched off since
 *   can still be saved).
 * - no card (cash / transfer rows, later phases) -> nothing to derive.
 *
 * Phase 3.1d: models that return true from rejectsUnusablePaymentMethod()
 * (subscriptions) may not be given a method that has been deactivated.
 * Charges do not: a charge records what really happened, so it may carry a
 * method that was switched off later.
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

    /**
     * Whether a new choice of payment method must be usable (see isUsable()).
     */
    protected function rejectsUnusablePaymentMethod(): bool
    {
        return false;
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
            $method = PaymentMethod::query()->where('credit_card_id', $cardId)->first();

            if ($method === null) {
                throw new PaymentMethodMismatchException("ไม่พบช่องทางชำระเงินของบัตร (รหัส {$cardId}) กรุณาตั้งค่าช่องทางชำระเงินของบัตรนี้ก่อน");
            }

            $this->assertUsable($method);
            $this->setAttribute('payment_method_id', $method->id);

            return;
        }

        if ($cardChanged || $methodChanged) {
            $method = PaymentMethod::query()->whereKey($methodId)->where('credit_card_id', $cardId)->first();

            if ($method === null) {
                throw new PaymentMethodMismatchException("ช่องทางชำระเงิน (รหัส {$methodId}) ไม่ตรงกับบัตร (รหัส {$cardId})");
            }

            $this->assertUsable($method);
        }
    }

    private function assertUsable(PaymentMethod $method): void
    {
        if ($this->rejectsUnusablePaymentMethod() && ! $method->isActive()) {
            throw new PaymentMethodMismatchException("ช่องทางชำระเงิน \"{$method->name}\" ถูกปิดใช้งาน เลือกใช้กับรายการใหม่ไม่ได้");
        }
    }
}
