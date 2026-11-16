<?php

use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Models\AccountingPeriodClosure;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function thbCharge(string $date, int $amount, array $subscription = []): SubscriptionCharge
{
    return SubscriptionCharge::factory()
        ->for(Subscription::factory()->create($subscription))
        ->chargedOn(CarbonImmutable::parse($date))
        ->create(['amount' => $amount]);
}

function usdCharge(string $date, int $usd, ?int $thb, array $subscription = []): SubscriptionCharge
{
    return SubscriptionCharge::factory()
        ->for(Subscription::factory()->create(['currency' => Currency::USD, ...$subscription]))
        ->usd($usd, $thb)
        ->chargedOn(CarbonImmutable::parse($date))
        ->create();
}

test('the baht total adds THB amounts and the baht paid for USD charges, never USD itself', function () {
    thbCharge('2026-11-03', 59_000);
    usdCharge('2026-11-05', 2_000, 70_240);      // $20.00 paid as ฿702.40
    usdCharge('2026-11-06', 5_499, null);        // $54.99, baht not known yet
    thbCharge('2026-10-30', 11_100);             // last month

    $summary = (new ChargeOverview($this->owner))->monthSummary();

    expect($summary)->toBe([
        'thb_total' => 59_000 + 70_240,
        'charge_count' => 3,
        'missing_thb_count' => 1,
        'missing_thb_usd' => 5_499,
    ]);
});

test('skipped cycles cost nothing and are not counted as charges', function () {
    thbCharge('2026-11-03', 59_000);
    SubscriptionCharge::factory()->skipped()->create(['due_date' => '2026-11-04']);

    expect((new ChargeOverview($this->owner))->monthSummary())
        ->thb_total->toBe(59_000)
        ->charge_count->toBe(1);
});

test('the dashboard shows real totals and lists USD charges waiting for baht separately', function () {
    thbCharge('2026-11-03', 59_000, ['name' => 'Canva']);
    usdCharge('2026-11-05', 2_000, 70_240, ['name' => 'GitHub']);
    usdCharge('2026-11-06', 5_499, null, ['name' => 'Adobe']);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('฿1,292.40')
        ->assertSee('ยังไม่รวม USD 1 รายการ ($54.99) ที่รอยอดบาท')
        ->assertSee('3 รายการ')
        ->assertSee('data-test="missing-thb"', false)
        ->assertSee('data-test="recent-expenses"', false)
        ->assertSee('Adobe')
        ->assertSee('GitHub')
        ->assertSee('รอยอดเงินบาท');
});

test('the dashboard has no missing-baht section when every USD charge has its baht amount', function () {
    usdCharge('2026-11-05', 2_000, 70_240);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertDontSee('data-test="missing-thb"', false);
});

test('the category breakdown uses baht only', function () {
    thbCharge('2026-11-03', 30_000, ['category' => SubscriptionCategory::Design]);
    usdCharge('2026-11-05', 2_000, 70_000, ['category' => SubscriptionCategory::Software]);
    usdCharge('2026-11-06', 9_999, null, ['category' => SubscriptionCategory::Marketing]);

    $segments = (new ChargeOverview($this->owner))->categoryBreakdown();

    expect(array_column($segments, 'value', 'label'))->toBe([
        SubscriptionCategory::Software->label() => 70_000,
        SubscriptionCategory::Design->label() => 30_000,
    ]);
});

test('the chart shows six months of baht paid', function () {
    thbCharge('2026-11-03', 10_000);
    thbCharge('2026-09-10', 20_000);
    thbCharge('2026-05-31', 99_000); // outside the six months

    $series = (new ChargeOverview($this->owner))->monthlySeries(6);

    expect(array_column($series, 'value'))->toBe([0, 0, 0, 20_000, 0, 10_000])
        ->and(end($series)['current'])->toBeTrue();
});

test('a closed month keeps its closing figure for owner and accountant even after late entries', function () {
    thbCharge('2026-10-05', 40_000);
    AccountingPeriodClosure::close('2026-10', $this->owner);

    // Late entry in the closed month.
    thbCharge('2026-10-20', 5_000)->forceFill(['entered_after_close' => true])->save();

    $series = (new ChargeOverview($this->owner))->monthlySeries(6);
    $october = $series[4];

    expect($october['value'])->toBe(40_000)
        ->and($october['closed'])->toBeTrue()
        // The live figure does include it, so the difference is visible.
        ->and(ChargeOverview::snapshotFor('2026-10')['thb_total'])->toBe(45_000);

    $this->actingAs($this->owner)->get(route('accounting-periods.index'))
        ->assertSee('ย้อนหลัง 1 รายการ')
        ->assertSee('ต่างจากวันปิดงวด');
});

test('late entries waiting for the accountant appear in the action list', function () {
    $charge = thbCharge('2026-10-20', 5_000, ['name' => 'Zoom ทีมขาย']);
    $charge->forceFill(['entered_after_close' => true])->save();

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertSee('data-test="action-items"', false)
        ->assertSee('Zoom ทีมขาย')
        ->assertSee('รายการย้อนหลังในงวดที่ปิดแล้ว');

    $charge->verify(User::factory()->accountant()->create());

    $this->actingAs($this->owner)->get(route('dashboard'))->assertDontSee('รายการย้อนหลังในงวดที่ปิดแล้ว');
});

test('the charge list filters by month and status and shows baht totals', function () {
    thbCharge('2026-11-03', 59_000, ['name' => 'Canva']);
    usdCharge('2026-11-06', 5_499, null, ['name' => 'Adobe']);
    thbCharge('2026-10-03', 11_100, ['name' => 'Slack เดือนก่อน']);

    $this->actingAs($this->owner)->get(route('charges.index'))
        ->assertSee('Canva')
        ->assertSee('Adobe')
        ->assertDontSee('Slack เดือนก่อน')
        ->assertSee('฿590.00');

    $this->actingAs($this->owner)->get(route('charges.index', ['month' => '2026-10']))
        ->assertSee('Slack เดือนก่อน')
        ->assertDontSee('Canva');

    $this->actingAs($this->owner)->get(route('charges.index', ['flag' => 'missing_thb']))
        ->assertSee('Adobe')
        ->assertDontSee('Canva');
});
