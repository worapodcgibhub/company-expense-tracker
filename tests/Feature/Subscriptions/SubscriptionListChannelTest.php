<?php

use App\Enums\PaymentMethodType;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Phase 3.1e-2: the subscription list shows the real payment channel and
 * Auto / Manual on every row, and speaks of "due dates" instead of card dates.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
});

function listPage(?User $as = null)
{
    return Livewire::actingAs($as ?? test()->owner)->test('pages::subscriptions.index');
}

function channelSubscription(string $name, PaymentMethod $method, array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'name' => $name,
        'credit_card_id' => $method->credit_card_id,
        'payment_method_id' => $method->id,
        ...$attributes,
    ]);
}

function bankMethod(string $name, PaymentMethodType $type, string $lastFour): PaymentMethod
{
    return PaymentMethod::factory()->forBankAccount($type)->create([
        'name' => $name,
        'bank_account_id' => BankAccount::factory()->create(['last_four' => $lastFour])->id,
    ]);
}

test('each row shows its real channel, masked number and Auto or Manual', function () {
    $card = CreditCard::factory()->create(['last_four' => '4242']);
    channelSubscription('ค่า AI ตัวอย่าง', $card->paymentMethod);
    channelSubscription('ค่าเช่า Colocation', bankMethod('โอนเงินผ่านธนาคาร KBank', PaymentMethodType::BankTransfer, '5678'));
    channelSubscription('ค่า Internet', bankMethod('หักบัญชีอัตโนมัติ KBank', PaymentMethodType::DirectDebit, '5678'));

    $html = listPage()->html();

    expect($html)->toContain('โอนเงินผ่านธนาคาร KBank')
        ->and($html)->toContain('หักบัญชีอัตโนมัติ KBank')
        ->and($html)->toContain('••••5678')
        ->and($html)->toContain('4242')
        ->and(substr_count($html, 'data-test="settlement-badge"'))->toBe(3)
        ->and(substr_count($html, '>Manual<'))->toBe(1)
        ->and(substr_count($html, '>Auto<'))->toBe(2);
});

test('the list speaks of due dates for every channel', function () {
    channelSubscription('จ่ายเอง', bankMethod('โอน SCB', PaymentMethodType::BankTransfer, '9012'), ['next_charge_date' => '2026-11-25']);
    channelSubscription('ตัดเอง', CreditCard::factory()->create()->paymentMethod, ['next_charge_date' => '2026-11-26']);

    listPage()
        ->assertSee('ใกล้ถึงกำหนดชำระ (7 วัน)')
        ->assertSee('เลยกำหนด ยังไม่ยืนยันยอด')
        ->assertSee('กำหนดชำระถัดไป')
        ->assertSee('ตัดเงินถัดไป')
        ->assertDontSee('จะตัดบัตรใน 7 วัน')
        ->assertDontSee('ตัดบัตรถัดไป');
});

test('the 7-day summary counts every channel, not just cards', function () {
    channelSubscription('โอนใกล้ถึง', bankMethod('โอน', PaymentMethodType::BankTransfer, '1111'), ['next_charge_date' => '2026-11-18']);
    channelSubscription('หักบัญชีใกล้ถึง', bankMethod('หักบัญชี', PaymentMethodType::DirectDebit, '2222'), ['next_charge_date' => '2026-11-19']);
    channelSubscription('บัตรใกล้ถึง', CreditCard::factory()->create()->paymentMethod, ['next_charge_date' => '2026-11-20']);
    channelSubscription('ไกลออกไป', CreditCard::factory()->create()->paymentMethod, ['next_charge_date' => '2026-12-20']);

    expect(listPage()->instance()->overview()->upcoming(7)->pluck('name')->sort()->values()->all())
        ->toBe(['บัตรใกล้ถึง', 'หักบัญชีใกล้ถึง', 'โอนใกล้ถึง']);
});

test('a row that is only due soon is not red, an overdue one is', function () {
    $transfer = bankMethod('โอน', PaymentMethodType::BankTransfer, '1111');
    $soon = channelSubscription('ใกล้ถึง', $transfer, ['next_charge_date' => '2026-11-16']);

    expect(listPage()->html())->not->toContain('text-red-600');

    $soon->forceFill(['next_charge_date' => '2026-11-10'])->save();

    expect(listPage()->html())->toContain('text-red-600')->toContain('เลยกำหนด');
});

test('the list can be filtered by payment method and by Auto or Manual', function () {
    $transfer = bankMethod('โอน KBank', PaymentMethodType::BankTransfer, '5678');
    $debit = bankMethod('หักบัญชี KBank', PaymentMethodType::DirectDebit, '5678');
    $card = CreditCard::factory()->create()->paymentMethod;
    channelSubscription('A โอน', $transfer);
    channelSubscription('B หักบัญชี', $debit);
    channelSubscription('C บัตร', $card);

    $names = fn ($component) => $component->instance()->subscriptions()->pluck('name')->sort()->values()->all();

    expect($names(listPage()->set('method', (string) $transfer->id)))->toBe(['A โอน'])
        ->and($names(listPage()->set('method', (string) $card->id)))->toBe(['C บัตร'])
        ->and($names(listPage()->set('settlement', 'manual')))->toBe(['A โอน'])
        ->and($names(listPage()->set('settlement', 'auto')))->toBe(['B หักบัญชี', 'C บัตร'])
        ->and($names(listPage()->set('settlement', 'manual')->set('method', (string) $card->id)))->toBe([])
        ->and($names(listPage()->set('settlement', 'bogus')))->toBe(['A โอน', 'B หักบัญชี', 'C บัตร']);
});

test('filters can be cleared', function () {
    $transfer = bankMethod('โอน', PaymentMethodType::BankTransfer, '5678');
    channelSubscription('A โอน', $transfer);
    channelSubscription('B บัตร', CreditCard::factory()->create()->paymentMethod);

    $component = listPage()->set('settlement', 'manual')->set('method', (string) $transfer->id)->call('resetFilters');

    expect($component->get('settlement'))->toBe('')
        ->and($component->get('method'))->toBe('')
        ->and($component->instance()->subscriptions())->toHaveCount(2);
});

test('the channel filters list payment methods for the owner and accountant only', function () {
    bankMethod('โอนเงิน KBank ลับ', PaymentMethodType::BankTransfer, '5678');
    channelSubscription('ของพนักงาน', CreditCard::factory()->create()->paymentMethod, ['responsible_user_id' => ($staff = User::factory()->create())->id]);

    foreach ([$this->owner, User::factory()->accountant()->create()] as $user) {
        listPage($user)->assertSee('ทุกช่องทางชำระเงิน')->assertSee('โอนเงิน KBank ลับ');
    }

    listPage($staff)->assertSee('ของพนักงาน')->assertDontSee('ทุกช่องทางชำระเงิน')->assertDontSee('โอนเงิน KBank ลับ');
});

test('the channel does not change estimates or recorded totals', function () {
    $subscription = channelSubscription('ยอดเท่าเดิม', CreditCard::factory()->create()->paymentMethod, ['estimated_amount' => 150_000]);
    SubscriptionCharge::factory()->for($subscription)->create(['amount' => 150_000]);
    $before = [$subscription->monthlyEstimate(), SubscriptionCharge::sum('amount'), SubscriptionCharge::count()];

    listPage()->assertSee('ยอดเท่าเดิม');

    expect([$subscription->fresh()->monthlyEstimate(), SubscriptionCharge::sum('amount'), SubscriptionCharge::count()])->toBe($before);
});

test('a guest cannot see the list', function () {
    $this->get(route('subscriptions.index'))->assertRedirect(route('login'));
});
