<?php

use App\Enums\ChargeOutcome;
use App\Enums\PaymentMethodType;
use App\Exceptions\PaymentMethodMismatchException;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ReceiptStorage;
use Carbon\CarbonImmutable;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Phase 3.1b hook: payment_method_id follows the card's EXISTING payment
// method. It never creates a method, never guesses and never overwrites a
// value that is already set.

/** A subscription saved without the factory's help, so only the hook acts. */
function saveRawSubscription(array $overrides = []): Subscription
{
    $subscription = new Subscription;
    $subscription->forceFill(Subscription::factory()->raw($overrides))->save();

    return $subscription;
}

test('a new subscription takes the payment method of its card', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->create();
    $method = PaymentMethod::factory()->create(['credit_card_id' => $card->id]);

    $subscription = saveRawSubscription(['credit_card_id' => $card->id]);

    expect($subscription->fresh()->payment_method_id)->toBe($method->id)
        ->and($subscription->paymentMethod->is($method))->toBeTrue();
});

test('a new charge takes the payment method of its card', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    $charge = SubscriptionCharge::factory()->for($subscription)->create();

    expect($charge->fresh()->payment_method_id)->toBe($subscription->fresh()->payment_method_id)
        ->and($charge->fresh()->payment_method_id)->toBe($subscription->creditCard->paymentMethod->id);
});

test('a card with no payment method is reported clearly and nothing is created', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->create();

    expect(fn () => saveRawSubscription(['credit_card_id' => $card->id]))
        ->toThrow(PaymentMethodMismatchException::class, 'ไม่พบช่องทางชำระเงินของบัตร');

    expect(Subscription::count())->toBe(0)
        ->and(PaymentMethod::count())->toBe(0);
});

test('a value that is already set is not overwritten by later saves', function () {
    $subscription = Subscription::factory()->create();
    $original = $subscription->fresh()->payment_method_id;

    $subscription->forceFill(['notes' => 'เปลี่ยนหมายเหตุ'])->save();

    expect($subscription->fresh()->payment_method_id)->toBe($original);
});

test('changing the card moves the payment method to the new card', function () {
    $subscription = Subscription::factory()->create();
    $other = CreditCard::factory()->create();

    $subscription->forceFill(['credit_card_id' => $other->id])->save();

    expect($subscription->fresh()->payment_method_id)->toBe($other->paymentMethod->id);

    $charge = SubscriptionCharge::factory()->for($subscription)->create();
    $third = CreditCard::factory()->create();
    $charge->forceFill(['credit_card_id' => $third->id])->save();

    expect($charge->fresh()->payment_method_id)->toBe($third->paymentMethod->id);
});

test('changing to a card without a payment method fails and keeps the old values', function () {
    $subscription = Subscription::factory()->create();
    $before = DB::table('subscriptions')->where('id', $subscription->id)->first();
    $bare = CreditCard::factory()->withoutPaymentMethod()->create();

    expect(fn () => $subscription->forceFill(['credit_card_id' => $bare->id])->save())
        ->toThrow(PaymentMethodMismatchException::class);

    expect(DB::table('subscriptions')->where('id', $subscription->id)->first())->toEqual($before);
});

test('a payment method that belongs to another card is rejected', function () {
    $subscription = Subscription::factory()->create();
    $foreign = PaymentMethod::factory()->forBankAccount()->create();

    expect(fn () => $subscription->forceFill(['payment_method_id' => $foreign->id])->save())
        ->toThrow(PaymentMethodMismatchException::class, 'ไม่ตรงกับบัตร');

    $otherCardMethod = CreditCard::factory()->create()->paymentMethod;
    expect(fn () => $subscription->forceFill(['payment_method_id' => $otherCardMethod->id])->save())
        ->toThrow(PaymentMethodMismatchException::class);
});

test('a row without a card (cash) can be saved with its own payment method', function () {
    $cash = PaymentMethod::factory()->cash()->create();

    $subscription = saveRawSubscription(['credit_card_id' => null, 'payment_method_id' => $cash->id]);
    $charge = new SubscriptionCharge;
    $charge->forceFill(SubscriptionCharge::factory()->raw([
        'subscription_id' => $subscription->id,
        'credit_card_id' => null,
        'payment_method_id' => $cash->id,
    ]))->save();

    expect($subscription->fresh()->credit_card_id)->toBeNull()
        ->and($subscription->fresh()->payment_method_id)->toBe($cash->id)
        ->and($charge->fresh()->credit_card_id)->toBeNull()
        ->and($charge->fresh()->payment_method_id)->toBe($cash->id)
        ->and($cash->type)->toBe(PaymentMethodType::Cash);
});

test('recording a charge through the subscription keeps card and payment method in step', function () {
    $owner = User::factory()->owner()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::now()->startOfDay())->create();

    $charge = $subscription->recordCharge(CarbonImmutable::now()->startOfDay(), [
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::now(),
        'amount' => 59_000,
        'amount_thb' => null,
        'credit_card_id' => $subscription->credit_card_id,
        'notes' => null,
    ], $owner);

    expect($charge->fresh()->payment_method_id)->toBe($subscription->fresh()->payment_method_id);
});

test('the sample data seeder still works and links every row to a payment method', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    Storage::fake(ReceiptStorage::DISK);
    User::factory()->owner()->create();

    // No card at all: the seeder creates its own sample card and its method.
    $this->seed(SampleDataSeeder::class);

    expect(Subscription::count())->toBeGreaterThan(0)
        ->and(SubscriptionCharge::count())->toBeGreaterThan(0)
        ->and(Subscription::whereNull('payment_method_id')->count())->toBe(0)
        ->and(SubscriptionCharge::whereNull('payment_method_id')->count())->toBe(0)
        ->and(CreditCard::count())->toBe(1)
        ->and(PaymentMethod::count())->toBe(1);
});
