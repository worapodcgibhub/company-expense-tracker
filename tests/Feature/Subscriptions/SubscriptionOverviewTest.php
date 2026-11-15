<?php

use App\Enums\BillingCycle;
use App\Enums\Currency;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SubscriptionOverview;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
});

function chargingIn(int $days): CarbonImmutable
{
    return CarbonImmutable::today()->addDays($days);
}

/*
|--------------------------------------------------------------------------
| Figures (estimates, per currency)
|--------------------------------------------------------------------------
*/

test('counts and windows only include active subscriptions', function () {
    Subscription::factory()->chargingOn(chargingIn(0))->create();
    Subscription::factory()->chargingOn(chargingIn(7))->create();
    Subscription::factory()->chargingOn(chargingIn(8))->create();
    Subscription::factory()->chargingOn(chargingIn(31))->create();
    Subscription::factory()->chargingOn(chargingIn(-2))->create();
    Subscription::factory()->paused()->chargingOn(chargingIn(3))->create();
    Subscription::factory()->cancelled()->chargingOn(chargingIn(3))->create();

    $overview = new SubscriptionOverview(User::factory()->owner()->create());

    expect($overview->activeCount())->toBe(5)
        ->and($overview->upcoming(7))->toHaveCount(2)
        ->and($overview->upcoming(30))->toHaveCount(3)
        ->and($overview->overdue())->toHaveCount(1);
});

test('"today" follows Bangkok time around midnight', function () {
    Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-16'))->create();
    $owner = User::factory()->owner()->create();

    // 23:59 on the 15th in Bangkok is 16:59 UTC; the charge is still tomorrow.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:59', 'Asia/Bangkok'));
    expect(Subscription::sole()->dueLabel())->toBe('พรุ่งนี้');

    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:01', 'Asia/Bangkok'));
    expect(Subscription::sole()->dueLabel())->toBe('วันนี้')
        ->and((new SubscriptionOverview($owner))->overdue())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-17 00:01', 'Asia/Bangkok'));
    expect(Subscription::sole()->dueLabel())->toBe('เลยกำหนด 1 วัน');
});

test('monthly estimates are totalled per currency and never mixed', function () {
    Subscription::factory()->create(['currency' => Currency::THB, 'estimated_amount' => 59000]);
    Subscription::factory()->create(['currency' => Currency::THB, 'estimated_amount' => 120000, 'billing_cycle' => BillingCycle::Yearly]);
    Subscription::factory()->create(['currency' => Currency::USD, 'estimated_amount' => 5499]);
    Subscription::factory()->paused()->create(['currency' => Currency::USD, 'estimated_amount' => 100000]);

    $totals = (new SubscriptionOverview(User::factory()->owner()->create()))->monthlyEstimateByCurrency();

    // 590.00 + 1,200.00 / 12 = 690.00 THB; USD separately; the paused one is left out.
    expect($totals)->toBe(['THB' => 69000, 'USD' => 5499]);
});

test('yearly amounts are divided by twelve and rounded half up', function (int $yearly, int $monthly) {
    $subscription = Subscription::factory()->make(['billing_cycle' => BillingCycle::Yearly, 'estimated_amount' => $yearly]);

    expect($subscription->monthlyEstimate())->toBe($monthly);
})->with([[120000, 10000], [100, 8], [102, 9], [6, 1], [5, 0]]);

test('staff figures only cover their own subscriptions', function () {
    $staff = User::factory()->create();
    Subscription::factory()->chargingOn(chargingIn(2))->create(['responsible_user_id' => $staff->id, 'estimated_amount' => 10000]);
    Subscription::factory()->chargingOn(chargingIn(2))->create(['estimated_amount' => 99900]);

    $overview = new SubscriptionOverview($staff);

    expect($overview->activeCount())->toBe(1)
        ->and($overview->upcoming(7))->toHaveCount(1)
        ->and($overview->monthlyEstimateByCurrency())->toBe(['THB' => 10000]);
});

test('subscriptions on a deactivated card need a new card unless cancelled', function () {
    $closed = CreditCard::factory()->deactivated()->create();
    Subscription::factory()->create(['credit_card_id' => $closed->id]);
    Subscription::factory()->paused()->create(['credit_card_id' => $closed->id]);
    Subscription::factory()->cancelled()->create(['credit_card_id' => $closed->id]);

    expect((new SubscriptionOverview(User::factory()->owner()->create()))->needingNewCard())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/

test('the dashboard lists charges in the next 30 days with the 7-day count', function () {
    Subscription::factory()->chargingOn(chargingIn(3))->create(['name' => 'Canva ใกล้ตัด', 'estimated_amount' => 59000]);
    Subscription::factory()->chargingOn(chargingIn(20))->create(['name' => 'Notion อีกนาน']);
    Subscription::factory()->chargingOn(chargingIn(45))->create(['name' => 'Zoom เกิน 30 วัน']);
    Subscription::factory()->paused()->chargingOn(chargingIn(3))->create(['name' => 'Slack พักไว้']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="upcoming-charges"', false)
        ->assertSeeInOrder(['Canva ใกล้ตัด', 'Notion อีกนาน'])
        ->assertSee('อีก 3 วัน')
        ->assertDontSee('Zoom เกิน 30 วัน')
        ->assertDontSee('Slack พักไว้')
        ->assertSee('ใช้งานอยู่ <strong class="text-slate-900">3</strong> บริการ', false);
});

test('the dashboard shows monthly estimates per currency', function () {
    Subscription::factory()->create(['currency' => Currency::THB, 'estimated_amount' => 59000]);
    Subscription::factory()->create(['currency' => Currency::USD, 'estimated_amount' => 5499]);

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('dashboard'))
        ->assertSee('฿590.00')
        ->assertSee('$54.99');
});

test('overdue subscriptions and deactivated cards appear under action needed', function () {
    Subscription::factory()->chargingOn(chargingIn(-2))->create(['name' => 'GitHub เลยกำหนด']);
    $closed = CreditCard::factory()->deactivated()->create();
    Subscription::factory()->create(['name' => 'Dropbox บัตรปิด', 'credit_card_id' => $closed->id]);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('dashboard'))
        ->assertSee('data-test="action-items"', false)
        ->assertSee('GitHub เลยกำหนด')
        ->assertSee('เลยกำหนด 2 วัน')
        ->assertSee('Dropbox บัตรปิด')
        ->assertSee('บัตรถูกปิดใช้งาน ต้องเปลี่ยนบัตร')
        // The "nothing to do" empty state is gone. (Its title also appears as
        // the receipts card hint, so check its description instead.)
        ->assertDontSee('ทุกอย่างเรียบร้อยดี');
});

test('staff only see their own subscriptions on the dashboard', function () {
    $staff = User::factory()->create();
    Subscription::factory()->chargingOn(chargingIn(2))->create(['name' => 'Canva ของฉัน', 'responsible_user_id' => $staff->id]);
    Subscription::factory()->chargingOn(chargingIn(2))->create(['name' => 'Figma ของคนอื่น']);

    $this->actingAs($staff)
        ->get(route('dashboard'))
        ->assertSee('Canva ของฉัน')
        ->assertDontSee('Figma ของคนอื่น');
});

/*
|--------------------------------------------------------------------------
| List page and card page
|--------------------------------------------------------------------------
*/

test('the list filters by status and puts the soonest charge first', function () {
    Subscription::factory()->chargingOn(chargingIn(20))->create(['name' => 'Notion ทีหลัง']);
    Subscription::factory()->chargingOn(chargingIn(2))->create(['name' => 'Canva ก่อน']);
    Subscription::factory()->cancelled()->create(['name' => 'Zoom ยกเลิก']);

    $component = Livewire\Livewire::actingAs(User::factory()->owner()->create())->test('pages::subscriptions.index');

    expect($component->instance()->subscriptions()->pluck('name')->all())->toBe(['Canva ก่อน', 'Notion ทีหลัง']);

    $component->call('setStatus', 'cancelled');
    expect($component->instance()->subscriptions()->pluck('name')->all())->toBe(['Zoom ยกเลิก']);
});

test('the list can be searched and filtered by category, cycle and card', function () {
    $card = CreditCard::factory()->create();
    Subscription::factory()->create(['name' => 'Canva Pro', 'vendor' => 'Canva Pty', 'category' => 'design', 'credit_card_id' => $card->id]);
    Subscription::factory()->create(['name' => 'AWS', 'vendor' => 'Amazon', 'category' => 'cloud_hosting', 'billing_cycle' => BillingCycle::Yearly]);

    $page = fn () => Livewire\Livewire::actingAs(User::factory()->owner()->create())->test('pages::subscriptions.index');
    $names = fn ($component) => $component->instance()->subscriptions()->pluck('name')->all();

    expect($names($page()->set('search', 'amazon')))->toBe(['AWS'])
        ->and($names($page()->set('category', 'design')))->toBe(['Canva Pro'])
        ->and($names($page()->set('cycle', 'yearly')))->toBe(['AWS'])
        ->and($names($page()->set('card', (string) $card->id)))->toBe(['Canva Pro']);
});

test('the card filter is ignored for staff', function () {
    $staff = User::factory()->create();
    $card = CreditCard::factory()->create();
    Subscription::factory()->create(['name' => 'ของฉัน', 'responsible_user_id' => $staff->id]);

    $component = Livewire\Livewire::actingAs($staff)->test('pages::subscriptions.index')->set('card', (string) $card->id);

    expect($component->instance()->subscriptions()->pluck('name')->all())->toBe(['ของฉัน'])
        ->and($component->instance()->cards())->toHaveCount(0);
});

test('the card page shows how many open subscriptions use each card', function () {
    $card = CreditCard::factory()->create(['name' => 'บัตรหลัก']);
    Subscription::factory()->count(2)->create(['credit_card_id' => $card->id]);
    Subscription::factory()->cancelled()->create(['credit_card_id' => $card->id]);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('credit-cards.index'))
        ->assertSee('ใช้กับ Subscription 2 รายการ')
        ->assertSee('ยังมี Subscription 2 รายการใช้บัตรนี้อยู่');
});
