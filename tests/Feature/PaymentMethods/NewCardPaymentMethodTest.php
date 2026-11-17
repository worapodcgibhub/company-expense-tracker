<?php

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Phase 3.1b: a NEW card gets its card payment method in the same database
// transaction. Existing cards and rows are not touched.

const NEW_CARD_PAGE = 'pages::credit-cards.index';

function newCardForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'บัตรฝ่ายขาย',
        'issuer' => 'ธนาคารกสิกรไทย',
        'network' => 'visa',
        'kind' => 'credit',
        'last_four' => '4242',
        'holder_name' => 'สมชาย ใจดี',
        'notes' => '',
    ], $overrides);
}

test('adding a card on the page also creates its card payment method', function () {
    $owner = User::factory()->owner()->create();

    $component = Livewire::actingAs($owner)->test(NEW_CARD_PAGE)->call('openCreate');
    foreach (newCardForm() as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('save')->assertHasNoErrors();

    $card = CreditCard::query()->where('name', 'บัตรฝ่ายขาย')->firstOrFail();
    $method = $card->paymentMethod;

    expect(PaymentMethod::count())->toBe(1)
        ->and($method)->not->toBeNull()
        ->and($method->type)->toBe(PaymentMethodType::Card)
        ->and($method->settlement)->toBe(SettlementMode::Auto)
        ->and($method->name)->toBe('บัตรฝ่ายขาย')
        ->and($method->credit_card_id)->toBe($card->id)
        ->and($method->bank_account_id)->toBeNull()
        ->and($method->isActive())->toBeTrue()
        ->and($method->created_by)->toBe($owner->id)
        ->and($method->hasValidTarget())->toBeTrue();
});

test('a subscription can be created right after adding a card on the page', function () {
    $owner = User::factory()->owner()->create();

    $component = Livewire::actingAs($owner)->test(NEW_CARD_PAGE)->call('openCreate');
    foreach (newCardForm(['last_four' => '9999']) as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('save')->assertHasNoErrors();

    $card = CreditCard::query()->where('last_four', '9999')->firstOrFail();
    $subscription = new Subscription;
    $subscription->forceFill(Subscription::factory()->raw(['credit_card_id' => $card->id]))->save();

    expect($subscription->fresh()->payment_method_id)->toBe($card->paymentMethod->id);
});

test('editing a card does not create or change a payment method', function () {
    $owner = User::factory()->owner()->create();
    $card = CreditCard::factory()->create(['name' => 'ชื่อเดิม']);
    $methodBefore = DB::table('payment_methods')->where('credit_card_id', $card->id)->first();

    $component = Livewire::actingAs($owner)->test(NEW_CARD_PAGE)->call('openEdit', $card->id);
    $component->set('name', 'ชื่อใหม่')->call('save')->assertHasNoErrors();

    expect($card->fresh()->name)->toBe('ชื่อใหม่')
        ->and(PaymentMethod::count())->toBe(1)
        ->and(DB::table('payment_methods')->where('credit_card_id', $card->id)->first())->toEqual($methodBefore);
});

test('saving a new card twice never creates two payment methods', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->make();

    $card->saveWithPaymentMethod();
    $card->saveWithPaymentMethod();

    expect(PaymentMethod::where('credit_card_id', $card->id)->count())->toBe(1);
});

test('an existing payment method of the card is kept, not duplicated', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->create();
    $existing = PaymentMethod::factory()->cash()->create();
    DB::table('payment_methods')->where('id', $existing->id)->update(['credit_card_id' => $card->id, 'name' => 'ชื่อที่ตั้งไว้เอง']);

    $card->saveWithPaymentMethod();

    expect(PaymentMethod::where('credit_card_id', $card->id)->count())->toBe(1)
        ->and(PaymentMethod::where('credit_card_id', $card->id)->value('name'))->toBe('ชื่อที่ตั้งไว้เอง');
});

test('the database itself refuses a second payment method for a card', function () {
    $card = CreditCard::factory()->create();

    expect(fn () => PaymentMethod::factory()->create(['credit_card_id' => $card->id]))
        ->toThrow(QueryException::class);
});

test('if the payment method cannot be created, no card is left behind', function () {
    $owner = User::factory()->owner()->create();
    $cardsBefore = DB::table('credit_cards')->count();
    $methodsBefore = DB::table('payment_methods')->count();

    PaymentMethod::creating(fn () => throw new RuntimeException('จำลองว่าสร้างช่องทางชำระไม่สำเร็จ'));

    try {
        $card = CreditCard::factory()->withoutPaymentMethod()->make(['created_by' => $owner->id]);

        expect(fn () => $card->saveWithPaymentMethod())->toThrow(RuntimeException::class, 'จำลองว่า');

        expect(DB::table('credit_cards')->count())->toBe($cardsBefore)
            ->and(DB::table('payment_methods')->count())->toBe($methodsBefore)
            ->and($card->exists)->toBeFalse()
            ->and($card->getKey())->toBeNull();
    } finally {
        PaymentMethod::flushEventListeners();
    }
});

test('a failure on the page leaves neither a card nor a payment method', function () {
    $owner = User::factory()->owner()->create();

    PaymentMethod::creating(fn () => throw new RuntimeException('จำลองว่าสร้างช่องทางชำระไม่สำเร็จ'));

    try {
        $component = Livewire::actingAs($owner)->test(NEW_CARD_PAGE)->call('openCreate');
        foreach (newCardForm() as $property => $value) {
            $component->set($property, $value);
        }

        expect(fn () => $component->call('save'))->toThrow(RuntimeException::class);

        expect(CreditCard::count())->toBe(0)
            ->and(PaymentMethod::count())->toBe(0);
    } finally {
        PaymentMethod::flushEventListeners();
    }
});

test('existing cards, payment methods and subscriptions are not changed by adding a new card', function () {
    $owner = User::factory()->owner()->create();
    $subscription = Subscription::factory()->create();

    $snapshot = fn () => [
        DB::table('credit_cards')->get()->all(),
        DB::table('payment_methods')->get()->all(),
        DB::table('subscriptions')->get()->all(),
    ];
    $before = $snapshot();

    $component = Livewire::actingAs($owner)->test(NEW_CARD_PAGE)->call('openCreate');
    foreach (newCardForm(['last_four' => '1111']) as $property => $value) {
        $component->set($property, $value);
    }
    $component->call('save')->assertHasNoErrors();

    $after = $snapshot();

    expect($after[0])->toHaveCount(2)
        ->and(array_slice($after[0], 0, 1))->toEqual($before[0])
        ->and(array_slice($after[1], 0, 1))->toEqual($before[1])
        ->and($after[2])->toEqual($before[2])
        ->and($subscription->fresh()->payment_method_id)->toBe($before[1][0]->id);
});
