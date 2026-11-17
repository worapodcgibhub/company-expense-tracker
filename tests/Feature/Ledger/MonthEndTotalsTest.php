<?php

use App\Enums\Currency;
use App\Models\AccountingPeriodClosure;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Regression: dates are stored with a time ("2026-10-31 00:00:00"), and the
// monthly queries used to stop at "2026-10-31", leaving month-end charges
// out of the dashboard, the reports and the closing figures.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();

    $make = fn (string $date, array $subscription = []) => SubscriptionCharge::factory()
        ->for(Subscription::factory()->create($subscription))
        ->chargedOn(CarbonImmutable::parse($date));

    $make('2026-10-01')->create(['amount' => 10_000]);
    $make('2026-10-31', ['name' => 'Hosting สิ้นเดือน'])->create(['amount' => 30_000]);
    $make('2026-10-31', ['currency' => Currency::USD])->usd(2_000, 70_000)->create();
    $make('2026-11-01', ['name' => 'Backup ต้นเดือนถัดไป'])->create(['amount' => 5_000]);
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['name' => 'Zoom ข้ามรอบ']))->skipped()->create(['due_date' => CarbonImmutable::parse('2026-10-31')]);

    $this->october = ['thb_total' => 110_000, 'charge_count' => 3, 'missing_thb_count' => 0, 'missing_thb_usd' => 0];
});

test('the test data is stored with a time, like the real data', function () {
    expect(DB::table('subscription_charges')->whereNotNull('charged_on')->pluck('charged_on')->map(fn ($date) => strlen($date))->unique()->all())->toBe([19]);
});

test('month-end charges count in their month everywhere the figures are shown', function () {
    $overview = new ChargeOverview($this->owner);
    $october = CarbonImmutable::parse('2026-10-01');

    expect(ChargeOverview::snapshotFor('2026-10'))->toBe($this->october)
        ->and($overview->monthSummary($october))->toBe($this->october)
        ->and(array_sum(array_column($overview->categoryBreakdown($october), 'value')))->toBe(110_000)
        ->and($overview->monthlySeries(6)[4]['value'])->toBe(110_000)
        ->and(array_sum(ChargeOverview::receiptCountsFor('2026-10')))->toBe(3)
        // The first day of the next month stays in the next month.
        ->and(ChargeOverview::snapshotFor('2026-11')['thb_total'])->toBe(5_000);
});

test('the charge list of a month shows month-end charges and skipped cycles', function () {
    Livewire::actingAs($this->owner)->test('pages::charges.index')
        ->set('month', '2026-10')
        ->assertSee('Hosting สิ้นเดือน')
        ->assertSee('Zoom ข้ามรอบ')
        ->assertDontSee('Backup ต้นเดือนถัดไป');
});

test('closing a month saves the full figure including month-end charges', function () {
    $closure = AccountingPeriodClosure::close('2026-10', $this->owner);

    expect($closure->fresh()->snapshot)->toBe($this->october);
});

test('a month closed earlier keeps its saved figure; the difference is shown, not recalculated', function () {
    // Closed before this fix, so its saved figure missed the month-end charges.
    $closure = new AccountingPeriodClosure;
    $closure->forceFill([
        'period' => '2026-10',
        'action' => AccountingPeriodClosure::CLOSED,
        'snapshot' => ['thb_total' => 10_000, 'charge_count' => 1, 'missing_thb_count' => 0, 'missing_thb_usd' => 0],
        'acted_by' => $this->owner->id,
    ])->save();
    $saved = $closure->snapshot;

    $this->actingAs($this->owner)->get(route('accounting-periods.index'))
        ->assertOk()
        ->assertSee('ต่างจากวันปิดงวด');

    expect((new ChargeOverview($this->owner))->monthlySeries(6)[4]['value'])->toBe(10_000)
        ->and($closure->fresh()->snapshot)->toBe($saved)
        ->and(AccountingPeriodClosure::count())->toBe(1)
        ->and(ChargeOverview::snapshotFor('2026-10'))->toBe($this->october);
});
