<?php

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\PaymentMethodType;
use App\Exceptions\PaymentMethodMismatchException;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Phase 3.1e-3: recording what was really paid, for every channel.
 * - Auto (card, direct debit): "record the actual charge".
 * - Manual (transfer / PromptPay, cash): "confirm the payment", with an
 *   explicit tick. Reaching the due date never counts as paid.
 * - The charge keeps the method really used and who recorded / confirmed it.
 */

beforeEach(function () {
    // Sunday 15 Nov 2026, Bangkok time.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function payForm(Subscription $subscription, ?User $as = null): Testable
{
    return Livewire::actingAs($as ?? test()->owner)->test('pages::charges.form', ['subscription' => $subscription]);
}

function payMethod(PaymentMethodType $type, string $name = 'ช่องทางทดสอบ'): PaymentMethod
{
    return match ($type) {
        PaymentMethodType::Card => tap(CreditCard::factory()->create()->paymentMethod, fn ($m) => $m->forceFill(['name' => $name])->save()),
        PaymentMethodType::Cash => PaymentMethod::factory()->cash()->create(['name' => $name]),
        default => PaymentMethod::factory()->forBankAccount($type)->create([
            'name' => $name,
            'bank_account_id' => BankAccount::factory()->create(['last_four' => '5678'])->id,
        ]),
    };
}

function dueSubscription(PaymentMethod $method, string $due = '2026-11-14', array $attributes = []): Subscription
{
    return Subscription::factory()->chargingOn(CarbonImmutable::parse($due))->create([
        'credit_card_id' => $method->credit_card_id,
        'payment_method_id' => $method->id,
        'estimated_amount' => 850_000,
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| Manual: confirm the payment
|--------------------------------------------------------------------------
*/

test('a Manual payment needs an explicit confirmation, then is stored with its method and confirmer', function (PaymentMethodType $type, string $name) {
    $method = payMethod($type, $name);
    $subscription = dueSubscription($method);

    $form = payForm($subscription)
        ->assertSeeHtml('data-test="confirm-paid-box"')
        ->assertSee('ยืนยันการชำระเงิน')
        ->assertSee('วันที่ชำระจริง')
        ->call('save')
        ->assertHasErrors(['confirm_paid']);

    expect(SubscriptionCharge::count())->toBe(0)
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-11-14');

    $form->set('confirm_paid', true)->call('save')->assertHasNoErrors()->assertRedirect(route('subscriptions.show', $subscription));

    $charge = SubscriptionCharge::sole();

    expect($charge->payment_method_id)->toBe($method->id)
        ->and($charge->credit_card_id)->toBeNull()
        ->and($charge->outcome)->toBe(ChargeOutcome::Charged)
        ->and($charge->amount)->toBe(850_000)
        ->and($charge->charged_on->format('Y-m-d'))->toBe('2026-11-14')
        ->and($charge->recorded_by)->toBe($this->owner->id)
        ->and($charge->revisions()->sole()->action)->toBe(ChargeRevisionAction::Recorded)
        ->and($charge->revisions()->sole()->diff['payment_method_id'])->toBe($method->id)
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-12-14');
})->with([
    'bank transfer' => [PaymentMethodType::BankTransfer, 'โอนเงินผ่านธนาคาร KBank'],
    'PromptPay / QR' => [PaymentMethodType::BankTransfer, 'PromptPay / QR ผ่านบัญชี SCB'],
]);

test('a Manual cycle that was not charged needs no payment confirmation', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::BankTransfer));

    payForm($subscription)->set('outcome', 'skipped')->assertDontSeeHtml('data-test="confirm-paid-box"')->call('save')->assertHasNoErrors();

    expect(SubscriptionCharge::sole()->outcome)->toBe(ChargeOutcome::Skipped);
});

/*
|--------------------------------------------------------------------------
| Auto: record the actual charge
|--------------------------------------------------------------------------
*/

test('an Auto charge is recorded with its method, without a payment tick', function (PaymentMethodType $type) {
    $method = payMethod($type);
    $subscription = dueSubscription($method);

    payForm($subscription)
        ->assertDontSeeHtml('data-test="confirm-paid-box"')
        ->assertSee('บันทึกยอดตัดจริง')
        ->assertSee('วันที่ตัดเงินจริง')
        ->set('amount', '8,612.50')
        ->call('save')
        ->assertHasNoErrors();

    $charge = SubscriptionCharge::sole();

    expect($charge->payment_method_id)->toBe($method->id)
        ->and($charge->credit_card_id)->toBe($method->credit_card_id)
        ->and($charge->amount)->toBe(861_250)
        ->and($charge->recorded_by)->toBe($this->owner->id);
})->with(['card' => PaymentMethodType::Card, 'direct debit' => PaymentMethodType::DirectDebit]);

test('an Auto cycle is not counted as paid just because its date has passed', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::DirectDebit), '2026-11-10');
    $overview = fn () => (new ChargeOverview($this->owner, now()))->monthSummary();

    expect($subscription->isOverdue())->toBeTrue()
        ->and($overview()['charge_count'])->toBe(0)
        ->and($overview()['thb_total'])->toBe(0);

    payForm($subscription)->call('save')->assertHasNoErrors();

    expect($overview()['charge_count'])->toBe(1)
        ->and($overview()['thb_total'])->toBe(850_000)
        ->and($subscription->fresh()->isOverdue())->toBeFalse();
});

test('a Manual cycle is not counted as paid until it is confirmed', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::BankTransfer), '2026-11-10');
    $overview = fn () => (new ChargeOverview($this->owner, now()))->monthSummary();

    payForm($subscription)->call('save')->assertHasErrors(['confirm_paid']);

    expect($overview()['charge_count'])->toBe(0)->and($subscription->fresh()->isOverdue())->toBeTrue();

    payForm($subscription)->set('confirm_paid', true)->call('save')->assertHasNoErrors();

    expect($overview()['charge_count'])->toBe(1)->and($overview()['thb_total'])->toBe(850_000);
});

/*
|--------------------------------------------------------------------------
| The method really used
|--------------------------------------------------------------------------
*/

test('the subscription method is the default, and another method can be recorded for one cycle', function () {
    $transfer = payMethod(PaymentMethodType::BankTransfer, 'โอน KBank');
    $cash = payMethod(PaymentMethodType::Cash, 'เงินสดย่อย');
    $subscription = dueSubscription($transfer);

    payForm($subscription)
        ->assertSet('payment_method_id', (string) $transfer->id)
        ->set('payment_method_id', (string) $cash->id)
        ->set('confirm_paid', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(SubscriptionCharge::sole()->payment_method_id)->toBe($cash->id)
        ->and($subscription->fresh()->payment_method_id)->toBe($transfer->id);
});

test('the payment method is required and must exist', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::Card));

    payForm($subscription)->set('payment_method_id', '')->call('save')->assertHasErrors(['payment_method_id' => 'required']);
    payForm($subscription)->set('payment_method_id', '99999')->call('save')->assertHasErrors(['payment_method_id']);

    expect(SubscriptionCharge::count())->toBe(0);
});

test('changing the subscription method later does not touch charges already recorded', function () {
    $card = payMethod(PaymentMethodType::Card);
    $subscription = dueSubscription($card);
    payForm($subscription)->call('save')->assertHasNoErrors();
    $before = DB::table('subscription_charges')->get()->toArray();
    $transfer = payMethod(PaymentMethodType::BankTransfer);

    Livewire::actingAs($this->owner)->test('pages::subscriptions.form', ['subscription' => $subscription->fresh()])
        ->set('payment_method_id', (string) $transfer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($subscription->fresh()->payment_method_id)->toBe($transfer->id)
        ->and(DB::table('subscription_charges')->get()->toArray())->toEqual($before)
        ->and(SubscriptionCharge::sole()->payment_method_id)->toBe($card->id);
});

test('correcting the method of a charge is kept in its history and takes back verification', function () {
    $transfer = payMethod(PaymentMethodType::BankTransfer, 'โอน KBank');
    $promptPay = payMethod(PaymentMethodType::BankTransfer, 'PromptPay SCB');
    $subscription = dueSubscription($transfer);
    payForm($subscription)->set('confirm_paid', true)->call('save')->assertHasNoErrors();
    $charge = SubscriptionCharge::sole();
    $charge->verify(User::factory()->accountant()->create());
    $amountBefore = $charge->amount;

    Livewire::actingAs($this->owner)->test('pages::charges.form', ['charge' => $charge])
        ->assertSet('payment_method_id', (string) $transfer->id)
        ->assertDontSeeHtml('data-test="confirm-paid-box"')
        ->set('payment_method_id', (string) $promptPay->id)
        ->set('reason', 'ลูกค้าโอนผ่าน PromptPay')
        ->call('save')
        ->assertHasNoErrors();

    $charge->refresh();
    $revision = $charge->revisions()->latest('id')->first();

    expect($charge->payment_method_id)->toBe($promptPay->id)
        ->and($charge->amount)->toBe($amountBefore)
        ->and($charge->isVerified())->toBeFalse()
        ->and($revision->diff['payment_method_id'])->toBe([$transfer->id, $promptPay->id])
        ->and($revision->describeChanges('THB'))->toContain('ช่องทางชำระเงิน: โอน KBank → PromptPay SCB');
});

/*
|--------------------------------------------------------------------------
| Model rules
|--------------------------------------------------------------------------
*/

test('recordCharge still works with only a card, and rejects a card that does not match the method', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::Card));
    $values = ['outcome' => ChargeOutcome::Charged, 'charged_on' => CarbonImmutable::parse('2026-11-14'), 'amount' => 1_000, 'amount_thb' => null, 'notes' => null];

    $other = payMethod(PaymentMethodType::BankTransfer);

    expect(fn () => $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), [...$values, 'payment_method_id' => $other->id, 'credit_card_id' => $subscription->credit_card_id], $this->owner))
        ->toThrow(PaymentMethodMismatchException::class)
        ->and(fn () => $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), $values, $this->owner))
        ->toThrow(LogicException::class)
        ->and(SubscriptionCharge::count())->toBe(0);

    $charge = $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), [...$values, 'credit_card_id' => $subscription->credit_card_id], $this->owner);

    expect($charge->payment_method_id)->toBe($subscription->payment_method_id);
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

test('the charge page shows the method really used and who confirmed a Manual payment', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::BankTransfer, 'โอนเงินผ่านธนาคาร KBank'));
    payForm($subscription)->set('confirm_paid', true)->call('save');
    $charge = SubscriptionCharge::sole();

    $this->actingAs($this->owner)->get(route('charges.show', $charge))->assertOk()
        ->assertSee('ช่องทางที่ใช้ชำระจริง')
        ->assertSee('โอนเงินผ่านธนาคาร KBank')
        ->assertSee('••••5678')
        ->assertSee('(Manual)')
        ->assertSee('ยืนยันการชำระโดย')
        ->assertSee('วันที่ชำระจริง');

    $this->actingAs($this->owner)->get(route('charges.index', ['month' => 'all']))->assertOk()->assertSee('โอนเงินผ่านธนาคาร KBank');
    $this->actingAs($this->owner)->get(route('subscriptions.show', $subscription))->assertOk()->assertSee('ยืนยันโดย');
    $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
});

test('an Auto charge page keeps "recorded by"', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::Card));
    payForm($subscription)->call('save');

    $this->actingAs($this->owner)->get(route('charges.show', SubscriptionCharge::sole()))->assertOk()
        ->assertSee('(Auto)')->assertSee('บันทึกโดย')->assertDontSee('ยืนยันการชำระโดย');
});

test('the subscription page shows the right button and due-date wording', function () {
    $manual = dueSubscription(payMethod(PaymentMethodType::BankTransfer), '2026-11-17');
    $auto = dueSubscription(payMethod(PaymentMethodType::DirectDebit), '2026-11-17');

    $this->actingAs($this->owner)->get(route('subscriptions.show', $manual))
        ->assertSee('กำหนดชำระครั้งถัดไป')->assertDontSee('วันตัดบัตรครั้งถัดไป')
        ->assertSee('ยืนยันการชำระเงิน (รอบ');

    $this->actingAs($this->owner)->get(route('subscriptions.show', $auto))
        ->assertSee('บันทึกยอดตัดจริง (รอบ')->assertDontSee('ยืนยันการชำระเงิน (รอบ');
});

test('only the owner can record or confirm a payment', function () {
    $subscription = dueSubscription(payMethod(PaymentMethodType::BankTransfer));

    foreach ([User::factory()->accountant()->create(), User::factory()->create(['id' => $subscription->responsible_user_id + 1000])] as $user) {
        Livewire::actingAs($user)->test('pages::charges.form', ['subscription' => $subscription])->assertForbidden();
        $this->actingAs($user)->get(route('charges.create', $subscription))->assertForbidden();
    }

    expect(SubscriptionCharge::count())->toBe(0);
});
