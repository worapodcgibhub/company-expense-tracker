<?php

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase 3.1a: new tables + backfill from existing cards. Nothing here may
// change existing cards, subscriptions, charges or month totals.

/** Run the migration's backfill again on the current test data. */
function runPaymentMethodBackfill(): void
{
    $migration = require database_path('migrations/2026_11_20_000000_create_payment_methods_and_bank_accounts.php');
    $migration->backfill();
}

test('bank accounts keep only non-secret fields', function () {
    expect(Schema::getColumnListing('bank_accounts'))->toEqualCanonicalizing([
        'id', 'name', 'bank', 'account_name', 'last_four', 'kind', 'notes',
        'deactivated_at', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]);

    $account = BankAccount::factory()->create(['last_four' => '1234']);

    expect($account->maskedNumber())->toBe('••••1234')
        ->and(strlen($account->last_four))->toBe(4);
});

test('payment method types know which target they need and their default settlement', function () {
    expect(PaymentMethodType::Card->requiresCard())->toBeTrue()
        ->and(PaymentMethodType::BankTransfer->requiresBankAccount())->toBeTrue()
        ->and(PaymentMethodType::DirectDebit->requiresBankAccount())->toBeTrue()
        ->and(PaymentMethodType::Cash->requiresCard())->toBeFalse()
        ->and(PaymentMethodType::Cash->requiresBankAccount())->toBeFalse()
        ->and(PaymentMethodType::Card->defaultSettlement())->toBe(SettlementMode::Auto)
        ->and(PaymentMethodType::DirectDebit->defaultSettlement())->toBe(SettlementMode::Auto)
        ->and(PaymentMethodType::BankTransfer->defaultSettlement())->toBe(SettlementMode::Manual)
        ->and(PaymentMethodType::Cash->defaultSettlement())->toBe(SettlementMode::Manual);
});

test('payment methods link to the right target for each type', function () {
    $card = PaymentMethod::factory()->create();
    $transfer = PaymentMethod::factory()->forBankAccount()->create();
    $debit = PaymentMethod::factory()->forBankAccount(PaymentMethodType::DirectDebit)->create();
    $cash = PaymentMethod::factory()->cash()->create();

    expect($card->hasValidTarget())->toBeTrue()
        ->and($card->creditCard)->toBeInstanceOf(CreditCard::class)
        ->and($card->isAuto())->toBeTrue()
        ->and($transfer->hasValidTarget())->toBeTrue()
        ->and($transfer->bankAccount)->toBeInstanceOf(BankAccount::class)
        ->and($transfer->isAuto())->toBeFalse()
        ->and($debit->hasValidTarget())->toBeTrue()
        ->and($debit->isAuto())->toBeTrue()
        ->and($cash->hasValidTarget())->toBeTrue();

    $broken = PaymentMethod::factory()->cash()->make(['bank_account_id' => $transfer->bank_account_id]);
    expect($broken->hasValidTarget())->toBeFalse();
});

test('a card can have only one payment method', function () {
    $card = CreditCard::factory()->create();
    PaymentMethod::factory()->create(['credit_card_id' => $card->id]);

    PaymentMethod::factory()->create(['credit_card_id' => $card->id]);
})->throws(QueryException::class);

test('a payment method in use cannot be deleted from its card or bank account', function () {
    $method = PaymentMethod::factory()->create();

    DB::table('credit_cards')->where('id', $method->credit_card_id)->delete();
})->throws(QueryException::class);

test('backfill gives every existing card a card payment method and links subscriptions and charges', function () {
    $active = CreditCard::factory()->create();
    $inactive = CreditCard::factory()->deactivated()->create();

    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['credit_card_id' => $active->id]);
    $charge = SubscriptionCharge::factory()->for($subscription)->create();
    $cardsBefore = DB::table('credit_cards')->get()->all();
    $subsBefore = DB::table('subscriptions')->first();

    expect(DB::table('payment_methods')->count())->toBe(0)
        ->and($subscription->fresh()->payment_method_id)->toBeNull();

    runPaymentMethodBackfill();

    $activeMethod = $active->paymentMethod()->first();
    $inactiveMethod = $inactive->paymentMethod()->first();

    expect(PaymentMethod::count())->toBe(2)
        ->and($activeMethod->type)->toBe(PaymentMethodType::Card)
        ->and($activeMethod->settlement)->toBe(SettlementMode::Auto)
        ->and($activeMethod->isActive())->toBeTrue()
        ->and($inactiveMethod->isActive())->toBeFalse()
        ->and(DB::table('subscriptions')->where('id', $subscription->id)->value('payment_method_id'))->toBe($activeMethod->id)
        ->and(DB::table('subscription_charges')->where('id', $charge->id)->value('payment_method_id'))->toBe($activeMethod->id);

    // Existing data is untouched: same cards, same credit_card_id, same other columns.
    expect(DB::table('credit_cards')->get()->all())->toEqual($cardsBefore);
    expect(Arr::except((array) DB::table('subscriptions')->first(), 'payment_method_id'))
        ->toEqual(Arr::except((array) $subsBefore, 'payment_method_id'));
});

test('backfill is safe to run twice', function () {
    $subscription = Subscription::factory()->create();

    runPaymentMethodBackfill();
    $firstId = $subscription->fresh()->payment_method_id;
    runPaymentMethodBackfill();

    expect(PaymentMethod::count())->toBe(1)
        ->and($subscription->fresh()->payment_method_id)->toBe($firstId);
});

test('backfill does not overwrite a payment method that was already chosen', function () {
    $subscription = Subscription::factory()->create();
    $cash = PaymentMethod::factory()->cash()->create();
    DB::table('subscriptions')->where('id', $subscription->id)->update(['payment_method_id' => $cash->id]);

    runPaymentMethodBackfill();

    expect($subscription->fresh()->payment_method_id)->toBe($cash->id);
});

test('adding payment methods does not change month totals', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();
    SubscriptionCharge::factory()->for($subscription)->create();

    $before = ChargeOverview::snapshotFor('2026-11');
    runPaymentMethodBackfill();

    expect(ChargeOverview::snapshotFor('2026-11'))->toEqual($before);
});

test('credit card columns and the old credit_card_id links are unchanged', function () {
    expect(Schema::hasColumns('credit_cards', ['name', 'issuer', 'network', 'kind', 'last_four', 'holder_name']))->toBeTrue()
        ->and(Schema::hasColumn('subscriptions', 'credit_card_id'))->toBeTrue()
        ->and(Schema::hasColumn('subscription_charges', 'credit_card_id'))->toBeTrue()
        ->and(Schema::hasColumn('subscriptions', 'payment_method_id'))->toBeTrue()
        ->and(Schema::hasColumn('subscription_charges', 'payment_method_id'))->toBeTrue();
});
