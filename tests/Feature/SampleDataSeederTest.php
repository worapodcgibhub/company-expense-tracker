<?php

use App\Enums\BillingCycle;
use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Enums\DataSet;
use App\Enums\PaymentMethodType;
use App\Enums\ReceiptStatus;
use App\Enums\SettlementMode;
use App\Enums\SubscriptionStatus;
use App\Models\BankAccount;
use App\Models\ChargeReceipt;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
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
        'accounts' => BankAccount::count(),
        'methods' => PaymentMethod::count(),
        'subscriptions' => Subscription::count(),
        'charges' => SubscriptionCharge::count(),
        'revisions' => SubscriptionChargeRevision::count(),
        'receipts' => ChargeReceipt::count(),
        'files' => count(Storage::disk('local')->allFiles('receipts')),
    ];
}

test('it adds the sample subscriptions with about six months of history, using the existing owner and card', function () {
    $this->seed(SampleDataSeeder::class);

    $catalog = array_merge(SampleDataSeeder::catalog(), SampleDataSeeder::channelCatalog());
    $subscriptions = Subscription::all();

    expect($subscriptions)->toHaveCount(count($catalog))
        ->and(count(SampleDataSeeder::catalog()))->toBe(8)
        ->and($subscriptions->every(fn ($s) => $s->notes === SampleDataSeeder::MARKER))->toBeTrue()
        ->and($subscriptions->pluck('responsible_user_id')->unique()->all())->toBe([$this->owner->id])
        ->and($subscriptions->whereNotNull('credit_card_id')->pluck('credit_card_id')->unique()->all())->toBe([$this->card->id])
        // No new users or cards.
        ->and(User::count())->toBe(1)
        ->and(CreditCard::count())->toBe(1);

    $charges = SubscriptionCharge::query()->charged()->get();
    $months = $charges->map(fn ($c) => $c->charged_on->format('Y-m'))->unique();

    expect($charges->count())->toBeGreaterThan(30)
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
        ->and(Subscription::where('notes', SampleDataSeeder::MARKER)->count())->toBe(count(SampleDataSeeder::catalog()) + count(SampleDataSeeder::channelCatalog()) - 2)
        ->and($existing->charges()->count())->toBe(1);
});

test('the sample covers the statuses needed for a demo', function () {
    $this->seed(SampleDataSeeder::class);

    $overview = new SubscriptionOverview($this->owner, null, DataSet::Sample);
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
    $overview = new ChargeOverview($this->owner, null, DataSet::Sample);

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

    // The dashboard shows real data by default; sample data is chosen with ?data=sample.
    $this->get(route('dashboard', ['data' => 'sample']))->assertOk()
        ->assertSee('data-test="upcoming-charges"', false)
        ->assertSee('data-test="recent-expenses"', false)
        ->assertSee('data-test="missing-thb"', false)
        ->assertSee('ข้อมูลตัวอย่าง')
        ->assertDontSee('กราฟจะแสดงเมื่อมีการบันทึกค่าใช้จ่ายจริง');

    $this->get(route('subscriptions.index', ['status' => 'all']))->assertOk()->assertSee('ChatGPT Plus')->assertSee('ค่า AI สำหรับคอนเทนต์');
    $this->get(route('charges.index', ['month' => 'all']))->assertOk()->assertSee('data-test="charge-list"', false);
    $this->get(route('charges.index', ['receipt' => 'waiting', 'month' => 'all']))->assertOk()->assertSee('data-test="receipt-waiting-badge"', false);
    // Closing and its receipt counts use real data only: sample data never reaches the books.
    $this->get(route('accounting-periods.index'))->assertOk()->assertDontSee('ChatGPT Plus');

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
        ->and(Subscription::whereNotNull('credit_card_id')->pluck('credit_card_id')->unique()->all())->toBe([$card->id]);
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

/*
|--------------------------------------------------------------------------
| Phase 3.1: sample bank accounts and payment methods
|--------------------------------------------------------------------------
*/

test('it adds two sample bank accounts and four payment methods with last four digits only', function () {
    $this->seed(SampleDataSeeder::class);

    $accounts = BankAccount::query()->orderBy('id')->get();
    $methods = PaymentMethod::query()->whereNotNull('name')->where('name', 'like', '%(ตัวอย่าง)')->get()->keyBy('name');

    expect($accounts->pluck('last_four')->all())->toBe(['5678', '9012'])
        ->and($accounts->every(fn ($a) => $a->notes === SampleDataSeeder::MARKER && strlen($a->last_four) === 4))->toBeTrue()
        ->and($methods)->toHaveCount(4)
        ->and($methods['โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)']->bank_account_id)->toBe($accounts[0]->id)
        ->and($methods['โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)']->type)->toBe(PaymentMethodType::BankTransfer)
        ->and($methods['PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)']->bank_account_id)->toBe($accounts[1]->id)
        ->and($methods['หักบัญชีอัตโนมัติ KBank (ตัวอย่าง)']->type)->toBe(PaymentMethodType::DirectDebit)
        ->and($methods['หักบัญชีอัตโนมัติ KBank (ตัวอย่าง)']->settlement)->toBe(SettlementMode::Auto)
        ->and($methods['เงินสดย่อยสำนักงาน (ตัวอย่าง)']->bank_account_id)->toBeNull()
        ->and($methods->every(fn ($m) => $m->isActive() && $m->hasValidTarget()))->toBeTrue()
        // The existing card keeps its own method; none is added for it.
        ->and(PaymentMethod::where('credit_card_id', $this->card->id)->count())->toBe(1)
        ->and(CreditCard::count())->toBe(1);
});

test('it adds the VPS and AI subscriptions on the card as monthly baht costs', function () {
    $this->seed(SampleDataSeeder::class);

    $vps = Subscription::where('name', 'ค่า VPS')->sole();
    $ai = Subscription::where('name', 'ค่า AI สำหรับคอนเทนต์')->sole();

    expect($vps->estimated_amount)->toBe(180_000)
        ->and($ai->estimated_amount)->toBe(75_000)
        ->and($vps->currency)->toBe(Currency::THB)
        ->and($ai->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($vps->credit_card_id)->toBe($this->card->id)
        ->and($vps->payment_method_id)->toBe($this->card->paymentMethod->id)
        ->and($vps->notes)->toBe(SampleDataSeeder::MARKER)
        ->and($vps->charges()->count())->toBe(6);
});

test('running again adds no accounts, methods or subscriptions, and a missing one is added alone', function () {
    $this->seed(SampleDataSeeder::class);
    $first = sampleCounts();

    $this->seed(SampleDataSeeder::class);
    expect(sampleCounts())->toBe($first);

    // Someone removed one method by hand: only that one comes back.
    $kept = PaymentMethod::where('name', 'like', 'PromptPay%')->firstOrFail();
    $accountsBefore = BankAccount::all()->map->getAttributes()->all();
    PaymentMethod::where('name', 'like', 'เงินสดย่อย%')->delete();

    $this->seed(SampleDataSeeder::class);

    expect(PaymentMethod::count())->toBe($first['methods'])
        ->and($kept->fresh()->getAttributes())->toBe($kept->getAttributes())
        ->and(BankAccount::all()->map->getAttributes()->all())->toBe($accountsBefore)
        ->and(Subscription::count())->toBe($first['subscriptions']);
});

test('existing accounts and methods are never changed, even when edited or switched off', function () {
    $account = BankAccount::factory()->create(['name' => 'KBank บัญชีบริษัท', 'last_four' => '1111', 'deactivated_at' => now()]);
    $method = PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อยสำนักงาน (ตัวอย่าง)', 'deactivated_at' => now()]);
    $before = [$account->fresh()->getAttributes(), $method->fresh()->getAttributes(), $this->card->fresh()->getAttributes(), $this->card->paymentMethod->fresh()->getAttributes()];

    $this->seed(SampleDataSeeder::class);

    expect([$account->fresh()->getAttributes(), $method->fresh()->getAttributes(), $this->card->fresh()->getAttributes(), $this->card->paymentMethod->fresh()->getAttributes()])->toBe($before)
        ->and(BankAccount::where('name', 'KBank บัญชีบริษัท')->count())->toBe(1)
        ->and(PaymentMethod::where('name', 'เงินสดย่อยสำนักงาน (ตัวอย่าง)')->count())->toBe(1)
        ->and(BankAccount::count())->toBe(2);
});

test('the channels add no amounts: charges exist only for the sample subscriptions, real rows are untouched', function () {
    $real = Subscription::factory()->create(['name' => 'ของจริง', 'vendor' => 'ผู้ขายจริง']);
    $charge = SubscriptionCharge::factory()->for($real)->create(['amount' => 99_900]);
    $rows = $charge->fresh()->getAttributes();
    $realBefore = SubscriptionCharge::where('subscription_id', $real->id)->sum('amount');

    $this->seed(SampleDataSeeder::class);

    $sampleIds = Subscription::where('notes', SampleDataSeeder::MARKER)->pluck('id');

    expect($charge->fresh()->getAttributes())->toBe($rows)
        ->and(SubscriptionCharge::where('subscription_id', $real->id)->sum('amount'))->toEqual($realBefore)
        ->and(SubscriptionCharge::whereNotIn('subscription_id', $sampleIds->push($real->id))->count())->toBe(0);
});

test('if the seeder fails, no bank account or method is left behind', function () {
    $attached = 0;
    ChargeReceipt::created(function () use (&$attached) {
        if (++$attached === 3) {
            throw new RuntimeException('simulated failure');
        }
    });

    expect(fn () => $this->seed(SampleDataSeeder::class))->toThrow(RuntimeException::class);

    expect(BankAccount::count())->toBe(0)
        ->and(PaymentMethod::count())->toBe(1);   // only the card's own method from beforeEach
});

test('the new pages show the sample channels', function () {
    $this->seed(SampleDataSeeder::class);
    $this->actingAs($this->owner);

    $this->get(route('bank-accounts.index'))->assertOk()->assertSee('KBank บัญชีบริษัท')->assertSee('SCB บัญชีบริษัท');
    $this->get(route('payment-methods.index'))->assertOk()->assertSee('PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)')->assertSee('เงินสดย่อยสำนักงาน (ตัวอย่าง)');
});

test('the card sample set is exactly eight automatic card subscriptions', function () {
    $this->seed(SampleDataSeeder::class);

    $subscriptions = Subscription::with('paymentMethod')->get()->filter(fn ($s) => $s->paymentMethod->type === PaymentMethodType::Card);

    expect($subscriptions)->toHaveCount(8)
        ->and($subscriptions->every(fn ($s) => $s->paymentMethod->settlement === SettlementMode::Auto))->toBeTrue()
        ->and($subscriptions->pluck('name')->all())->toContain('ChatGPT Plus', 'Claude Pro', 'Cloud Server (Droplet)', 'Cloudflare Pro', 'Web Hosting (Business)', 'Canva Pro', 'ค่า VPS', 'ค่า AI สำหรับคอนเทนต์');
});

/*
|--------------------------------------------------------------------------
| Phase 3.1e-5: samples paid through the bank / PromptPay
|--------------------------------------------------------------------------
*/

test('it adds four bank samples on their own methods, separate from the eight card samples', function () {
    $this->seed(SampleDataSeeder::class);

    $bank = Subscription::with('paymentMethod.bankAccount')->whereNull('credit_card_id')->get()->keyBy('name');

    expect($bank)->toHaveCount(4)
        ->and(count(SampleDataSeeder::channelCatalog()))->toBe(4)
        ->and($bank['ค่าเช่า Colocation']->estimated_amount)->toBe(850_000)
        ->and($bank['ค่าเช่า Colocation']->paymentMethod->name)->toBe('โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)')
        ->and($bank['ค่าเช่า Colocation']->paymentMethod->settlement)->toBe(SettlementMode::Manual)
        ->and($bank['ค่าเช่า Colocation']->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($bank['ค่า Internet สำนักงาน']->estimated_amount)->toBe(129_000)
        ->and($bank['ค่า Internet สำนักงาน']->paymentMethod->type)->toBe(PaymentMethodType::DirectDebit)
        ->and($bank['ค่า Internet สำนักงาน']->paymentMethod->settlement)->toBe(SettlementMode::Auto)
        ->and($bank['ค่าโดเมนเว็บไซต์']->estimated_amount)->toBe(45_000)
        ->and($bank['ค่าโดเมนเว็บไซต์']->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and($bank['ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก']->estimated_amount)->toBe(350_000)
        ->and($bank['ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก']->paymentMethod->name)->toBe('PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)')
        ->and($bank['ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก']->paymentMethod->bankAccount->last_four)->toBe('9012')
        ->and($bank->every(fn ($s) => $s->notes === SampleDataSeeder::MARKER && $s->status === SubscriptionStatus::Active && $s->isActive()))->toBeTrue()
        ->and($bank->every(fn ($s) => $s->paymentMethod->isUsable()))->toBeTrue();
});

test('bank samples have history recorded on their own method, with no card and a recorder', function () {
    $this->seed(SampleDataSeeder::class);

    foreach (Subscription::whereNull('credit_card_id')->get() as $subscription) {
        $charges = $subscription->charges;

        expect($charges)->not->toBeEmpty()
            ->and($charges->every(fn ($c) => $c->payment_method_id === $subscription->payment_method_id && $c->credit_card_id === null))->toBeTrue()
            ->and($charges->every(fn ($c) => $c->recorded_by === $this->owner->id))->toBeTrue()
            ->and($subscription->next_charge_date->greaterThan(now()))->toBeTrue();
    }

    expect(Subscription::where('name', 'ค่าเช่า Colocation')->sole()->charges)->toHaveCount(6)
        ->and(Subscription::where('name', 'ค่าโดเมนเว็บไซต์')->sole()->charges)->toHaveCount(1)
        ->and(Subscription::where('name', 'ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก')->sole()->charges->pluck('amount')->contains(400_000))->toBeTrue();
});

test('bank samples are not overdue and not paid early: they show as upcoming, Manual or Auto', function () {
    $this->seed(SampleDataSeeder::class);
    $this->actingAs($this->owner);

    $html = $this->get(route('dashboard', ['data' => 'sample']))->assertOk()->getContent();

    expect($html)->toContain('ค่าเช่า Colocation')
        ->and($html)->toContain('data-test="action-item-manual-due"')
        ->and($html)->toContain('ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก');

    $this->get(route('subscriptions.index', ['status' => 'all']))->assertOk()
        ->assertSee('ค่า Internet สำนักงาน')->assertSee('โอนเงินผ่านธนาคาร KBank (ตัวอย่าง)')->assertSee('หักบัญชีอัตโนมัติ KBank (ตัวอย่าง)');
    $this->get(route('subscriptions.show', Subscription::where('name', 'ค่าเช่า Colocation')->sole()))->assertOk()->assertSee('กำหนดชำระครั้งถัดไป');
});

test('running again adds no bank samples, and one that exists is skipped without a change', function () {
    $this->seed(SampleDataSeeder::class);
    $first = sampleCounts();

    $this->seed(SampleDataSeeder::class);

    expect(sampleCounts())->toBe($first);
});

test('a bank sample that already exists as a real subscription is skipped without a change', function () {
    $real = Subscription::factory()->create(['name' => 'ค่าโดเมนเว็บไซต์', 'vendor' => 'ผู้ให้บริการโดเมน (ตัวอย่าง)', 'notes' => 'ของจริง', 'estimated_amount' => 77_700]);
    $snapshot = $real->fresh()->getAttributes();

    $this->seed(SampleDataSeeder::class);

    expect($real->fresh()->getAttributes())->toBe($snapshot)
        ->and(Subscription::where('name', 'ค่าโดเมนเว็บไซต์')->count())->toBe(1)
        ->and(Subscription::whereNull('credit_card_id')->count())->toBe(3);   // the other three bank samples
});

test('a bank sample whose method was switched off is skipped, and nothing is reactivated or changed', function () {
    $this->seed(SampleDataSeeder::class);
    Subscription::whereNull('credit_card_id')->get()->each(fn ($s) => $s->forceFill(['notes' => 'ของจริง'])->save());
    $off = PaymentMethod::where('name', 'PromptPay / QR ผ่านบัญชี SCB (ตัวอย่าง)')->firstOrFail();
    $off->forceFill(['deactivated_at' => now()])->save();
    Subscription::where('name', 'ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก')->firstOrFail()->forceFill(['status' => SubscriptionStatus::Cancelled, 'name' => 'อื่น'])->save();
    $before = sampleCounts();
    $offBefore = $off->fresh()->getAttributes();

    $this->seed(SampleDataSeeder::class);

    // The freelancer sample is gone from the list only because it was renamed: it is skipped, not re-added with a dead method.
    expect(Subscription::where('name', 'ค่าจ้างฟรีแลนซ์ออกแบบกราฟิก')->count())->toBe(0)
        ->and(sampleCounts())->toBe($before)
        ->and($off->fresh()->getAttributes())->toBe($offBefore)
        ->and($off->fresh()->isActive())->toBeFalse();
});

test('the bank samples add no amount to anything but their own subscriptions', function () {
    $real = Subscription::factory()->create(['name' => 'ของจริง', 'vendor' => 'ผู้ขายจริง']);
    $charge = SubscriptionCharge::factory()->for($real)->create(['amount' => 99_900]);
    $rows = $charge->fresh()->getAttributes();

    $this->seed(SampleDataSeeder::class);

    $sampleIds = Subscription::where('notes', SampleDataSeeder::MARKER)->pluck('id');

    expect($charge->fresh()->getAttributes())->toBe($rows)
        ->and(SubscriptionCharge::whereNotIn('subscription_id', $sampleIds->push($real->id))->count())->toBe(0)
        ->and(SubscriptionCharge::whereIn('subscription_id', Subscription::whereNull('credit_card_id')->pluck('id'))->charged()->count())->toBeGreaterThan(10);
});

test('a database that already has the older samples keeps them and gets only the missing ones', function () {
    // What the real database looks like today: older samples (some no longer in the catalog) plus a real one.
    $older = collect([
        ['ChatGPT Plus', 'OpenAI'], ['Claude Pro', 'Anthropic'], ['Canva Pro', 'Canva'], ['Cloud Server (Droplet)', 'DigitalOcean'],
        ['Cloudflare Pro', 'Cloudflare'], ['Web Hosting (Business)', 'Hostinger'],
        ['Zoom Pro', 'Zoom'], ['Google Workspace Business Standard', 'Google'], ['LINE Official Account', 'LINE'],
        ['Adobe Creative Cloud', 'Adobe'],
    ])->map(fn ($pair) => Subscription::factory()->create(['name' => $pair[0], 'vendor' => $pair[1], 'notes' => $pair[0] === 'Adobe Creative Cloud' ? 'ของจริง' : SampleDataSeeder::MARKER]));
    $charges = $older->map(fn ($s) => SubscriptionCharge::factory()->for($s)->create());
    $before = $older->map(fn ($s) => $s->fresh()->getAttributes())->all();
    $chargesBefore = $charges->map(fn ($c) => $c->fresh()->getAttributes())->all();

    $this->seed(SampleDataSeeder::class);

    expect(Subscription::count())->toBe(16)                                   // 10 existing + VPS + AI + 4 bank samples
        ->and($older->map(fn ($s) => $s->fresh()->getAttributes())->all())->toBe($before)
        ->and($charges->map(fn ($c) => $c->fresh()->getAttributes())->all())->toBe($chargesBefore)
        ->and(Subscription::whereIn('name', ['ค่า VPS', 'ค่า AI สำหรับคอนเทนต์'])->count())->toBe(2)
        ->and(Subscription::where('name', 'Zoom Pro')->count())->toBe(1)
        ->and(Subscription::where('status', SubscriptionStatus::Cancelled->value)->count())->toBe(0);

    $this->seed(SampleDataSeeder::class);

    expect(Subscription::count())->toBe(16);
});
