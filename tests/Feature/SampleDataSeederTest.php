<?php

use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Enums\ReceiptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ChargeReceipt;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionChargeRevision;
use App\Models\User;
use App\Support\BillingSchedule;
use App\Support\ChargeOverview;
use App\Support\ReceiptStorage;
use App\Support\SubscriptionOverview;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    Storage::fake(ReceiptStorage::DISK);
    $this->owner = User::factory()->owner()->create();
    $this->card = CreditCard::factory()->create();
});

/**
 * Every table the seeder may write to, with its row count.
 *
 * @return array<string, int>
 */
function sampleCounts(): array
{
    return [
        'users' => User::count(),
        'cards' => CreditCard::count(),
        'subscriptions' => Subscription::count(),
        'charges' => SubscriptionCharge::count(),
        'revisions' => SubscriptionChargeRevision::count(),
        'receipts' => ChargeReceipt::count(),
        'files' => count(Storage::disk('local')->allFiles('receipts')),
    ];
}

test('it adds the sample subscriptions with about six months of history, using the existing owner and card', function () {
    $this->seed(SampleDataSeeder::class);

    $catalog = SampleDataSeeder::catalog();
    $subscriptions = Subscription::all();

    expect($subscriptions)->toHaveCount(count($catalog))
        ->and(count($catalog))->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(15)
        ->and($subscriptions->every(fn ($s) => $s->notes === SampleDataSeeder::MARKER))->toBeTrue()
        ->and($subscriptions->pluck('responsible_user_id')->unique()->all())->toBe([$this->owner->id])
        ->and($subscriptions->pluck('credit_card_id')->unique()->all())->toBe([$this->card->id])
        // No new users or cards.
        ->and(User::count())->toBe(1)
        ->and(CreditCard::count())->toBe(1);

    $charges = SubscriptionCharge::query()->charged()->get();
    $months = $charges->map(fn ($c) => $c->charged_on->format('Y-m'))->unique();

    expect($charges->count())->toBeGreaterThan(50)
        ->and($months->count())->toBeGreaterThanOrEqual(6)
        ->and($charges->pluck('currency')->unique()->map->value->sort()->values()->all())->toBe(['THB', 'USD'])
        // Nothing in the future, nothing older than ~7 months.
        ->and($charges->max('charged_on')->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($charges->min('charged_on')->greaterThan(now()->subMonths(8)))->toBeTrue();
});

test('running it again adds nothing', function () {
    $this->seed(SampleDataSeeder::class);
    $first = sampleCounts();

    $this->seed(SampleDataSeeder::class);

    expect(sampleCounts())->toBe($first);
});

test('existing data is never changed, and a sample that already exists is skipped', function () {
    // Real data that happens to share a sample's name.
    $existing = Subscription::factory()->create(['name' => 'ChatGPT Plus', 'vendor' => 'openai', 'estimated_amount' => 12_345, 'notes' => 'ของจริง']);
    $realCharge = SubscriptionCharge::factory()->for($existing)->create(['amount' => 12_345]);
    $other = Subscription::factory()->cancelled()->create(['name' => 'Claude Pro', 'vendor' => 'Anthropic']);

    $snapshot = fn () => [
        $existing->fresh()->getAttributes(),
        $realCharge->fresh()->getAttributes(),
        $other->fresh()->getAttributes(),
        $this->owner->fresh()->getAttributes(),
        $this->card->fresh()->getAttributes(),
    ];
    $before = $snapshot();

    $this->seed(SampleDataSeeder::class);

    expect($snapshot())->toBe($before)
        // Two skipped: the same name + vendor (case ignored), even when cancelled.
        ->and(Subscription::where('name', 'ChatGPT Plus')->count())->toBe(1)
        ->and(Subscription::where('name', 'Claude Pro')->count())->toBe(1)
        ->and(Subscription::where('notes', SampleDataSeeder::MARKER)->count())->toBe(count(SampleDataSeeder::catalog()) - 2)
        ->and($existing->charges()->count())->toBe(1);
});

test('the sample covers the statuses needed for a demo', function () {
    $this->seed(SampleDataSeeder::class);

    $overview = new SubscriptionOverview($this->owner);
    $charges = SubscriptionCharge::query()->withCurrentReceiptCount()->get();
    $statuses = $charges->map(fn ($c) => $c->receiptStatus())->countBy(fn ($s) => $s->value);

    expect($overview->upcoming(7)->count())->toBeGreaterThanOrEqual(3)            // ใกล้ตัดบัตร
        ->and($overview->overdue()->count())->toBeGreaterThanOrEqual(1)            // เลยกำหนด ยังไม่บันทึก
        ->and(Subscription::where('status', SubscriptionStatus::Paused->value)->count())->toBe(1)
        ->and($statuses[ReceiptStatus::Attached->value] ?? 0)->toBeGreaterThan(10)  // เอกสารครบ
        ->and($statuses[ReceiptStatus::Waiting->value] ?? 0)->toBeGreaterThan(5)    // รอใบเสร็จ
        ->and($statuses[ReceiptStatus::Unavailable->value] ?? 0)->toBeGreaterThanOrEqual(2)
        ->and($statuses[ReceiptStatus::NotNeeded->value] ?? 0)->toBe(1)             // รอบที่ไม่ถูกตัดเงิน
        ->and($charges->filter->needsThbAmount()->count())->toBeGreaterThanOrEqual(1)
        ->and($charges->filter->isVerified()->count())->toBe(0)                     // ทั้งหมดรอตรวจสอบ
        ->and(SubscriptionChargeRevision::where('action', ChargeRevisionAction::Updated->value)->count())->toBe(2)
        ->and(ChargeReceipt::whereNotNull('replaces_id')->count())->toBe(1)
        ->and(ChargeReceipt::whereNotNull('removed_at')->count())->toBe(1);
});

test('cycles are consecutive and every next charge date follows the last recorded cycle', function () {
    $this->seed(SampleDataSeeder::class);

    foreach (Subscription::with('charges')->get() as $subscription) {
        $dues = $subscription->charges->sortBy('due_date')->pluck('due_date')->values();

        foreach ($dues as $i => $due) {
            if ($i > 0) {
                expect(BillingSchedule::next($dues[$i - 1], $subscription->billing_cycle, $subscription->billing_day)->format('Y-m-d'))
                    ->toBe($due->format('Y-m-d'));
            }
        }

        if ($dues->isNotEmpty()) {
            expect($subscription->next_charge_date->format('Y-m-d'))
                ->toBe(BillingSchedule::next($dues->last(), $subscription->billing_cycle, $subscription->billing_day)->format('Y-m-d'));
        }
    }
});

test('baht totals only add baht amounts, never USD', function () {
    $this->seed(SampleDataSeeder::class);
    $overview = new ChargeOverview($this->owner);

    foreach (ThaiFormat::recentMonths(6) as $month) {
        $charges = SubscriptionCharge::query()->chargedInMonth($month)->get();
        $expected = $charges->where('currency', Currency::THB)->sum('amount')
            + $charges->where('currency', Currency::USD)->whereNotNull('amount_thb')->sum('amount_thb');

        expect($overview->monthSummary($month)['thb_total'])->toBe($expected);
    }
});

test('the pages show the sample data', function () {
    $this->seed(SampleDataSeeder::class);
    $this->actingAs($this->owner);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('data-test="upcoming-charges"', false)
        ->assertSee('data-test="recent-expenses"', false)
        ->assertSee('data-test="missing-thb"', false)
        ->assertDontSee('กราฟจะแสดงเมื่อมีการบันทึกค่าใช้จ่ายจริง');

    $this->get(route('subscriptions.index', ['status' => 'all']))->assertOk()->assertSee('Google Workspace Business Standard')->assertSee('Zoom Pro');
    $this->get(route('charges.index'))->assertOk()->assertSee('data-test="charge-list"', false);
    $this->get(route('charges.index', ['receipt' => 'waiting', 'month' => 'all']))->assertOk()->assertSee('data-test="receipt-waiting-badge"', false);
    $this->get(route('accounting-periods.index'))->assertOk()->assertSee('รอใบเสร็จ');

    $charge = SubscriptionCharge::query()->receiptStatusIs(ReceiptStatus::Attached)->firstOrFail();
    $this->get(route('charges.show', $charge))->assertOk()->assertSee('แนบเอกสาร');

    $receipt = $charge->receipts()->current()->firstOrFail();
    $response = $this->get(route('receipts.show', $receipt));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF-')->toContain('SAMPLE DOCUMENT');
});

test('nothing is added when there is no active owner', function () {
    $this->owner->forceFill(['deactivated_at' => now()])->save();

    expect(fn () => $this->seed(SampleDataSeeder::class))->toThrow(RuntimeException::class);

    expect(Subscription::count())->toBe(0);
});

test('a sample card is added only when there is no active card', function () {
    $this->card->forceFill(['deactivated_at' => now()])->save();

    $this->seed(SampleDataSeeder::class);

    $card = CreditCard::query()->whereNull('deactivated_at')->sole();
    expect($card->notes)->toBe(SampleDataSeeder::MARKER)
        ->and($card->last_four)->toHaveLength(4)
        ->and(Subscription::pluck('credit_card_id')->unique()->all())->toBe([$card->id]);
});

test('if anything fails, nothing at all is added and no file is left behind', function () {
    $attached = 0;
    ChargeReceipt::created(function () use (&$attached) {
        if (++$attached === 5) {
            throw new RuntimeException('simulated failure');
        }
    });

    expect(fn () => $this->seed(SampleDataSeeder::class))->toThrow(RuntimeException::class);

    expect(Subscription::count())->toBe(0)
        ->and(SubscriptionCharge::count())->toBe(0)
        ->and(ChargeReceipt::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('receipts'))->toBe([]);
});

test('the clock is put back after seeding', function () {
    $this->seed(SampleDataSeeder::class);

    expect(now()->format('Y-m-d H:i'))->toBe('2026-11-15 10:00');
});
