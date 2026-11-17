<?php

use App\Enums\ChargeOutcome;
use App\Models\AccountingPeriodClosure;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;

// Baseline: the figures saved when a month is closed must never change by
// themselves. Complements the closing tests in tests/Feature/Charges.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function ledgerOctoberCharge(int $amount, ?int $usdThb = null): SubscriptionCharge
{
    $factory = SubscriptionCharge::factory()->chargedOn(CarbonImmutable::parse('2026-10-10'));

    return $usdThb !== null
        ? $factory->for(Subscription::factory()->create(['currency' => 'USD']))->usd($amount, $usdThb)->create()
        : $factory->create(['amount' => $amount]);
}

test('a snapshot recomputed with nothing changed equals the one saved at closing', function () {
    ledgerOctoberCharge(40_000);
    ledgerOctoberCharge(2_000, 72_000);
    SubscriptionCharge::factory()->for(Subscription::factory()->create(['currency' => 'USD']))->usd(1_500)->chargedOn(CarbonImmutable::parse('2026-10-12'))->create();

    $closure = AccountingPeriodClosure::close('2026-10', $this->owner);

    expect($closure->fresh()->snapshot)->toBe(ChargeOverview::snapshotFor('2026-10'))
        ->and($closure->snapshot['missing_thb_count'])->toBe(1);
});

test('filling in a missing baht amount after closing leaves the closing figures untouched', function () {
    $charge = SubscriptionCharge::factory()
        ->for(Subscription::factory()->create(['currency' => 'USD']))
        ->usd(2_000)
        ->chargedOn(CarbonImmutable::parse('2026-10-10'))
        ->create();

    $closure = AccountingPeriodClosure::close('2026-10', $this->owner);
    $saved = $closure->snapshot;

    $charge->revise([
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::parse('2026-10-10'),
        'amount' => 2_000,
        'amount_thb' => 72_000,
        'credit_card_id' => $charge->credit_card_id,
        'notes' => null,
    ], $this->owner, 'ได้ใบแจ้งยอดบัตรแล้ว', confirmClosedPeriod: true);

    expect($closure->fresh()->snapshot)->toBe($saved)
        ->and($saved['missing_thb_count'])->toBe(1)
        // The live figure moved on, so the difference stays visible.
        ->and(ChargeOverview::snapshotFor('2026-10'))->not->toBe($saved)
        ->and(ChargeOverview::snapshotFor('2026-10')['thb_total'])->toBe(72_000)
        ->and($charge->fresh()->entered_after_close)->toBeTrue();
});

test('reopening and closing again keeps every earlier closing figure in the log', function () {
    ledgerOctoberCharge(40_000);
    $first = AccountingPeriodClosure::close('2026-10', $this->owner);

    AccountingPeriodClosure::reopen('2026-10', $this->owner, 'พบรายการตกหล่น');
    ledgerOctoberCharge(5_000);
    $second = AccountingPeriodClosure::close('2026-10', $this->owner);

    $rows = AccountingPeriodClosure::orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['closed', 'reopened', 'closed'])
        ->and($rows[0]->snapshot['thb_total'])->toBe(40_000)
        ->and($rows[1]->snapshot)->toBeNull()
        ->and($rows[2]->snapshot['thb_total'])->toBe(45_000)
        ->and($first->fresh()->snapshot['thb_total'])->toBe(40_000)
        ->and($second->fresh()->snapshot['thb_total'])->toBe(45_000);
});
