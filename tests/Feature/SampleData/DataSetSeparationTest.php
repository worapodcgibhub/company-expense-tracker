<?php

use App\Enums\DataSet;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use App\Support\MonthlyReport;
use App\Support\SubscriptionOverview;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
 * Sample data is kept on purpose for trying the system out, but must never
 * mix into real money: subscriptions carry `is_sample`; their charges,
 * documents and history follow them. Dashboard and report default to real data.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function sampleCharge(bool $sample, int $amount, string $date = '2026-11-03'): SubscriptionCharge
{
    $subscription = $sample ? Subscription::factory()->sample()->create() : Subscription::factory()->create();

    return SubscriptionCharge::factory()->for($subscription)->chargedOn(CarbonImmutable::parse($date))->create(['amount' => $amount]);
}

test('is_sample defaults to false and cannot be mass assigned by a form', function () {
    $real = Subscription::factory()->create();
    $sample = Subscription::factory()->sample()->create();

    expect($real->fresh()->is_sample)->toBeFalse()
        ->and($sample->fresh()->is_sample)->toBeTrue()
        ->and((new Subscription)->fill(['name' => 'x', 'is_sample' => true])->is_sample)->toBeFalsy();
});

test('scopes split subscriptions and their charges into real, sample and all', function () {
    $realCharge = sampleCharge(false, 100_000);
    $sampleCharge = sampleCharge(true, 700_000);

    expect(Subscription::inDataSet(DataSet::Real)->count())->toBe(1)
        ->and(Subscription::inDataSet(DataSet::Sample)->count())->toBe(1)
        ->and(Subscription::inDataSet(DataSet::All)->count())->toBe(2)
        ->and(SubscriptionCharge::inDataSet(DataSet::Real)->pluck('id')->all())->toBe([$realCharge->id])
        ->and(SubscriptionCharge::inDataSet(DataSet::Sample)->pluck('id')->all())->toBe([$sampleCharge->id])
        ->and(SubscriptionCharge::inDataSet(DataSet::All)->count())->toBe(2);
});

test('request values map to a data set and anything unknown means real data', function () {
    expect(DataSet::fromRequest('sample'))->toBe(DataSet::Sample)
        ->and(DataSet::fromRequest('all'))->toBe(DataSet::All)
        ->and(DataSet::fromRequest('real'))->toBe(DataSet::Real)
        ->and(DataSet::fromRequest('junk'))->toBe(DataSet::Real)
        ->and(DataSet::fromRequest(null))->toBe(DataSet::Real)
        ->and(DataSet::fromRequest(['sample']))->toBe(DataSet::Real);
});

test('monthly totals separate real money from sample money', function () {
    sampleCharge(false, 100_000);
    sampleCharge(true, 700_000);
    $today = now();

    expect((new ChargeOverview($this->owner, $today, DataSet::Real))->monthSummary()['thb_total'])->toBe(100_000)
        ->and((new ChargeOverview($this->owner, $today, DataSet::Sample))->monthSummary()['thb_total'])->toBe(700_000)
        ->and((new ChargeOverview($this->owner, $today, DataSet::All))->monthSummary()['thb_total'])->toBe(800_000)
        ->and((new ChargeOverview($this->owner, $today))->monthSummary()['thb_total'])->toBe(100_000);
});

test('subscription overview counts only the chosen data set', function () {
    Subscription::factory()->create();
    Subscription::factory()->sample()->count(2)->create();

    expect((new SubscriptionOverview($this->owner, now(), DataSet::Real))->activeCount())->toBe(1)
        ->and((new SubscriptionOverview($this->owner, now(), DataSet::Sample))->activeCount())->toBe(2)
        ->and((new SubscriptionOverview($this->owner, now(), DataSet::All))->activeCount())->toBe(3);
});

test('the monthly report separates data sets and keeps one-off expenses in real and all only', function () {
    sampleCharge(false, 100_000);
    sampleCharge(true, 700_000);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-05'))->create(['amount' => 50_000]);
    $month = CarbonImmutable::parse('2026-11-01');

    $real = (new MonthlyReport($this->owner, $month, DataSet::Real))->summary();
    $sample = (new MonthlyReport($this->owner, $month, DataSet::Sample))->summary();
    $all = (new MonthlyReport($this->owner, $month, DataSet::All))->summary();

    expect($real['thb']['total'])->toBe(150_000)
        ->and($sample['thb']['total'])->toBe(700_000)
        ->and($sample['counts']['one_off'])->toBe(0)
        ->and($all['thb']['total'])->toBe(850_000);
});

test('the closing snapshot counts real data only', function () {
    sampleCharge(false, 100_000);
    sampleCharge(true, 700_000);

    expect(ChargeOverview::snapshotFor('2026-11'))->toMatchArray(['thb_total' => 100_000, 'charge_count' => 1])
        ->and(ChargeOverview::receiptCountsFor('2026-11'))->toBe(['waiting' => 1, 'unavailable' => 0, 'attached' => 0]);
});

test('the chart ignores closing snapshots when sample data is shown', function () {
    sampleCharge(true, 700_000, '2026-10-10');

    $series = (new ChargeOverview($this->owner, now(), DataSet::Sample))->monthlySeries(6);
    $october = collect($series)->firstWhere('label', ThaiFormat::monthShort(CarbonImmutable::parse('2026-10-01')));

    expect($october['value'])->toBe(700_000)->and($october['closed'])->toBeFalse();
});

test('the migration flags only rows carrying the seeder marker and keeps every row', function () {
    // Build the pre-migration state in the test database, then run the migration file again.
    Schema::table('subscriptions', fn ($table) => $table->dropColumn('is_sample'));

    $marker = 'ข้อมูลตัวอย่าง (Sample data)';
    $user = User::factory()->create();
    $card = CreditCard::factory()->create();
    $insert = fn (string $name, ?string $notes) => DB::table('subscriptions')->insert([
        'name' => $name, 'vendor' => 'v', 'category' => 'software', 'responsible_user_id' => $user->id,
        'credit_card_id' => $card->id, 'billing_cycle' => 'monthly', 'billing_day' => 1,
        'next_charge_date' => '2026-12-01', 'currency' => 'THB', 'estimated_amount' => 100, 'notes' => $notes,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $insert('sample one', $marker);
    $insert('real one', 'หมายเหตุจริง');
    $insert('real two', null);

    (require database_path('migrations/2026_11_23_000000_add_is_sample_to_subscriptions.php'))->up();

    expect(DB::table('subscriptions')->count())->toBe(3)
        ->and(DB::table('subscriptions')->where('is_sample', true)->pluck('name')->all())->toBe(['sample one'])
        ->and(DB::table('subscriptions')->where('name', 'sample one')->value('notes'))->toBe($marker)
        ->and(DB::table('subscriptions')->where('is_sample', false)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

test('the dashboard shows real data by default and the switch reaches sample and all', function () {
    $real = Subscription::factory()->create(['name' => 'RealSubXyz']);
    Subscription::factory()->sample()->create(['name' => 'SampleSubXyz']);
    $this->actingAs($this->owner);

    $default = $this->get(route('dashboard'));
    $default->assertOk()->assertSee('data-test="data-set-switch"', false)->assertSee('RealSubXyz')->assertDontSee('SampleSubXyz')->assertDontSee('data-test="data-set-notice"', false);

    $sample = $this->get(route('dashboard', ['data' => 'sample']));
    $sample->assertOk()->assertSee('SampleSubXyz')->assertDontSee('RealSubXyz')->assertSee('data-test="data-set-notice"', false)->assertSee('ไม่ใช่ยอดเงินจริง');

    $this->get(route('dashboard', ['data' => 'all']))->assertOk()->assertSee('SampleSubXyz')->assertSee('RealSubXyz');
    $this->get(route('dashboard', ['data' => 'junk']))->assertOk()->assertDontSee('SampleSubXyz');
});

test('the report page defaults to real data and can show sample data with a warning', function () {
    $this->actingAs($this->owner);
    $real = sampleCharge(false, 100_000);
    $sample = sampleCharge(true, 700_000);

    Livewire::test('pages::reports.monthly')
        ->assertSee('฿1,000.00')->assertDontSee('฿7,000.00')->assertDontSee('data-test="sample-badge"', false)
        ->set('data', 'sample')
        ->assertSee('฿7,000.00')->assertSee('data-test="data-set-notice"', false)->assertSee('data-test="sample-badge"', false)
        ->set('data', 'all')
        ->assertSee('฿8,000.00');
});

test('sample badges appear on the subscription and charge pages', function () {
    $this->actingAs($this->owner);
    $realCharge = sampleCharge(false, 100_000);
    $sampleCharge = sampleCharge(true, 700_000);

    $this->get(route('subscriptions.index'))->assertOk()->assertSee('data-test="sample-badge"', false);
    $this->get(route('subscriptions.show', $sampleCharge->subscription))->assertSee('data-test="sample-badge"', false);
    $this->get(route('subscriptions.show', $realCharge->subscription))->assertDontSee('data-test="sample-badge"', false);
    $this->get(route('charges.show', $sampleCharge))->assertSee('data-test="sample-badge"', false);
    $this->get(route('charges.show', $realCharge))->assertDontSee('data-test="sample-badge"', false);
    $this->get(route('charges.index'))->assertOk()->assertSee('data-test="sample-badge"', false);
});

test('before the is_sample migration runs, everything counts as real and the sample view is empty', function () {
    sampleCharge(false, 100_000);
    Schema::table('subscriptions', fn ($table) => $table->dropColumn('is_sample'));

    expect(Subscription::inDataSet(DataSet::Real)->count())->toBe(1)
        ->and(Subscription::inDataSet(DataSet::All)->count())->toBe(1)
        ->and(Subscription::inDataSet(DataSet::Sample)->count())->toBe(0)
        ->and(SubscriptionCharge::inDataSet(DataSet::Real)->count())->toBe(1)
        ->and(SubscriptionCharge::inDataSet(DataSet::Sample)->count())->toBe(0);
});

test('with real and sample data mixed, each dashboard tab shows its own items, KPIs and chart without duplicates', function () {
    $this->actingAs($this->owner);
    $real = Subscription::factory()->chargingOn(now()->addDays(2))->create(['name' => 'RealDueXyz']);
    $sample = Subscription::factory()->sample()->chargingOn(now()->addDays(3))->create(['name' => 'SampleDueXyz']);
    $sampleOverdue = Subscription::factory()->sample()->chargingOn(now()->subDays(2))->create(['name' => 'SampleLateXyz']);
    SubscriptionCharge::factory()->for($real)->chargedOn(now())->create(['amount' => 100_000]);
    SubscriptionCharge::factory()->for($sample)->chargedOn(now())->create(['amount' => 700_000]);

    foreach ([
        'real' => [['RealDueXyz'], ['SampleDueXyz', 'SampleLateXyz'], '฿1,000.00'],
        'sample' => [['SampleDueXyz', 'SampleLateXyz'], ['RealDueXyz'], '฿7,000.00'],
        'all' => [['RealDueXyz', 'SampleDueXyz', 'SampleLateXyz'], [], '฿8,000.00'],
    ] as $tab => [$seen, $unseen, $total]) {
        $html = $this->get(route('dashboard', ['data' => $tab]))->assertOk()->assertSee($total)->getContent();

        foreach ($seen as $name) {
            expect(substr_count($html, $name))->toBeGreaterThan(0);
        }
        foreach ($unseen as $name) {
            expect($html)->not->toContain($name);
        }
    }

    $sampleHtml = $this->get(route('dashboard', ['data' => 'sample']))->getContent();
    expect($sampleHtml)->toContain('data-test="action-item-')->toContain('SampleLateXyz');
});
