<?php

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Phase 3.1e-1: the subscription form chooses a payment method (card, bank
 * transfer / PromptPay / QR, direct debit) instead of a card.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

/**
 * @param  array<string, string>  $overrides
 */
function channelForm(?Subscription $subscription = null, ?User $as = null): Testable
{
    return Livewire::actingAs($as ?? test()->owner)
        ->test('pages::subscriptions.form', $subscription ? ['subscription' => $subscription] : []);
}

/**
 * @param  array<string, string>  $overrides
 */
function fillChannelForm(Testable $component, PaymentMethod $method, array $overrides = []): Testable
{
    $values = array_merge([
        'name' => 'ค่าบริการทดสอบ',
        'vendor' => 'ผู้ให้บริการทดสอบ',
        'category' => 'cloud_hosting',
        'responsible_user_id' => (string) test()->owner->id,
        'payment_method_id' => (string) $method->id,
        'billing_cycle' => 'monthly',
        'next_charge_date' => '2026-11-20',
        'currency' => 'THB',
        'amount' => '8,500',
        'remind_days_before' => '3',
        'notes' => '',
    ], $overrides);

    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

function transferMethod(string $name = 'โอนเงินผ่านธนาคาร KBank', PaymentMethodType $type = PaymentMethodType::BankTransfer, array $account = []): PaymentMethod
{
    return PaymentMethod::factory()->forBankAccount($type)->create([
        'name' => $name,
        'bank_account_id' => BankAccount::factory()->create($account)->id,
    ]);
}

function cardMethod(): PaymentMethod
{
    return CreditCard::factory()->create()->paymentMethod;
}

/*
|--------------------------------------------------------------------------
| Creating with each channel
|--------------------------------------------------------------------------
*/

test('a subscription can be created with each kind of channel', function (string $kind) {
    $method = match ($kind) {
        'card' => cardMethod(),
        'bank_transfer' => transferMethod(),
        'promptpay' => transferMethod('PromptPay / QR ผ่านบัญชี SCB', PaymentMethodType::BankTransfer),
        'direct_debit' => transferMethod('หักบัญชีอัตโนมัติ KBank', PaymentMethodType::DirectDebit),
    };

    fillChannelForm(channelForm(), $method)->call('save')->assertHasNoErrors();

    $subscription = Subscription::sole();

    expect($subscription->payment_method_id)->toBe($method->id)
        ->and($subscription->credit_card_id)->toBe($method->credit_card_id)
        ->and($subscription->paymentMethod->settlement)->toBe($method->settlement)
        ->and($subscription->charges()->count())->toBe(0);
})->with(['card', 'bank_transfer', 'promptpay', 'direct_debit']);

test('Auto and Manual come from the payment method', function () {
    expect(cardMethod()->settlement)->toBe(SettlementMode::Auto)
        ->and(transferMethod()->settlement)->toBe(SettlementMode::Manual)
        ->and(transferMethod('หักบัญชี', PaymentMethodType::DirectDebit)->settlement)->toBe(SettlementMode::Auto);
});

test('the form explains Auto and Manual for the chosen method', function () {
    $transfer = transferMethod();
    $debit = transferMethod('หักบัญชีอัตโนมัติ', PaymentMethodType::DirectDebit);

    channelForm()->assertDontSee('data-test="settlement-hint"', false)
        ->set('payment_method_id', (string) $transfer->id)
        ->assertSee('Manual')->assertSee('ยืนยันการชำระเงินในระบบ')
        ->set('payment_method_id', (string) $debit->id)
        ->assertSee('Auto')->assertSee('บันทึกยอดที่ตัดจริง');
});

test('the form lists each channel with its type and masked number only', function () {
    $account = BankAccount::factory()->create(['last_four' => '5678']);
    PaymentMethod::factory()->forBankAccount()->create(['name' => 'โอน KBank', 'bank_account_id' => $account->id]);

    channelForm()->assertSee('โอน KBank')->assertSee('••••5678')->assertSee('ต้องกดยืนยันว่าจ่ายจริง');
});

/*
|--------------------------------------------------------------------------
| What can be chosen
|--------------------------------------------------------------------------
*/

test('only active, usable, non-cash methods are offered', function () {
    $ok = transferMethod('ใช้ได้');
    $off = transferMethod('ปิดอยู่');
    $off->forceFill(['deactivated_at' => now()])->save();
    $deadAccount = transferMethod('บัญชีปิด', account: ['deactivated_at' => now()]);
    $deadCard = CreditCard::factory()->deactivated()->create()->paymentMethod;
    $cash = PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);

    $ids = channelForm()->instance()->methods->pluck('id')->all();

    expect($ids)->toContain($ok->id)
        ->and($ids)->not->toContain($off->id)
        ->and($ids)->not->toContain($deadAccount->id)
        ->and($ids)->not->toContain($deadCard->id)
        ->and($ids)->not->toContain($cash->id);
});

test('an unusable or cash method is rejected when saving', function (string $kind) {
    $method = match ($kind) {
        'method off' => tap(transferMethod(), fn ($m) => $m->forceFill(['deactivated_at' => now()])->save()),
        'account off' => transferMethod(account: ['deactivated_at' => now()]),
        'card off' => CreditCard::factory()->deactivated()->create()->paymentMethod,
        'cash' => PaymentMethod::factory()->cash()->create(),
    };

    fillChannelForm(channelForm(), $method)->call('save')->assertHasErrors(['payment_method_id']);

    expect(Subscription::count())->toBe(0);
})->with(['method off', 'account off', 'card off', 'cash']);

test('the payment method is required and must exist', function () {
    fillChannelForm(channelForm(), cardMethod(), ['payment_method_id' => ''])->call('save')->assertHasErrors(['payment_method_id' => 'required']);
    fillChannelForm(channelForm(), cardMethod(), ['payment_method_id' => '99999'])->call('save')->assertHasErrors(['payment_method_id']);
    fillChannelForm(channelForm(), cardMethod(), ['payment_method_id' => 'abc'])->call('save')->assertHasErrors(['payment_method_id']);

    expect(Subscription::count())->toBe(0);
});

test('the form says so when there is no channel to choose', function () {
    channelForm()->assertSee('ยังไม่มีช่องทางชำระเงินที่ใช้งานอยู่');
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

test('the form opens an existing subscription with its current channel', function () {
    $subscription = Subscription::factory()->create();

    channelForm($subscription)->assertSet('payment_method_id', (string) $subscription->payment_method_id);
});

test('saving other fields keeps the channel as it was', function () {
    $subscription = Subscription::factory()->create();
    $before = $subscription->only(['payment_method_id', 'credit_card_id']);

    channelForm($subscription)->set('notes', 'แก้หมายเหตุ')->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->only(['payment_method_id', 'credit_card_id']))->toEqual($before)
        ->and($subscription->fresh()->notes)->toBe('แก้หมายเหตุ');
});

test('a subscription keeps its current method even if it was switched off since', function () {
    $subscription = Subscription::factory()->create();
    $subscription->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    channelForm($subscription)->set('notes', 'ยังแก้ไขได้')->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->notes)->toBe('ยังแก้ไขได้')
        ->and($subscription->fresh()->payment_method_id)->toBe($subscription->payment_method_id);
});

test('a card subscription can move to transfer, and back to a card', function () {
    $subscription = Subscription::factory()->create();
    $cardMethodId = $subscription->payment_method_id;
    $cardId = $subscription->credit_card_id;
    $transfer = transferMethod();

    channelForm($subscription)->set('payment_method_id', (string) $transfer->id)->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->payment_method_id)->toBe($transfer->id)
        ->and($subscription->fresh()->credit_card_id)->toBeNull();

    channelForm($subscription->fresh())->set('payment_method_id', (string) $cardMethodId)->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->payment_method_id)->toBe($cardMethodId)
        ->and($subscription->fresh()->credit_card_id)->toBe($cardId);
});

test('changing the channel leaves recorded charges, receipts and totals exactly as they were', function () {
    $subscription = Subscription::factory()->create();
    foreach ([1, 2, 3] as $monthsAgo) {
        SubscriptionCharge::factory()->for($subscription)->create(['due_date' => now()->startOfDay()->subMonths($monthsAgo)]);
    }
    $chargesBefore = DB::table('subscription_charges')->orderBy('id')->get()->toArray();
    $receiptsBefore = DB::table('charge_receipts')->count();
    $revisionsBefore = DB::table('subscription_charge_revisions')->count();
    $transfer = transferMethod();

    channelForm($subscription)
        ->assertSee('ประวัติการตัดเงินที่บันทึกไว้แล้วจะไม่เปลี่ยน')
        ->set('payment_method_id', (string) $transfer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($subscription->fresh()->payment_method_id)->toBe($transfer->id)
        ->and(DB::table('subscription_charges')->orderBy('id')->get()->toArray())->toEqual($chargesBefore)
        ->and(DB::table('charge_receipts')->count())->toBe($receiptsBefore)
        ->and(DB::table('subscription_charge_revisions')->count())->toBe($revisionsBefore)
        ->and(SubscriptionCharge::sum('amount'))->toBe(collect($chargesBefore)->sum('amount'));
});

test('switching channel is blocked for a deactivated target and nothing changes', function () {
    $subscription = Subscription::factory()->create();
    $before = $subscription->only(['payment_method_id', 'credit_card_id']);
    $off = transferMethod();
    $off->forceFill(['deactivated_at' => now()])->save();

    channelForm($subscription)->set('payment_method_id', (string) $off->id)->call('save')->assertHasErrors(['payment_method_id']);

    expect($subscription->fresh()->only(['payment_method_id', 'credit_card_id']))->toEqual($before);
});

test('editing warns when the current channel has been switched off', function () {
    $subscription = Subscription::factory()->create();
    $subscription->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    channelForm($subscription)->assertSeeHtml('data-test="method-deactivated-warning"');
});

/*
|--------------------------------------------------------------------------
| Pages keep working for subscriptions without a card
|--------------------------------------------------------------------------
*/

test('detail and list pages show a transfer subscription without a card', function () {
    $transfer = transferMethod('โอนค่าเช่าเซิร์ฟเวอร์', account: ['last_four' => '5678']);
    $subscription = Subscription::factory()->create(['credit_card_id' => null, 'payment_method_id' => $transfer->id, 'name' => 'Colocation ตัวอย่าง']);

    Livewire::actingAs($this->owner)->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->assertSee('โอนค่าเช่าเซิร์ฟเวอร์')->assertSee('••••5678')->assertSee('ต้องกดยืนยันว่าจ่ายจริง')
        ->assertSeeHtml('data-test="record-charge-not-yet"')
        ->assertDontSeeHtml('data-test="record-charge-button"');

    Livewire::actingAs($this->owner)->test('pages::subscriptions.index')
        ->assertSee('Colocation ตัวอย่าง')->assertSee('โอนค่าเช่าเซิร์ฟเวอร์');

    expect($subscription->needsNewCard())->toBeFalse();
});

test('recording a charge is not offered yet for a subscription without a card', function () {
    $transfer = transferMethod();
    $subscription = Subscription::factory()->create([
        'credit_card_id' => null, 'payment_method_id' => $transfer->id, 'next_charge_date' => now()->startOfDay(),
    ]);

    Livewire::actingAs($this->owner)->test('pages::charges.form', ['subscription' => $subscription])
        ->assertSee('จะเปิดให้ใช้ในขั้นถัดไป');

    expect(SubscriptionCharge::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

test('only the owner can open the form or save', function () {
    $method = cardMethod();
    $subscription = Subscription::factory()->create();

    foreach ([User::factory()->accountant()->create(), User::factory()->create()] as $user) {
        Livewire::actingAs($user)->test('pages::subscriptions.form')->assertForbidden();
        Livewire::actingAs($user)->test('pages::subscriptions.form', ['subscription' => $subscription])->assertForbidden();
        $this->actingAs($user)->get(route('subscriptions.create'))->assertForbidden();
    }

    expect(Subscription::count())->toBe(1)
        ->and($method->subscriptions()->count())->toBe(0);
});

test('a guest cannot reach the form', function () {
    $this->get(route('subscriptions.create'))->assertRedirect(route('login'));
});
