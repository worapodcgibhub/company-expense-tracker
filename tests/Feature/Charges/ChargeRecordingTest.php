<?php

use App\Enums\BillingCycle;
use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Exceptions\ChargeConflictException;
use App\Models\AccountingPeriodClosure;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const CHARGE_FORM = 'pages::charges.form';

beforeEach(function () {
    // Sunday 15 Nov 2026, Bangkok time.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function recordForm(Subscription $subscription, ?User $as = null): Testable
{
    return Livewire::actingAs($as ?? test()->owner)->test(CHARGE_FORM, ['subscription' => $subscription]);
}

/**
 * @return array{outcome: ChargeOutcome, charged_on: CarbonImmutable, amount: int, amount_thb: ?int, credit_card_id: int, notes: ?string}
 */
function chargeValues(Subscription $subscription, array $overrides = []): array
{
    return array_merge([
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::parse('2026-11-14'),
        'amount' => 61_000,
        'amount_thb' => null,
        'credit_card_id' => $subscription->credit_card_id,
        'notes' => null,
    ], $overrides);
}

test('the owner records the actual charge and the next charge date moves to the next cycle', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['estimated_amount' => 59_000]);

    recordForm($subscription)
        ->assertSet('charged_on', '2026-11-14')
        ->assertSet('amount', '590.00')
        ->set('amount', '610')
        ->set('notes', 'ราคาขึ้น')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('subscriptions.show', $subscription));

    $charge = SubscriptionCharge::sole();

    expect($charge->subscription_id)->toBe($subscription->id)
        ->and($charge->due_date->format('Y-m-d'))->toBe('2026-11-14')
        ->and($charge->charged_on?->format('Y-m-d'))->toBe('2026-11-14')
        ->and($charge->outcome)->toBe(ChargeOutcome::Charged)
        ->and($charge->amount)->toBe(61_000)
        ->and($charge->currency)->toBe(Currency::THB)
        ->and($charge->credit_card_id)->toBe($subscription->credit_card_id)
        ->and($charge->notes)->toBe('ราคาขึ้น')
        ->and($charge->recorded_by)->toBe($this->owner->id)
        ->and($charge->entered_after_close)->toBeFalse()
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-12-14');

    $revision = $charge->revisions()->sole();
    expect($revision->action)->toBe(ChargeRevisionAction::Recorded)
        ->and($revision->changed_by)->toBe($this->owner->id)
        ->and($revision->diff['amount'])->toBe(61_000);
});

test('recording never changes the estimated amount or anything else on the subscription', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['estimated_amount' => 59_000]);
    $before = $subscription->only(['name', 'estimated_amount', 'billing_day', 'billing_cycle', 'status', 'credit_card_id']);

    recordForm($subscription)->set('amount', '999')->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->only(array_keys($before)))->toEqual($before);
});

test('a month-end subscription keeps its billing day when moving to the next cycle', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-10-31'))->create(['billing_day' => 31]);

    $subscription->recordCharge(CarbonImmutable::parse('2026-10-31'), chargeValues($subscription, ['charged_on' => CarbonImmutable::parse('2026-10-31')]), $this->owner);
    expect($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-11-30');
});

test('a yearly subscription moves one year ahead', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-10'))->create(['billing_cycle' => BillingCycle::Yearly]);

    $subscription->recordCharge(CarbonImmutable::parse('2026-11-10'), chargeValues($subscription), $this->owner);

    expect($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2027-11-10');
});

test('a skipped cycle is recorded without amount and still moves to the next cycle', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    recordForm($subscription)
        ->set('outcome', 'skipped')
        ->set('amount', '')
        ->call('save')
        ->assertHasNoErrors();

    $charge = SubscriptionCharge::sole();
    expect($charge->outcome)->toBe(ChargeOutcome::Skipped)
        ->and($charge->amount)->toBe(0)
        ->and($charge->charged_on)->toBeNull()
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-12-14');
});

test('a USD charge keeps the USD amount and the baht amount is optional', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['currency' => Currency::USD, 'estimated_amount' => 5_499]);

    recordForm($subscription)
        ->set('amount', '54.99')
        ->set('amount_thb', '')
        ->call('save')
        ->assertHasNoErrors();

    $charge = SubscriptionCharge::sole();
    expect($charge->currency)->toBe(Currency::USD)
        ->and($charge->amount)->toBe(5_499)
        ->and($charge->amount_thb)->toBeNull()
        ->and($charge->needsThbAmount())->toBeTrue()
        ->and($charge->thbAmount())->toBeNull();
});

test('a USD charge can be recorded with the baht the bank took', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['currency' => Currency::USD, 'estimated_amount' => 5_499]);

    recordForm($subscription)->set('amount', '54.99')->set('amount_thb', '1,950.25')->call('save')->assertHasNoErrors();

    expect(SubscriptionCharge::sole())
        ->amount_thb->toBe(195_025)
        ->thbAmount()->toBe(195_025);
});

test('a THB charge never stores a separate baht amount', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    recordForm($subscription)->set('amount_thb', '123')->call('save')->assertHasNoErrors();

    expect(SubscriptionCharge::sole()->amount_thb)->toBeNull();
});

test('invalid amounts and dates are rejected', function (string $field, string $value) {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['currency' => Currency::USD]);

    recordForm($subscription)->set($field, $value)->call('save')->assertHasErrors($field);

    expect(SubscriptionCharge::count())->toBe(0);
})->with([
    'zero amount' => ['amount', '0'],
    'text amount' => ['amount', 'abc'],
    'three decimals' => ['amount', '1.234'],
    'empty amount' => ['amount', ''],
    'zero baht' => ['amount_thb', '0'],
    'bad baht' => ['amount_thb', '12,34'],
    'future date' => ['charged_on', '2026-11-16'],
    'empty date' => ['charged_on', ''],
    'far before due date' => ['charged_on', '2026-10-13'],
]);

test('the charge date can be backdated and the recording time and person are kept', function () {
    // Cycle due 20 Sep, charged that day, only recorded today (15 Nov).
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-09-20'))->create();

    recordForm($subscription)
        ->assertSet('charged_on', '2026-09-20')
        ->call('save')
        ->assertHasNoErrors();

    $charge = SubscriptionCharge::sole();
    expect($charge->charged_on?->format('Y-m-d'))->toBe('2026-09-20')
        ->and($charge->created_at?->format('Y-m-d'))->toBe('2026-11-15')
        ->and($charge->recorded_by)->toBe($this->owner->id)
        ->and($charge->daysRecordedLate())->toBe(56)
        // Still overdue: the October cycle is next, recorded one at a time.
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-10-20');
});

test('overdue cycles are recorded one at a time in order', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-09-20'))->create();

    foreach (['2026-09-20', '2026-10-20'] as $due) {
        $subscription->recordCharge(CarbonImmutable::parse($due), chargeValues($subscription, ['charged_on' => CarbonImmutable::parse($due)]), $this->owner);
    }

    expect(SubscriptionCharge::orderBy('due_date')->pluck('due_date')->map->format('Y-m-d')->all())->toBe(['2026-09-20', '2026-10-20'])
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-11-20');
});

test('a cycle can be recorded up to 7 days before it is due, not earlier', function () {
    $soon = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-22'))->create();
    $later = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-23'))->create();

    recordForm($soon)->assertDontSee('data-test="blocked-notice"', false)->set('charged_on', '2026-11-15')->call('save')->assertHasNoErrors();

    recordForm($later)->assertSee('data-test="blocked-notice"', false);
    expect(fn () => $later->recordCharge(CarbonImmutable::parse('2026-11-23'), chargeValues($later, ['charged_on' => CarbonImmutable::parse('2026-11-15')]), $this->owner))
        ->toThrow(ChargeConflictException::class);

    expect(SubscriptionCharge::count())->toBe(1);
});

test('the same cycle cannot be recorded twice, even from two open forms', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    $first = recordForm($subscription);
    $second = recordForm($subscription);

    $first->call('save')->assertHasNoErrors();
    $second->call('save')->assertHasErrors('form');

    expect(SubscriptionCharge::count())->toBe(1)
        ->and($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-12-14');
});

test('recording a cycle that is no longer the current one is refused', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();
    $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), chargeValues($subscription), $this->owner);

    expect(fn () => $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), chargeValues($subscription), $this->owner))
        ->toThrow(ChargeConflictException::class);

    expect(SubscriptionCharge::count())->toBe(1);
});

test('the database itself refuses a second row for the same cycle', function () {
    $subscription = Subscription::factory()->create();
    SubscriptionCharge::factory()->for($subscription)->chargedOn(CarbonImmutable::parse('2026-11-01'))->create();

    expect(fn () => SubscriptionCharge::factory()->for($subscription)->chargedOn(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-01'))->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('paused and cancelled subscriptions cannot be recorded', function (string $state) {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->{$state}()->create();

    $this->actingAs($this->owner)->get(route('charges.create', $subscription))->assertForbidden();

    expect(fn () => $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), chargeValues($subscription), $this->owner))
        ->toThrow(ChargeConflictException::class);
})->with(['paused', 'cancelled']);

test('a deactivated card can be chosen for a backdated charge', function () {
    $oldCard = CreditCard::factory()->deactivated()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    recordForm($subscription)->set('payment_method_id', (string) $oldCard->paymentMethod->id)->call('save')->assertHasNoErrors();

    expect(SubscriptionCharge::sole()->credit_card_id)->toBe($oldCard->id);
});

test('a big difference from the estimate shows a warning but does not block', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['estimated_amount' => 59_000]);

    recordForm($subscription)
        ->set('amount', '900')
        ->assertSee('data-test="variance-notice"', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(SubscriptionCharge::count())->toBe(1);
});

test('the detail page shows the record button and the recorded charges', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    $this->actingAs($this->owner)->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertSee('data-test="record-charge-button"', false)
        ->assertSee('ยังไม่มีการบันทึกยอดตัดจริง');

    $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), chargeValues($subscription), $this->owner);

    $this->actingAs($this->owner)->get(route('subscriptions.show', $subscription))
        ->assertSee('data-test="charge-list"', false)
        ->assertSee('฿610.00')
        // Next cycle (14 Dec) is more than 7 days away.
        ->assertDontSee('data-test="record-charge-button"', false)
        ->assertSee('data-test="record-charge-later"', false);
});

/*
|--------------------------------------------------------------------------
| Closed accounting periods
|--------------------------------------------------------------------------
*/

test('a charge dated in a closed month needs confirmation and is flagged as a late entry', function () {
    $accountant = User::factory()->accountant()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-10-28'))->create();
    $closure = AccountingPeriodClosure::close('2026-10', $accountant);

    recordForm($subscription)
        ->assertSet('charged_on', '2026-10-28')
        ->assertSee('data-test="closed-period-warning"', false)
        ->call('save')
        ->assertHasErrors('confirm_closed_period');

    expect(SubscriptionCharge::count())->toBe(0);

    recordForm($subscription)->set('confirm_closed_period', true)->call('save')->assertHasNoErrors();

    $charge = SubscriptionCharge::sole();
    expect($charge->entered_after_close)->toBeTrue()
        ->and($charge->revisions()->sole()->in_closed_period)->toBeTrue()
        // The closed period itself is untouched: still closed, same figures.
        ->and(AccountingPeriodClosure::isClosed('2026-10'))->toBeTrue()
        ->and(AccountingPeriodClosure::count())->toBe(1)
        ->and($closure->fresh()->snapshot['thb_total'])->toBe(0);
});

test('the model also refuses an unconfirmed entry in a closed month', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-10-28'))->create();
    AccountingPeriodClosure::close('2026-10', $this->owner);

    expect(fn () => $subscription->recordCharge(CarbonImmutable::parse('2026-10-28'), chargeValues($subscription, ['charged_on' => CarbonImmutable::parse('2026-10-28')]), $this->owner))
        ->toThrow(ChargeConflictException::class);
});

test('an open month needs no confirmation', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-10-28'))->create();
    AccountingPeriodClosure::close('2026-10', $this->owner);
    AccountingPeriodClosure::reopen('2026-10', $this->owner, 'ต้องแก้ไขยอด');

    recordForm($subscription)->assertDontSee('data-test="closed-period-warning"', false)->call('save')->assertHasNoErrors();

    expect(SubscriptionCharge::sole()->entered_after_close)->toBeFalse();
});
