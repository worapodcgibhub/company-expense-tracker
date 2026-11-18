<?php

use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use App\Support\MonthlyReport;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Mini MVP round 2: the monthly report of what was really paid, from
 * subscription charges and one-off expenses together. Different tables, so
 * nothing is counted twice; estimates are never included; baht and dollars
 * stay apart.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
    $this->staff = User::factory()->create();
});

function reportCharge(string $date, int $amount, array $attributes = [], ?Subscription $subscription = null): SubscriptionCharge
{
    $subscription ??= Subscription::factory()->create();

    return SubscriptionCharge::factory()->for($subscription)->chargedOn(CarbonImmutable::parse($date))->create(['amount' => $amount, ...$attributes]);
}

function reportFor(User $user, string $month = '2026-11-01'): MonthlyReport
{
    return new MonthlyReport($user, CarbonImmutable::parse($month));
}

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

test('baht paid is the subscription charges plus the one-off expenses of the month, each counted once', function () {
    reportCharge('2026-11-03', 180_000);
    reportCharge('2026-11-12', 59_000);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-05'))->create(['amount' => 250_000]);

    $summary = reportFor($this->owner)->summary();

    expect($summary['thb'])->toBe(['subscription' => 239_000, 'one_off' => 250_000, 'total' => 489_000])
        ->and($summary['counts'])->toBe(['subscription' => 2, 'one_off' => 1, 'total' => 3])
        ->and(reportFor($this->owner)->rows())->toHaveCount(3);
});

test('a one-off with the same name and amount as a subscription charge is still a separate payment', function () {
    $s = Subscription::factory()->create(['name' => 'ค่าเช่า Colocation']);
    reportCharge('2026-11-03', 850_000, [], $s);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-03'))->create(['title' => 'ค่าเช่า Colocation', 'amount' => 850_000]);

    expect(reportFor($this->owner)->summary()['thb']['total'])->toBe(1_700_000);
});

test('estimates, skipped cycles and other months are never included', function () {
    Subscription::factory()->create(['estimated_amount' => 9_900_000]);
    $skipped = Subscription::factory()->create();
    SubscriptionCharge::factory()->for($skipped)->skipped()->create(['due_date' => '2026-11-10']);
    reportCharge('2026-10-31', 111_100);
    reportCharge('2026-12-01', 222_200);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-10-31'))->create(['amount' => 333_300]);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-12-01'))->create(['amount' => 444_400]);
    reportCharge('2026-11-30', 100_000);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-01'))->create(['amount' => 200_000]);

    $summary = reportFor($this->owner)->summary();

    expect($summary['thb']['total'])->toBe(300_000)
        ->and($summary['counts']['total'])->toBe(2);
});

test('the subscription figure is exactly the one the dashboard and charge list use', function () {
    reportCharge('2026-11-03', 180_000);
    reportCharge('2026-11-04', 2_000, ['currency' => 'USD', 'amount_thb' => 72_000]);
    reportCharge('2026-11-05', 3_000, ['currency' => 'USD', 'amount_thb' => null]);

    $existing = (new ChargeOverview($this->owner, now()))->monthSummary();
    $report = reportFor($this->owner)->summary();

    expect($report['thb']['subscription'])->toBe($existing['thb_total'])
        ->and($report['counts']['subscription'])->toBe($existing['charge_count'])
        ->and($report['missing_thb']['count'])->toBe($existing['missing_thb_count'])
        ->and($report['missing_thb']['usd'])->toBe($existing['missing_thb_usd']);
});

test('dollars are reported apart and never converted by an assumed rate', function () {
    reportCharge('2026-11-04', 2_000, ['currency' => 'USD', 'amount_thb' => 72_000]);
    reportCharge('2026-11-05', 3_000, ['currency' => 'USD', 'amount_thb' => null]);
    reportCharge('2026-11-06', 100_000);

    $summary = reportFor($this->owner)->summary();

    // Baht: 72,000 (what the bank really took) + 100,000 THB. The USD row without a baht amount adds nothing.
    expect($summary['thb']['total'])->toBe(172_000)
        ->and($summary['foreign'])->toBe(['count' => 2, 'usd' => 5_000])
        ->and($summary['missing_thb'])->toBe(['count' => 1, 'usd' => 3_000]);
});

test('an empty month is all zero', function () {
    $summary = reportFor($this->owner, '2026-09-01')->summary();

    expect($summary['thb']['total'])->toBe(0)
        ->and($summary['counts']['total'])->toBe(0)
        ->and($summary['foreign'])->toBe(['count' => 0, 'usd' => 0])
        ->and($summary['receipts']['waiting'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Receipts
|--------------------------------------------------------------------------
*/

test('receipts still missing are counted from both sources with the existing statuses', function () {
    reportCharge('2026-11-03', 100_000);
    reportCharge('2026-11-04', 100_000);
    reportCharge('2026-11-05', 100_000, ['receipt_unavailable_at' => now(), 'receipt_unavailable_reason' => 'ไม่มีใบเสร็จ']);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-06'))->create();
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-07'))->receiptReceived()->create();
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-10-07'))->create();

    $receipts = reportFor($this->owner)->summary()['receipts'];

    expect($receipts['subscription'])->toBe(['waiting' => 2, 'unavailable' => 1, 'attached' => 0])
        ->and($receipts['one_off'])->toBe(['waiting' => 1, 'attached' => 1])
        ->and($receipts['waiting'])->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Who sees what
|--------------------------------------------------------------------------
*/

test('Staff see only their own expenses and the charges of subscriptions they are responsible for', function () {
    $mine = Subscription::factory()->create(['responsible_user_id' => $this->staff->id]);
    reportCharge('2026-11-03', 100_000, [], $mine);
    reportCharge('2026-11-03', 700_000);
    Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-11-04'))->create(['amount' => 20_000]);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-04'))->create(['amount' => 90_000]);

    expect(reportFor($this->staff)->summary()['thb']['total'])->toBe(120_000)
        ->and(reportFor($this->owner)->summary()['thb']['total'])->toBe(910_000)
        ->and(reportFor($this->accountant)->summary()['thb']['total'])->toBe(910_000);
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the page opens for every role and shows this month by default', function () {
    reportCharge('2026-11-03', 180_000);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-05'))->create(['amount' => 250_000]);

    foreach (['owner', 'accountant', 'staff'] as $role) {
        $this->actingAs($this->{$role})->get(route('reports.monthly'))->assertOk()->assertSee('รายงานรายเดือน');
    }

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->assertSet('year', '2026')
        ->assertSet('month', '11')
        ->assertSee('พฤศจิกายน 2569')
        ->assertSee('฿4,300.00')
        ->assertSee('Subscription ฿1,800.00 · ทั่วไป ฿2,500.00')
        ->assertSeeHtml('data-test="report-row-subscription"')
        ->assertSeeHtml('data-test="report-row-one_off"');
});

test('choosing another month and year shows that month only', function () {
    reportCharge('2026-11-03', 180_000);
    reportCharge('2026-09-10', 77_700);
    Expense::factory()->paidOn(CarbonImmutable::parse('2025-12-20'))->create(['title' => 'ค่าซ่อมปีก่อน', 'amount' => 55_500]);

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->set('month', '9')
        ->assertSee('กันยายน 2569')
        ->assertSee('฿777.00')
        ->assertDontSee('฿1,800.00')
        ->set('year', '2025')
        ->set('month', '12')
        ->assertSee('ธันวาคม 2568')
        ->assertSee('ค่าซ่อมปีก่อน')
        ->assertSee('฿555.00');
});

test('the year list reaches back to the earliest payment', function () {
    Expense::factory()->paidOn(CarbonImmutable::parse('2024-03-01'))->create();

    $years = Livewire::actingAs($this->owner)->test('pages::reports.monthly')->instance()->years;

    expect($years)->toBe([2026, 2025, 2024]);
});

test('a wrong month or year falls back to this month', function () {
    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->set('month', '13')
        ->set('year', 'abc')
        ->assertSee('พฤศจิกายน 2569');
});

test('the missing-receipt filter lists only rows still waiting', function () {
    reportCharge('2026-11-03', 100_000, [], Subscription::factory()->create(['name' => 'มีใบเสร็จแล้ว']))
        ->forceFill(['receipt_unavailable_at' => now(), 'receipt_unavailable_reason' => 'ไม่มี'])->save();
    reportCharge('2026-11-04', 100_000, [], Subscription::factory()->create(['name' => 'ยังขาดใบเสร็จ-ซับ']));
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-05'))->create(['title' => 'ยังขาดใบเสร็จ-ทั่วไป']);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-06'))->receiptReceived()->create(['title' => 'ได้รับใบเสร็จแล้ว']);

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->assertSee('มีใบเสร็จแล้ว')
        ->assertSee('ได้รับใบเสร็จแล้ว')
        ->set('onlyMissing', true)
        ->assertSee('ยังขาดใบเสร็จ-ซับ')
        ->assertSee('ยังขาดใบเสร็จ-ทั่วไป')
        ->assertDontSee('มีใบเสร็จแล้ว')
        ->assertDontSee('ได้รับใบเสร็จแล้ว');
});

test('USD rows show baht only when it is known, and the notice appears for the ones waiting', function () {
    reportCharge('2026-11-04', 2_000, ['currency' => 'USD', 'amount_thb' => 72_000]);
    reportCharge('2026-11-05', 3_000, ['currency' => 'USD', 'amount_thb' => null]);

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->assertSee('฿720.00')
        ->assertSee('รอยอดบาท')
        ->assertSeeHtml('data-test="report-missing-thb"')
        ->assertSee('$50.00');
});

test('a closed month whose subscription figure changed since closing is explained, not hidden', function () {
    reportCharge('2026-10-10', 100_000);
    AccountingPeriodClosure::close('2026-10', $this->owner);

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->set('month', '10')
        ->assertSeeHtml('data-test="report-closed"')
        ->assertDontSeeHtml('data-test="report-closed-diff"');

    reportCharge('2026-10-20', 50_000, ['entered_after_close' => true]);

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')
        ->set('month', '10')
        ->assertSeeHtml('data-test="report-closed-diff"')
        ->assertSee('฿1,000.00')
        ->assertSee('฿1,500.00');
});

test('viewing the report changes no data', function () {
    reportCharge('2026-11-03', 180_000);
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-05'))->create();
    $before = [Subscription::query()->get()->toArray(), SubscriptionCharge::query()->get()->toArray(), Expense::query()->get()->toArray()];

    Livewire::actingAs($this->owner)->test('pages::reports.monthly')->set('month', '10')->set('onlyMissing', true);
    $this->actingAs($this->staff)->get(route('reports.monthly'))->assertOk();

    expect([Subscription::query()->get()->toArray(), SubscriptionCharge::query()->get()->toArray(), Expense::query()->get()->toArray()])->toEqual($before);
});

test('guests are sent to log in and the menu entry is shown', function () {
    auth()->logout();
    $this->get(route('reports.monthly'))->assertRedirect(route('login'));

    $this->actingAs($this->staff)->get(route('dashboard'))->assertSee('รายงานรายเดือน');
});
