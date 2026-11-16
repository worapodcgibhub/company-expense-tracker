<?php

use App\Enums\UserRole;
use App\Models\AccountingPeriodClosure;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
    $this->staff = User::factory()->create();
});

test('guests are redirected to the login page', function () {
    $charge = SubscriptionCharge::factory()->create();

    $this->get(route('charges.index'))->assertRedirect(route('login'));
    $this->get(route('charges.show', $charge))->assertRedirect(route('login'));
    $this->get(route('accounting-periods.index'))->assertRedirect(route('login'));
});

test('owner and accountant see every charge', function (UserRole $role) {
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['name' => 'Canva ทีมการตลาด']))->create();
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['name' => 'Figma ทีมออกแบบ']))->create();

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('charges.index'))
        ->assertOk()
        ->assertSee('Canva ทีมการตลาด')
        ->assertSee('Figma ทีมออกแบบ');
})->with([UserRole::Owner, UserRole::Accountant]);

test('staff see only charges of subscriptions they are responsible for', function () {
    $own = SubscriptionCharge::factory()->for(Subscription::factory()->create(['name' => 'Canva ของฉัน', 'responsible_user_id' => $this->staff->id]))->create();
    $other = SubscriptionCharge::factory()->for(Subscription::factory()->create(['name' => 'Figma ของคนอื่น']))->create();

    $this->actingAs($this->staff)
        ->get(route('charges.index'))
        ->assertOk()
        ->assertSee('Canva ของฉัน')
        ->assertDontSee('Figma ของคนอื่น')
        // No company-wide card filter for Staff.
        ->assertDontSee('ทุกบัตร');

    $this->actingAs($this->staff)->get(route('charges.show', $own))->assertOk()->assertDontSee('data-test="edit-charge-button"', false);
    $this->actingAs($this->staff)->get(route('charges.show', $other))->assertForbidden();
});

test('staff totals and dashboard only count their own subscriptions', function () {
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['responsible_user_id' => $this->staff->id]))->chargedOn(CarbonImmutable::parse('2026-11-10'))->create(['amount' => 10_000]);
    SubscriptionCharge::factory()->for(Subscription::factory()->create())->chargedOn(CarbonImmutable::parse('2026-11-10'))->create(['amount' => 99_900]);

    $this->actingAs($this->staff)->get(route('charges.index'))->assertSee('฿100.00')->assertDontSee('฿1,099.00');
    $this->actingAs($this->staff)->get(route('dashboard'))->assertSee('฿100.00')->assertDontSee('฿999.00');
});

test('only the owner can record or correct a charge', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['responsible_user_id' => $user->id]);
    $charge = SubscriptionCharge::factory()->for($subscription)->chargedOn(CarbonImmutable::parse('2026-10-14'))->create();

    $this->actingAs($user)->get(route('charges.create', $subscription))->assertForbidden();
    $this->actingAs($user)->get(route('charges.edit', $charge))->assertForbidden();
    $this->actingAs($user)->get(route('subscriptions.show', $subscription))->assertOk()->assertDontSee('data-test="record-charge-button"', false);

    Livewire::actingAs($user)->test('pages::charges.form', ['subscription' => $subscription])->assertForbidden();
    Livewire::actingAs($user)->test('pages::charges.form', ['charge' => $charge])->assertForbidden();
    expect(SubscriptionCharge::count())->toBe(1);
})->with([UserRole::Accountant, UserRole::Staff]);

test('a deactivated owner cannot record', function () {
    $owner = User::factory()->owner()->deactivated()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create();

    expect($owner->can('create', [SubscriptionCharge::class, $subscription]))->toBeFalse();
});

test('only the accountant verifies charges', function () {
    $charge = SubscriptionCharge::factory()->create();

    expect($this->accountant->can('verify', $charge))->toBeTrue()
        ->and($this->owner->can('verify', $charge))->toBeFalse()
        ->and($this->staff->can('verify', $charge))->toBeFalse();

    $this->actingAs($this->accountant)->get(route('charges.show', $charge))
        ->assertSee('data-test="verify-button"', false)
        ->assertDontSee('data-test="edit-charge-button"', false);

    Livewire::actingAs($this->owner)->test('pages::charges.show', ['charge' => $charge])->call('verify')->assertForbidden();
    expect($charge->fresh()->isVerified())->toBeFalse();
});

test('the sidebar shows the charge menu to everyone and the period menu to owner and accountant only', function () {
    $this->actingAs($this->staff)->get(route('dashboard'))
        ->assertSee('data-test="charges-menu"', false)
        ->assertDontSee('data-test="accounting-periods-menu"', false);

    $this->actingAs($this->accountant)->get(route('dashboard'))
        ->assertSee('data-test="charges-menu"', false)
        ->assertSee('data-test="accounting-periods-menu"', false);
});

/*
|--------------------------------------------------------------------------
| Accounting periods
|--------------------------------------------------------------------------
*/

test('staff cannot open the accounting periods page', function () {
    $this->actingAs($this->staff)->get(route('accounting-periods.index'))->assertForbidden();
});

test('owner and accountant can close an ended month, which saves its figures', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    SubscriptionCharge::factory()->chargedOn(CarbonImmutable::parse('2026-10-05'))->create(['amount' => 50_000]);
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['currency' => 'USD']))->usd(1_500)->chargedOn(CarbonImmutable::parse('2026-10-06'))->create();

    Livewire::actingAs($user)->test('pages::accounting-periods.index')
        ->call('openClose', '2026-10')
        ->set('reason', 'ตรวจยอดเดือนตุลาคมเสร็จ')
        ->call('confirmClose')
        ->assertHasNoErrors();

    $closure = AccountingPeriodClosure::sole();
    expect($closure->period)->toBe('2026-10')
        ->and($closure->isClosing())->toBeTrue()
        ->and($closure->acted_by)->toBe($user->id)
        ->and($closure->snapshot)->toBe(['thb_total' => 50_000, 'charge_count' => 2, 'missing_thb_count' => 1, 'missing_thb_usd' => 1_500]);
})->with([UserRole::Owner, UserRole::Accountant]);

test('the current month cannot be closed', function () {
    Livewire::actingAs($this->accountant)->test('pages::accounting-periods.index')->call('openClose', '2026-11')->assertNotFound();

    expect(AccountingPeriodClosure::count())->toBe(0);
});

test('reopening needs a reason and keeps the full log', function () {
    AccountingPeriodClosure::close('2026-10', $this->accountant);

    $page = Livewire::actingAs($this->accountant)->test('pages::accounting-periods.index')->call('openReopen', '2026-10');
    $page->set('reason', '')->call('confirmReopen')->assertHasErrors('reason');
    expect(AccountingPeriodClosure::isClosed('2026-10'))->toBeTrue();

    $page->set('reason', 'พบรายการตกหล่น')->call('confirmReopen')->assertHasNoErrors();

    expect(AccountingPeriodClosure::isClosed('2026-10'))->toBeFalse()
        ->and(AccountingPeriodClosure::orderBy('id')->pluck('action')->all())->toBe(['closed', 'reopened']);

    $this->actingAs($this->accountant)->get(route('accounting-periods.index'))
        ->assertSee('data-test="period-log"', false)
        ->assertSee('พบรายการตกหล่น');
});
