<?php

use App\Enums\ChargeOutcome;
use App\Enums\PaymentMethodType;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;

/*
 * Phase 3.1e-4: the dashboard speaks of due dates for every channel, shows
 * the real channel with Auto / Manual, and lists what has to be paid or
 * confirmed. Nothing is counted as paid until it is recorded / confirmed.
 */

beforeEach(function () {
    // Sunday 15 Nov 2026, Bangkok time.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function dashMethod(PaymentMethodType $type, string $name, string $lastFour = '5678'): PaymentMethod
{
    if ($type === PaymentMethodType::Card) {
        $method = CreditCard::factory()->create(['last_four' => $lastFour])->paymentMethod;
        $method->forceFill(['name' => $name])->save();

        return $method;
    }

    return PaymentMethod::factory()->forBankAccount($type)->create([
        'name' => $name,
        'bank_account_id' => BankAccount::factory()->create(['last_four' => $lastFour])->id,
    ]);
}

function dashSubscription(string $name, PaymentMethod $method, int $inDays, array $attributes = []): Subscription
{
    return Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-15')->addDays($inDays))->create([
        'name' => $name,
        'credit_card_id' => $method->credit_card_id,
        'payment_method_id' => $method->id,
        'estimated_amount' => 100_000,
        ...$attributes,
    ]);
}

function dashboardHtml(?User $as = null): string
{
    return test()->actingAs($as ?? test()->owner)->get(route('dashboard'))->assertOk()->getContent();
}

/**
 * The HTML of one action item (<li data-test="action-item-…">…</li>) that
 * mentions $name, or '' when there is none.
 */
function actionItemFor(string $html, string $name): string
{
    preg_match_all('/<li data-test="action-item-[a-z-]+">.*?<\/li>/s', $html, $matches);

    foreach ($matches[0] as $item) {
        if (str_contains($item, $name)) {
            return $item;
        }
    }

    return '';
}

test('the dashboard works with card, bank-transfer and direct-debit subscriptions and charges', function () {
    $transfer = dashMethod(PaymentMethodType::BankTransfer, 'โอนเงินผ่านธนาคาร KBank');
    $debit = dashMethod(PaymentMethodType::DirectDebit, 'หักบัญชีอัตโนมัติ KBank');
    $card = dashMethod(PaymentMethodType::Card, 'บัตรบริษัท', '4242');
    $colocation = dashSubscription('Colocation', $transfer, -1);
    dashSubscription('Internet', $debit, 2);
    dashSubscription('VPS', $card, 4);
    $colocation->recordCharge($colocation->next_charge_date, [
        'outcome' => ChargeOutcome::Charged, 'charged_on' => now(), 'amount' => 850_000, 'amount_thb' => null,
        'payment_method_id' => $transfer->id, 'notes' => null,
    ], $this->owner);

    $html = dashboardHtml();

    expect($html)->toContain('กำหนดชำระที่กำลังจะถึง')
        ->and($html)->toContain('ใกล้ถึงกำหนดชำระ')
        ->and($html)->toContain('บันทึกจ่ายจริงแล้ว')
        ->and($html)->not->toContain('รอบตัดบัตร')
        ->and($html)->toContain('โอนเงินผ่านธนาคาร KBank');
});

test('each upcoming item shows its real channel, masked number and Auto or Manual', function () {
    dashSubscription('ค่าเช่า Colocation', dashMethod(PaymentMethodType::BankTransfer, 'โอนเงินผ่านธนาคาร KBank', '5678'), 3);
    dashSubscription('ค่า Freelancer', dashMethod(PaymentMethodType::BankTransfer, 'PromptPay / QR ผ่านบัญชี SCB', '9012'), 5);
    dashSubscription('ค่า Internet', dashMethod(PaymentMethodType::DirectDebit, 'หักบัญชีอัตโนมัติ KBank', '5678'), 6);
    dashSubscription('ค่า VPS', dashMethod(PaymentMethodType::Card, 'บัตรบริษัท', '4242'), 8);

    $html = dashboardHtml();
    preg_match('/<ul class="divide-y divide-slate-100" data-test="upcoming-charges">.*?<\/ul>/s', $html, $list);

    expect($list[0])->toContain('PromptPay / QR ผ่านบัญชี SCB')
        ->toContain('••••9012')
        ->toContain('หักบัญชีอัตโนมัติ KBank')
        ->toContain('บัตรบริษัท')
        ->toContain('4242')
        ->and(substr_count($list[0], 'data-test="upcoming-settlement"'))->toBe(4)
        ->and(substr_count($list[0], '>Manual</span>'))->toBe(2)
        ->and(substr_count($list[0], '>Auto</span>'))->toBe(2);
});

test('the 7-day card counts every channel and splits Manual from Auto', function () {
    dashSubscription('โอน', dashMethod(PaymentMethodType::BankTransfer, 'โอน'), 2);
    dashSubscription('หักบัญชี', dashMethod(PaymentMethodType::DirectDebit, 'หักบัญชี'), 3);
    dashSubscription('บัตร', dashMethod(PaymentMethodType::Card, 'บัตร'), 4);
    dashSubscription('ไกล', dashMethod(PaymentMethodType::Card, 'บัตร 2'), 20);

    expect(dashboardHtml())->toContain('ภายใน 7 วัน · ต้องชำระเอง 1 · ตัดอัตโนมัติ 2');
});

/*
|--------------------------------------------------------------------------
| Action needed
|--------------------------------------------------------------------------
*/

test('a Manual payment due soon is listed to pay, in amber not red', function () {
    $s = dashSubscription('ค่าเช่า Colocation', dashMethod(PaymentMethodType::BankTransfer, 'โอนเงินผ่านธนาคาร KBank'), 3);
    dashSubscription('จ่ายเองอีกนาน', dashMethod(PaymentMethodType::BankTransfer, 'โอน 2'), 10);

    $html = dashboardHtml();
    $item = actionItemFor($html, 'ค่าเช่า Colocation');

    expect($item)->toContain('data-test="action-item-manual-due"')
        ->toContain('ต้องชำระเอง (Manual)')
        ->toContain('อีก 3 วัน')
        ->toContain('โอนเงินผ่านธนาคาร KBank')
        ->toContain('bg-amber-50/60')
        ->toContain(route('charges.create', $s))
        ->not->toContain('red')
        ->and(actionItemFor($html, 'จ่ายเองอีกนาน'))->toBe('');
});

test('a Manual payment past due and not confirmed is red', function () {
    dashSubscription('ค่า Domain', dashMethod(PaymentMethodType::BankTransfer, 'โอน KBank'), -2);

    $item = actionItemFor(dashboardHtml(), 'ค่า Domain');

    expect($item)->toContain('data-test="action-item-manual-overdue"')
        ->toContain('เลยกำหนด 2 วัน')
        ->toContain('ยังไม่ได้ยืนยันการชำระ')
        ->toContain('bg-red-50/60');
});

test('an Auto charge due today waits for confirmation in amber, and turns red once past due', function (PaymentMethodType $type, string $name) {
    $s = dashSubscription('รายการ Auto', dashMethod($type, $name), 0);

    $today = actionItemFor(dashboardHtml(), 'รายการ Auto');

    expect($today)->toContain('data-test="action-item-auto-due"')
        ->toContain('รอยืนยันยอดตัดจริง (Auto)')
        ->toContain('bg-amber-50/60')
        ->not->toContain('ชำระแล้ว');

    $s->forceFill(['next_charge_date' => '2026-11-12'])->save();

    $late = actionItemFor(dashboardHtml(), 'รายการ Auto');

    expect($late)->toContain('data-test="action-item-auto-overdue"')
        ->toContain('เลยกำหนด 3 วัน')
        ->toContain('bg-red-50/60');
})->with([
    'credit card' => [PaymentMethodType::Card, 'บัตรบริษัท'],
    'bank direct debit' => [PaymentMethodType::DirectDebit, 'หักบัญชีอัตโนมัติ KBank'],
]);

test('an item that is only due soon is never shown in red', function () {
    dashSubscription('Auto ใกล้ถึง', dashMethod(PaymentMethodType::Card, 'บัตร'), 2);
    dashSubscription('Manual ใกล้ถึง', dashMethod(PaymentMethodType::BankTransfer, 'โอน'), 1);

    $html = dashboardHtml();

    expect($html)->not->toContain('bg-red-50/60')
        ->and(actionItemFor($html, 'Auto ใกล้ถึง'))->toBe('')
        ->and(actionItemFor($html, 'Manual ใกล้ถึง'))->toContain('พรุ่งนี้');
});

/*
|--------------------------------------------------------------------------
| Nothing counted as paid until confirmed, nothing counted twice
|--------------------------------------------------------------------------
*/

test('due or overdue items are not counted as paid; once confirmed they count exactly once', function () {
    $transfer = dashMethod(PaymentMethodType::BankTransfer, 'โอน KBank');
    $debit = dashMethod(PaymentMethodType::DirectDebit, 'หักบัญชี KBank');
    $manual = dashSubscription('Manual เลยกำหนด', $transfer, -1, ['estimated_amount' => 850_000]);
    $auto = dashSubscription('Auto ถึงกำหนด', $debit, 0, ['estimated_amount' => 129_000]);
    $summary = fn () => (new ChargeOverview($this->owner, now()))->monthSummary();

    $html = dashboardHtml();

    expect($summary())->toMatchArray(['charge_count' => 0, 'thb_total' => 0])
        ->and($html)->toContain('฿0.00')
        ->and(actionItemFor($html, 'Manual เลยกำหนด'))->not->toBe('')
        ->and(actionItemFor($html, 'Auto ถึงกำหนด'))->not->toBe('');

    foreach ([[$manual, $transfer, 850_000], [$auto, $debit, 129_000]] as [$s, $m, $amount]) {
        $s->recordCharge($s->next_charge_date, [
            'outcome' => ChargeOutcome::Charged, 'charged_on' => now(), 'amount' => $amount, 'amount_thb' => null,
            'payment_method_id' => $m->id, 'notes' => null,
        ], $this->owner);
    }

    $html = dashboardHtml();

    expect($summary())->toMatchArray(['charge_count' => 2, 'thb_total' => 979_000])
        ->and(SubscriptionCharge::count())->toBe(2)
        ->and($html)->toContain('฿9,790.00')
        ->and(actionItemFor($html, 'Manual เลยกำหนด'))->toBe('')
        ->and(actionItemFor($html, 'Auto ถึงกำหนด'))->toBe('');
});

test('viewing the dashboard changes no data', function () {
    $s = dashSubscription('VPS', dashMethod(PaymentMethodType::Card, 'บัตร'), -3);
    SubscriptionCharge::factory()->for($s)->create(['due_date' => '2026-10-12', 'charged_on' => '2026-10-12', 'amount' => 180_000]);
    $before = [Subscription::query()->get()->toArray(), SubscriptionCharge::query()->get()->toArray()];

    dashboardHtml();
    dashboardHtml(User::factory()->accountant()->create());

    expect([Subscription::query()->get()->toArray(), SubscriptionCharge::query()->get()->toArray()])->toEqual($before);
});

/*
|--------------------------------------------------------------------------
| Who sees what
|--------------------------------------------------------------------------
*/

test('staff see only their own items, and links go to the detail page', function () {
    $staff = User::factory()->create();
    $mine = dashSubscription('ของฉัน', dashMethod(PaymentMethodType::BankTransfer, 'โอน'), 2, ['responsible_user_id' => $staff->id]);
    dashSubscription('ของคนอื่น', dashMethod(PaymentMethodType::BankTransfer, 'โอนอื่น'), 2);

    $html = dashboardHtml($staff);
    $item = actionItemFor($html, 'ของฉัน');

    expect($item)->toContain(route('subscriptions.show', $mine))
        ->not->toContain('/charges/create')
        ->and($html)->not->toContain('ของคนอื่น');
});
