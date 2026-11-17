<?php

use App\Enums\Currency;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Baseline: pins how real payments are added up today, so later changes
// (new payment methods, one-off expenses) cannot silently change old figures.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
});

function ledgerCharge(string $chargedOn, int $amount, ?string $dueDate = null): SubscriptionCharge
{
    $charge = SubscriptionCharge::factory()
        ->chargedOn(CarbonImmutable::parse($chargedOn), $dueDate !== null ? CarbonImmutable::parse($dueDate) : null)
        ->create(['amount' => $amount]);

    // Plain YYYY-MM-DD here; dates stored with a time (like the real data)
    // are covered by MonthEndTotalsTest.
    DB::table('subscription_charges')->where('id', $charge->id)->update(['charged_on' => $chargedOn]);

    return $charge->refresh();
}

function ledgerUsdCharge(string $chargedOn, int $usd, ?int $thb): SubscriptionCharge
{
    return SubscriptionCharge::factory()
        ->for(Subscription::factory()->create(['currency' => Currency::USD]))
        ->usd($usd, $thb)
        ->chargedOn(CarbonImmutable::parse($chargedOn))
        ->create();
}

test('a charge belongs to the month it was charged, not the month it was due', function () {
    ledgerCharge('2026-10-01', 10_000, dueDate: '2026-09-30');
    ledgerCharge('2026-10-31', 20_000);
    ledgerCharge('2026-11-01', 30_000, dueDate: '2026-10-31');

    expect(ChargeOverview::snapshotFor('2026-09')['thb_total'])->toBe(0)
        ->and(ChargeOverview::snapshotFor('2026-10')['thb_total'])->toBe(30_000)
        ->and(ChargeOverview::snapshotFor('2026-10')['charge_count'])->toBe(2)
        ->and(ChargeOverview::snapshotFor('2026-11')['thb_total'])->toBe(30_000);
});

test('the monthly figures add up to every real payment exactly once', function () {
    ledgerCharge('2026-08-05', 10_000);
    ledgerUsdCharge('2026-09-10', 2_000, 72_000);
    ledgerUsdCharge('2026-09-12', 1_000, null);
    SubscriptionCharge::factory()->skipped()->create(['due_date' => '2026-10-03']);
    ledgerCharge('2026-10-20', 5_500);
    ledgerCharge('2026-11-02', 3_000);

    $periods = ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];
    $figures = array_map(fn (string $period) => ChargeOverview::snapshotFor($period), $periods);

    $everyCharge = SubscriptionCharge::query()->charged()->get();

    // Nothing is counted twice, nothing falls outside the months, and the
    // sums match the charges themselves.
    expect(array_sum(array_column($figures, 'charge_count')))->toBe($everyCharge->count())->toBe(5)
        ->and(array_sum(array_column($figures, 'thb_total')))->toBe(10_000 + 72_000 + 5_500 + 3_000)
        ->and(array_sum(array_column($figures, 'thb_total')))->toBe((int) $everyCharge->sum(fn (SubscriptionCharge $charge) => $charge->thbAmount() ?? 0))
        ->and(array_sum(array_column($figures, 'missing_thb_count')))->toBe(1)
        ->and(array_sum(array_column($figures, 'missing_thb_usd')))->toBe(1_000);
});
