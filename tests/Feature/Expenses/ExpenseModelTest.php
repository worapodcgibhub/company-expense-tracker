<?php

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethodType;
use App\Enums\ReceiptStatus;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Phase One-off 1: the expenses table and model. Separate from subscription
 * charges; no exchange rate is ever assumed.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
});

test('an expense keeps its details, category, currency and amount in minor units', function () {
    $owner = User::factory()->create();
    $expense = Expense::factory()->ownedBy($owner)->create([
        'title' => 'ซื้อโน้ตบุ๊ก',
        'category' => ExpenseCategory::Equipment,
        'paid_on' => '2026-11-10',
        'amount' => 3_250_000,
        'notes' => 'ผ่อนชำระครั้งเดียว',
    ])->fresh();

    expect($expense->title)->toBe('ซื้อโน้ตบุ๊ก')
        ->and($expense->category)->toBe(ExpenseCategory::Equipment)
        ->and($expense->paid_on->toDateString())->toBe('2026-11-10')
        ->and($expense->currency)->toBe(Currency::THB)
        ->and($expense->amount)->toBe(3_250_000)
        ->and($expense->notes)->toBe('ผ่อนชำระครั้งเดียว')
        ->and($expense->period())->toBe('2026-11');
});

test('relations point to the payment method, owner and recorder', function () {
    $staff = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $method = PaymentMethod::factory()->forBankAccount(PaymentMethodType::BankTransfer)->create();
    $expense = Expense::factory()->ownedBy($staff)->create([
        'payment_method_id' => $method->id,
        'recorded_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    expect($expense->paymentMethod->is($method))->toBeTrue()
        ->and($expense->paymentMethod->bankAccount)->not->toBeNull()
        ->and($expense->owner->is($staff))->toBeTrue()
        ->and($expense->recorder->is($owner))->toBeTrue()
        ->and($expense->updater->is($owner))->toBeTrue()
        ->and($expense->isOwnedBy($staff))->toBeTrue()
        ->and($expense->isOwnedBy($owner))->toBeFalse();
});

test('works with cash, bank transfer and card payment methods', function () {
    $cash = PaymentMethod::factory()->cash()->create();
    $transfer = PaymentMethod::factory()->forBankAccount()->create();
    $card = PaymentMethod::factory()->create();

    foreach ([$cash, $transfer, $card] as $method) {
        Expense::factory()->create(['payment_method_id' => $method->id]);
    }

    expect(Expense::query()->pluck('payment_method_id')->all())->toEqualCanonicalizing([$cash->id, $transfer->id, $card->id]);
});

test('baht is the amount itself, and a USD amount never becomes baht by guessing', function () {
    $thb = Expense::factory()->create(['amount' => 500_000]);
    $usdUnknown = Expense::factory()->usd(2_000)->create();
    $usdKnown = Expense::factory()->usd(2_000, 72_400)->create();

    expect($thb->thbAmount())->toBe(500_000)
        ->and($thb->needsThbAmount())->toBeFalse()
        ->and($usdUnknown->thbAmount())->toBeNull()
        ->and($usdUnknown->needsThbAmount())->toBeTrue()
        ->and($usdUnknown->amount_thb)->toBeNull()
        ->and($usdKnown->thbAmount())->toBe(72_400)
        ->and($usdKnown->needsThbAmount())->toBeFalse();
});

test('receipt status is waiting until the document is marked as received', function () {
    $waiting = Expense::factory()->create();
    $received = Expense::factory()->receiptReceived()->create();

    expect($waiting->receiptStatus())->toBe(ReceiptStatus::Waiting)
        ->and($received->receiptStatus())->toBe(ReceiptStatus::Attached);
});

test('visibleTo shows Owner and Accountant everything, Staff only their own', function () {
    $staff = User::factory()->create();
    $mine = Expense::factory()->ownedBy($staff)->create();
    $theirs = Expense::factory()->create();

    $ids = fn (User $user) => Expense::query()->visibleTo($user)->pluck('id')->all();

    expect($ids($staff))->toBe([$mine->id])
        ->and($ids(User::factory()->owner()->create()))->toEqualCanonicalizing([$mine->id, $theirs->id])
        ->and($ids(User::factory()->accountant()->create()))->toEqualCanonicalizing([$mine->id, $theirs->id]);
});

test('paidInMonth uses the whole calendar month', function () {
    $first = Expense::factory()->paidOn(CarbonImmutable::parse('2026-10-01'))->create();
    $last = Expense::factory()->paidOn(CarbonImmutable::parse('2026-10-31'))->create();
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-09-30'))->create();
    Expense::factory()->paidOn(CarbonImmutable::parse('2026-11-01'))->create();

    expect(Expense::query()->paidInMonth(CarbonImmutable::parse('2026-10-15'))->pluck('id')->all())
        ->toEqualCanonicalizing([$first->id, $last->id]);
});

test('adding expenses never touches subscription charges', function () {
    Expense::factory()->count(3)->create();

    expect(SubscriptionCharge::count())->toBe(0);
});
