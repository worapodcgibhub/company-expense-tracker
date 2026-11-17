<?php

use App\Enums\BillingCycle;
use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionStatusChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const SUB_FORM = 'pages::subscriptions.form';
const SUB_SHOW = 'pages::subscriptions.show';

beforeEach(function () {
    // Sunday 15 Nov 2026, Bangkok time.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->card = CreditCard::factory()->create();
});

/**
 * The form filled with valid values (override any of them). The date is set
 * before the billing day because choosing a date fills the day in.
 *
 * @param  array<string, string>  $overrides
 */
function fillSubscriptionForm(Testable $component, array $overrides = []): Testable
{
    $values = array_merge([
        'name' => 'Adobe Creative Cloud',
        'vendor' => 'Adobe Inc.',
        'category' => 'design',
        'responsible_user_id' => (string) test()->owner->id,
        'payment_method_id' => (string) test()->card->paymentMethod->id,
        'billing_cycle' => 'monthly',
        'next_charge_date' => '2026-11-20',
        'currency' => 'THB',
        'amount' => '1,890.50',
        'remind_days_before' => '3',
        'notes' => 'ทีมออกแบบ 3 ที่นั่ง',
    ], $overrides);

    $billingDay = $values['billing_day'] ?? null;
    unset($values['billing_day']);

    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    if ($billingDay !== null) {
        $component->set('billing_day', $billingDay);
    }

    return $component;
}

/**
 * Livewire's assertSeeInOrder() compares against JSON-escaped Thai, so check
 * the order in the rendered HTML instead.
 *
 * @param  list<string>  $texts
 */
function expectHtmlInOrder(string $html, array $texts): void
{
    $position = -1;

    foreach ($texts as $text) {
        $found = mb_strpos($html, $text, $position + 1);
        expect($found)->not->toBeFalse("\"{$text}\" not found after position {$position}");
        $position = $found;
    }
}

function ownerForm(?Subscription $subscription = null): Testable
{
    return Livewire::actingAs(test()->owner)->test(SUB_FORM, $subscription ? ['subscription' => $subscription] : []);
}

/*
|--------------------------------------------------------------------------
| Create and edit
|--------------------------------------------------------------------------
*/

test('the owner can add a subscription', function () {
    $staff = User::factory()->create();

    $component = fillSubscriptionForm(ownerForm(), ['responsible_user_id' => (string) $staff->id])
        ->call('save')
        ->assertHasNoErrors();

    $subscription = Subscription::sole();
    $component->assertRedirect(route('subscriptions.show', $subscription));

    expect($subscription->name)->toBe('Adobe Creative Cloud')
        ->and($subscription->vendor)->toBe('Adobe Inc.')
        ->and($subscription->category)->toBe(SubscriptionCategory::Design)
        ->and($subscription->responsible_user_id)->toBe($staff->id)
        ->and($subscription->credit_card_id)->toBe($this->card->id)
        ->and($subscription->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($subscription->billing_day)->toBe(20)
        ->and($subscription->next_charge_date->format('Y-m-d'))->toBe('2026-11-20')
        ->and($subscription->currency)->toBe(Currency::THB)
        ->and($subscription->estimated_amount)->toBe(189050)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->created_by)->toBe($this->owner->id)
        ->and($subscription->updated_by)->toBe($this->owner->id);

    $history = SubscriptionStatusChange::sole();
    expect($history->from_status)->toBeNull()
        ->and($history->to_status)->toBe(SubscriptionStatus::Active)
        ->and($history->changed_by)->toBe($this->owner->id);
});

test('a USD subscription keeps its own currency and cents', function () {
    fillSubscriptionForm(ownerForm(), ['currency' => 'USD', 'amount' => '54.99'])->call('save')->assertHasNoErrors();

    $subscription = Subscription::sole();
    expect($subscription->currency)->toBe(Currency::USD)
        ->and($subscription->estimated_amount)->toBe(5499)
        ->and($subscription->amountLabel())->toBe('$54.99 / เดือน');
});

test('the responsible person defaults to the current user', function () {
    ownerForm()->assertSet('responsible_user_id', (string) $this->owner->id);
});

test('choosing a date fills in the billing day, and month end keeps 31', function () {
    $component = ownerForm()->set('next_charge_date', '2026-11-23')->assertSet('billing_day', '23');

    $component->set('next_charge_date', '2026-11-30')->assertSet('billing_day', '30')
        ->assertSeeHtml('data-test="month-end-hint"');

    $component->set('billing_day', '31')->set('next_charge_date', '2027-02-28')->assertSet('billing_day', '31');
});

test('a month-end subscription is saved with billing day 31', function () {
    fillSubscriptionForm(ownerForm(), ['next_charge_date' => '2026-11-30', 'billing_day' => '31'])->call('save')->assertHasNoErrors();

    expect(Subscription::sole()->billing_day)->toBe(31);
});

test('the form previews the next three charge dates', function () {
    $component = fillSubscriptionForm(ownerForm(), ['next_charge_date' => '2027-01-31', 'billing_day' => '31'])
        ->assertSeeHtml('data-test="schedule-preview"');

    expectHtmlInOrder($component->html(), ['31 ม.ค. 2570', '28 ก.พ. 2570', '31 มี.ค. 2570']);
});

test('the owner can edit a subscription', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-25'))->create(['name' => 'ชื่อเดิม', 'estimated_amount' => 59000]);

    ownerForm($subscription)
        ->assertSet('amount', '590.00')
        ->set('name', 'ชื่อใหม่')
        ->set('amount', '650')
        ->set('billing_cycle', 'yearly')
        ->call('save')
        ->assertHasNoErrors();

    $subscription->refresh();
    expect($subscription->name)->toBe('ชื่อใหม่')
        ->and($subscription->estimated_amount)->toBe(65000)
        ->and($subscription->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and($subscription->updated_by)->toBe($this->owner->id)
        ->and($subscription->statusChanges()->count())->toBe(0);
});

test('editing keeps an overdue date when it is not changed', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-10'))->create();

    ownerForm($subscription)->set('notes', 'ยังไม่ได้ยืนยันยอด')->call('save')->assertHasNoErrors();

    expect($subscription->fresh()->next_charge_date->format('Y-m-d'))->toBe('2026-11-10');
});

test('editing keeps a card that was deactivated later, with a warning', function () {
    $subscription = Subscription::factory()->create();
    $subscription->creditCard->forceFill(['deactivated_at' => now()])->save();

    ownerForm($subscription)
        ->assertSeeHtml('data-test="card-deactivated-warning"')
        ->set('notes', 'แก้หมายเหตุ')
        ->call('save')
        ->assertHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

test('required fields are validated', function () {
    ownerForm()
        ->set('responsible_user_id', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'vendor' => 'required', 'responsible_user_id' => 'required', 'payment_method_id' => 'required', 'next_charge_date' => 'required', 'billing_day' => 'required', 'amount' => 'required']);

    expect(Subscription::count())->toBe(0);
});

test('the amount must be a positive number with at most two decimals', function (string $amount) {
    fillSubscriptionForm(ownerForm(), ['amount' => $amount])->call('save')->assertHasErrors(['amount']);

    expect(Subscription::count())->toBe(0);
})->with(['abc', '1.234', '-5', '0', '0.00', '100000000', '1e3']);

test('the first charge date cannot be in the past or too far ahead', function (string $date) {
    fillSubscriptionForm(ownerForm(), ['next_charge_date' => $date])->call('save')->assertHasErrors(['next_charge_date']);
})->with(['2026-11-14', '2029-01-01', '2026-02-30', 'not-a-date']);

test('today is accepted as the next charge date', function () {
    fillSubscriptionForm(ownerForm(), ['next_charge_date' => '2026-11-15'])->call('save')->assertHasNoErrors();
});

test('the date must fall on the chosen billing day', function () {
    fillSubscriptionForm(ownerForm(), ['next_charge_date' => '2026-11-20', 'billing_day' => '25'])
        ->call('save')
        ->assertHasErrors(['next_charge_date']);
});

test('a deactivated card cannot be used for a new subscription', function () {
    $closed = CreditCard::factory()->deactivated()->create();

    fillSubscriptionForm(ownerForm(), ['payment_method_id' => (string) $closed->paymentMethod->id])
        ->call('save')
        ->assertHasErrors(['payment_method_id']);

    expect(Subscription::count())->toBe(0);
});

test('an existing subscription cannot be switched to a deactivated card', function () {
    $subscription = Subscription::factory()->create();
    $closed = CreditCard::factory()->deactivated()->create();

    ownerForm($subscription)->set('payment_method_id', (string) $closed->paymentMethod->id)->call('save')->assertHasErrors(['payment_method_id']);

    expect($subscription->fresh()->payment_method_id)->not->toBe($closed->paymentMethod->id);
});

test('deactivated cards are not offered for a new subscription', function () {
    CreditCard::factory()->deactivated()->create(['name' => 'บัตรที่ปิดแล้ว']);

    ownerForm()->assertDontSee('บัตรที่ปิดแล้ว');
});

test('the responsible person must be an active user', function () {
    $former = User::factory()->deactivated()->create();

    fillSubscriptionForm(ownerForm(), ['responsible_user_id' => (string) $former->id])
        ->call('save')
        ->assertHasErrors(['responsible_user_id']);
});

test('unknown enum values and ids are rejected', function () {
    fillSubscriptionForm(ownerForm(), [
        'category' => 'gaming', 'billing_cycle' => 'weekly', 'currency' => 'EUR', 'payment_method_id' => '99999', 'remind_days_before' => '31',
    ])->call('save')->assertHasErrors(['category', 'billing_cycle', 'currency', 'payment_method_id', 'remind_days_before']);
});

test('full card numbers are rejected in the name and notes', function (string $field) {
    fillSubscriptionForm(ownerForm(), [$field => 'บัตร 4111111111111111'])->call('save')->assertHasErrors([$field]);
})->with(['name', 'vendor', 'notes']);

/*
|--------------------------------------------------------------------------
| Possible duplicates
|--------------------------------------------------------------------------
*/

test('the same service and vendor warns before saving and can be confirmed', function () {
    Subscription::factory()->create(['name' => 'Adobe Creative Cloud', 'vendor' => 'Adobe Inc.']);

    $component = fillSubscriptionForm(ownerForm(), ['name' => ' adobe creative  cloud ', 'vendor' => 'ADOBE INC.'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="duplicate-warning"');

    expect(Subscription::count())->toBe(1);

    $component->call('saveAnyway')->assertHasNoErrors();

    expect(Subscription::count())->toBe(2);
});

test('a cancelled subscription is not treated as a duplicate', function () {
    Subscription::factory()->cancelled()->create(['name' => 'Adobe Creative Cloud', 'vendor' => 'Adobe Inc.']);

    fillSubscriptionForm(ownerForm())->call('save')->assertHasNoErrors()->assertSet('duplicateWarnings', []);

    expect(Subscription::count())->toBe(2);
});

test('editing a subscription does not warn about itself', function () {
    $subscription = Subscription::factory()->create();

    ownerForm($subscription)->set('notes', 'x')->call('save')->assertSet('duplicateWarnings', []);
});

/*
|--------------------------------------------------------------------------
| Status changes keep history; nothing is deleted
|--------------------------------------------------------------------------
*/

test('the owner can pause with a reason and the change is recorded', function () {
    $subscription = Subscription::factory()->create();

    Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])
        ->call('openStatusChange', 'pause')
        ->set('reason', 'โปรเจกต์หยุดชั่วคราว')
        ->call('confirmStatusChange')
        ->assertHasNoErrors();

    $subscription->refresh();
    $change = $subscription->statusChanges()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Paused)
        ->and($subscription->status_changed_at)->not->toBeNull()
        ->and($change->from_status)->toBe(SubscriptionStatus::Active)
        ->and($change->to_status)->toBe(SubscriptionStatus::Paused)
        ->and($change->reason)->toBe('โปรเจกต์หยุดชั่วคราว')
        ->and($change->changed_by)->toBe($this->owner->id);
});

test('resuming suggests the first charge date that is not in the past', function () {
    $subscription = Subscription::factory()->paused()->chargingOn(CarbonImmutable::parse('2026-08-31'), 31)->create();

    Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])
        ->call('openResume')
        ->assertSet('resume_date', '2026-11-30')
        ->call('confirmResume')
        ->assertHasNoErrors();

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->next_charge_date->format('Y-m-d'))->toBe('2026-11-30')
        ->and($subscription->statusChanges()->latest('id')->first()->to_status)->toBe(SubscriptionStatus::Active);
});

test('resuming rejects past dates and dates off the billing day', function (string $date) {
    $subscription = Subscription::factory()->paused()->chargingOn(CarbonImmutable::parse('2026-12-10'))->create();

    Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])
        ->call('openResume')
        ->set('resume_date', $date)
        ->call('confirmResume')
        ->assertHasErrors(['resume_date']);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);
})->with(['past' => '2026-11-10', 'wrong day' => '2026-12-11']);

test('a subscription cannot be resumed while its card is deactivated', function () {
    $subscription = Subscription::factory()->paused()->chargingOn(CarbonImmutable::parse('2026-12-10'))->create();
    $subscription->creditCard->forceFill(['deactivated_at' => now()])->save();

    Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])
        ->call('openResume')
        ->call('confirmResume')
        ->assertHasErrors(['resume_date']);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);
});

test('cancel and reactivate keep every row and the full history', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-20'))->create();

    $page = fn () => Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription]);

    $page()->call('openStatusChange', 'cancel')->set('reason', 'เปลี่ยนไปใช้เครื่องมืออื่น')->call('confirmStatusChange');
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

    $page()->assertSee('ยกเลิกแล้ว ไม่มีรอบตัดบัตรถัดไป')->call('openResume')->call('confirmResume')->assertHasNoErrors();

    expect(Subscription::count())->toBe(1)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->statusChanges()->orderBy('id')->pluck('to_status')->map->value->all())->toBe(['cancelled', 'active']);

    $page()->assertSee('เปลี่ยนไปใช้เครื่องมืออื่น');
});

test('status changes that make no sense are refused', function (string $status, string $method, array $args) {
    $subscription = Subscription::factory()->create();
    $subscription->forceFill(['status' => $status])->save();

    Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])
        ->call($method, ...$args)
        ->assertForbidden();

    expect($subscription->fresh()->status->value)->toBe($status);
})->with([
    'resume an active one' => ['active', 'openResume', []],
    'pause a cancelled one' => ['cancelled', 'openStatusChange', ['pause']],
    'cancel a cancelled one' => ['cancelled', 'openStatusChange', ['cancel']],
    'pause a paused one' => ['paused', 'openStatusChange', ['pause']],
]);

test('the model itself refuses invalid status changes', function () {
    $subscription = Subscription::factory()->cancelled()->create();

    expect(fn () => $subscription->changeStatus(SubscriptionStatus::Paused, $this->owner))->toThrow(LogicException::class)
        ->and(fn () => $subscription->changeStatus(SubscriptionStatus::Active, $this->owner))->toThrow(LogicException::class);
});

test('status cannot be set through mass assignment', function () {
    $subscription = new Subscription(['status' => 'cancelled', 'status_changed_at' => now(), 'created_by' => 1]);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->created_by)->toBeNull();
});

test('the database refuses to delete a card, user or subscription that history depends on', function () {
    $subscription = Subscription::factory()->create();
    $subscription->changeStatus(SubscriptionStatus::Paused, $this->owner);

    expect(fn () => $subscription->creditCard->delete())->toThrow(QueryException::class)
        ->and(fn () => $subscription->responsible->delete())->toThrow(QueryException::class)
        ->and(fn () => $subscription->delete())->toThrow(QueryException::class);
});

test('the detail page shows the next six charge dates', function () {
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2027-01-31'), 31)->create();

    $html = Livewire::actingAs($this->owner)->test(SUB_SHOW, ['subscription' => $subscription])->html();

    expectHtmlInOrder($html, ['31 ม.ค. 2570', '28 ก.พ. 2570', '31 มี.ค. 2570', '30 เม.ย. 2570', '31 พ.ค. 2570', '30 มิ.ย. 2570']);
});

test('pausing reminds that the vendor still has to be told', function () {
    $subscription = Subscription::factory()->paused()->create();

    $this->actingAs($this->owner)
        ->get(route('subscriptions.show', $subscription))
        ->assertSee('ไม่ได้หยุดการตัดเงินที่ผู้ให้บริการ');
});
