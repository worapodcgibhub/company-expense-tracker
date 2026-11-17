<?php

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\PaymentMethodMismatchException;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const METHOD_PAGE = 'pages::payment-methods.index';

function methodPage(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? User::factory()->owner()->create())->test(METHOD_PAGE);
}

/** Open the create form, choose a type and fill the common fields. */
function startMethodForm(Testable $component, string $type, array $values = []): Testable
{
    $component->call('openCreate')->set('type', $type);

    foreach (array_merge(['name' => 'ช่องทางทดสอบ'], $values) as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

/*
|--------------------------------------------------------------------------
| Access control (Owner manages, Accountant views, Staff has no access)
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('payment-methods.index'))->assertRedirect(route('login'));
});

test('staff cannot open the page or use the component directly', function () {
    $staff = User::factory()->create(['role' => UserRole::Staff]);

    $this->actingAs($staff)->get(route('payment-methods.index'))->assertForbidden();

    Livewire::actingAs($staff)->test(METHOD_PAGE)->assertForbidden();
});

test('a deactivated owner cannot use the component', function () {
    methodPage(User::factory()->owner()->deactivated()->create())->assertForbidden();
});

test('the owner can open the page and sees the methods', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('payment-methods.index'))
        ->assertOk()
        ->assertSee('เงินสดย่อย')
        ->assertSee('data-test="add-method-button"', false)
        ->assertDontSee('data-test="read-only-notice"', false);
});

test('the accountant can view the list but sees no management controls', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('payment-methods.index'))
        ->assertOk()
        ->assertSee('เงินสดย่อย')
        ->assertSee('data-test="read-only-notice"', false)
        ->assertDontSee('data-test="add-method-button"', false)
        ->assertDontSee('แก้ไขข้อมูล');
});

test('the accountant cannot add, edit, deactivate or reactivate methods even when calling actions directly', function () {
    $active = PaymentMethod::factory()->cash()->create();
    $inactive = PaymentMethod::factory()->cash()->deactivated()->create();
    $accountant = User::factory()->accountant()->create();
    // A fresh component per call: a forbidden call ends that component.
    $page = fn () => methodPage($accountant);

    $page()->call('openCreate')->assertForbidden();
    $page()->set('type', 'cash')->set('name', 'ชื่อใหม่')->call('save')->assertForbidden();
    $page()->call('openEdit', $active->id)->assertForbidden();
    $page()->call('deactivate', $active->id)->assertForbidden();
    $page()->call('reactivate', $inactive->id)->assertForbidden();

    expect(PaymentMethod::count())->toBe(2)
        ->and($active->fresh()->isActive())->toBeTrue()
        ->and($inactive->fresh()->isActive())->toBeFalse();
});

test('the payment method menu is shown to the owner and accountant only', function (UserRole $role, bool $visible) {
    $response = $this->actingAs(User::factory()->create(['role' => $role]))->get(route('dashboard'));

    $visible
        ? $response->assertSee('data-test="payment-methods-menu"', false)
        : $response->assertDontSee('data-test="payment-methods-menu"', false);
})->with([
    'owner' => [UserRole::Owner, true],
    'accountant' => [UserRole::Accountant, true],
    'staff' => [UserRole::Staff, false],
]);

test('nobody, not even the owner, is allowed to delete a method', function () {
    $method = PaymentMethod::factory()->cash()->create();
    $owner = User::factory()->owner()->create();

    expect(Gate::forUser($owner)->denies('delete', $method))->toBeTrue()
        ->and(Gate::forUser($owner)->denies('forceDelete', $method))->toBeTrue()
        ->and(method_exists(methodPage($owner)->instance(), 'delete'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Create: every type
|--------------------------------------------------------------------------
*/

test('the owner can add a bank transfer method for an existing bank account', function () {
    $owner = User::factory()->owner()->create();
    $account = BankAccount::factory()->create();

    startMethodForm(methodPage($owner), 'bank_transfer', ['name' => '  โอนค่าเช่า  ', 'bank_account_id' => (string) $account->id])
        ->call('save')
        ->assertHasNoErrors();

    $method = PaymentMethod::sole();

    expect($method->name)->toBe('โอนค่าเช่า')
        ->and($method->type)->toBe(PaymentMethodType::BankTransfer)
        ->and($method->settlement)->toBe(SettlementMode::Manual)
        ->and($method->bank_account_id)->toBe($account->id)
        ->and($method->credit_card_id)->toBeNull()
        ->and($method->isActive())->toBeTrue()
        ->and($method->hasValidTarget())->toBeTrue()
        ->and($method->created_by)->toBe($owner->id)
        ->and($method->updated_by)->toBe($owner->id);
});

test('the owner can add a direct debit method which defaults to automatic', function () {
    $account = BankAccount::factory()->create();

    $component = startMethodForm(methodPage(), 'direct_debit', ['bank_account_id' => (string) $account->id]);
    $component->assertSet('settlement', 'auto')->call('save')->assertHasNoErrors();

    $method = PaymentMethod::sole();

    expect($method->type)->toBe(PaymentMethodType::DirectDebit)
        ->and($method->settlement)->toBe(SettlementMode::Auto)
        ->and($method->bank_account_id)->toBe($account->id)
        ->and($method->hasValidTarget())->toBeTrue();
});

test('the owner can choose another way of confirming payment than the default', function () {
    $account = BankAccount::factory()->create();

    startMethodForm(methodPage(), 'bank_transfer', ['bank_account_id' => (string) $account->id, 'settlement' => 'auto'])
        ->call('save')
        ->assertHasNoErrors();

    expect(PaymentMethod::sole()->settlement)->toBe(SettlementMode::Auto);
});

test('the same account can have a transfer method and a direct debit method', function () {
    $account = BankAccount::factory()->create();

    startMethodForm(methodPage(), 'bank_transfer', ['name' => 'โอน', 'bank_account_id' => (string) $account->id])->call('save')->assertHasNoErrors();
    startMethodForm(methodPage(), 'direct_debit', ['name' => 'หักบัญชี', 'bank_account_id' => (string) $account->id])->call('save')->assertHasNoErrors();

    expect(PaymentMethod::where('bank_account_id', $account->id)->count())->toBe(2);
});

test('the owner can add a cash method that is not linked to a card or account', function () {
    startMethodForm(methodPage(), 'cash', ['name' => 'เงินสดย่อย'])->call('save')->assertHasNoErrors();

    $method = PaymentMethod::sole();

    expect($method->type)->toBe(PaymentMethodType::Cash)
        ->and($method->settlement)->toBe(SettlementMode::Manual)
        ->and($method->credit_card_id)->toBeNull()
        ->and($method->bank_account_id)->toBeNull()
        ->and($method->hasValidTarget())->toBeTrue();
});

test('a cash method ignores any card or account that is still in the form', function () {
    $account = BankAccount::factory()->create();
    $card = CreditCard::factory()->withoutPaymentMethod()->create();

    startMethodForm(methodPage(), 'cash')
        ->set('bank_account_id', (string) $account->id)
        ->set('credit_card_id', (string) $card->id)
        ->call('save')
        ->assertHasNoErrors();

    $method = PaymentMethod::sole();

    expect($method->bank_account_id)->toBeNull()
        ->and($method->credit_card_id)->toBeNull();
});

test('a card method links an existing card and never creates a new card', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->create(['name' => 'บัตรฝ่ายขาย']);
    $cardsBefore = CreditCard::count();

    startMethodForm(methodPage(), 'card', ['name' => 'บัตรฝ่ายขาย', 'credit_card_id' => (string) $card->id])
        ->assertSet('settlement', 'auto')
        ->call('save')
        ->assertHasNoErrors();

    $method = PaymentMethod::sole();

    expect($method->type)->toBe(PaymentMethodType::Card)
        ->and($method->settlement)->toBe(SettlementMode::Auto)
        ->and($method->credit_card_id)->toBe($card->id)
        ->and($method->bank_account_id)->toBeNull()
        ->and($method->hasValidTarget())->toBeTrue()
        ->and(CreditCard::count())->toBe($cardsBefore);
});

test('only active cards without a method can be chosen', function () {
    $free = CreditCard::factory()->withoutPaymentMethod()->create();
    CreditCard::factory()->create();                                      // already has its method
    CreditCard::factory()->withoutPaymentMethod()->deactivated()->create(); // inactive

    $page = methodPage();

    expect($page->instance()->availableCards->pluck('id')->all())->toBe([$free->id]);
});

test('the form tells the owner when no card is free', function () {
    CreditCard::factory()->create();

    methodPage()->call('openCreate')->set('type', 'card')->assertSeeHtml('data-test="no-available-cards"');
});

test('the form tells the owner when there is no active bank account', function () {
    BankAccount::factory()->deactivated()->create();

    methodPage()->call('openCreate')->set('type', 'bank_transfer')->assertSeeHtml('data-test="no-active-accounts"');
});

test('changing the type clears a link that does not belong to it', function () {
    $account = BankAccount::factory()->create();

    methodPage()->call('openCreate')
        ->set('type', 'bank_transfer')
        ->set('bank_account_id', (string) $account->id)
        ->set('type', 'cash')
        ->assertSet('bank_account_id', '')
        ->assertSet('settlement', 'manual');
});

/*
|--------------------------------------------------------------------------
| Validation and duplicates
|--------------------------------------------------------------------------
*/

test('the name is required and limited to 100 characters', function () {
    startMethodForm(methodPage(), 'cash', ['name' => ''])->call('save')->assertHasErrors(['name' => 'required']);
    startMethodForm(methodPage(), 'cash', ['name' => str_repeat('ก', 101)])->call('save')->assertHasErrors(['name' => 'max']);

    expect(PaymentMethod::count())->toBe(0);
});

test('the name must be unique, ignoring upper and lower case', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'Petty Cash']);

    startMethodForm(methodPage(), 'cash', ['name' => 'petty cash'])->call('save')->assertHasErrors(['name']);

    expect(PaymentMethod::count())->toBe(1);
});

test('a full account, card or PromptPay number is rejected in the name', function (string $value) {
    startMethodForm(methodPage(), 'cash', ['name' => $value])->call('save')->assertHasErrors(['name']);

    expect(PaymentMethod::count())->toBe(0);
})->with(['account' => 'โอนเข้า 123-4-56789-0', 'promptpay' => 'พร้อมเพย์ 0812345678', 'card' => '4111 1111 1111 1111']);

test('the type must be a known value', function () {
    methodPage()->call('openCreate')->set('type', 'crypto')->set('name', 'x')->call('save')->assertHasErrors(['type']);

    expect(PaymentMethod::count())->toBe(0);
});

test('the settlement must be a known value', function () {
    startMethodForm(methodPage(), 'cash', ['settlement' => 'whenever'])->call('save')->assertHasErrors(['settlement']);
});

test('a card method needs a card that exists and is active', function (string $case) {
    $card = CreditCard::factory()->withoutPaymentMethod()->deactivated()->create();
    $value = match ($case) {
        'missing' => '',
        'unknown' => '999999',
        'inactive' => (string) $card->id,
    };

    startMethodForm(methodPage(), 'card', ['credit_card_id' => $value])->call('save')->assertHasErrors(['credit_card_id']);

    expect(PaymentMethod::count())->toBe(0);
})->with(['missing', 'unknown', 'inactive']);

test('a second method cannot be created for a card', function () {
    $card = CreditCard::factory()->create();   // has its method already

    startMethodForm(methodPage(), 'card', ['credit_card_id' => (string) $card->id])
        ->call('save')
        ->assertHasErrors(['credit_card_id' => 'unique']);

    expect(PaymentMethod::where('credit_card_id', $card->id)->count())->toBe(1);
});

test('a transfer or debit method needs a bank account that exists and is active', function (string $type, string $case) {
    $account = BankAccount::factory()->deactivated()->create();
    $value = match ($case) {
        'missing' => '',
        'unknown' => '999999',
        'inactive' => (string) $account->id,
    };

    startMethodForm(methodPage(), $type, ['bank_account_id' => $value])->call('save')->assertHasErrors(['bank_account_id']);

    expect(PaymentMethod::count())->toBe(0);
})->with(function () {
    foreach (['bank_transfer', 'direct_debit'] as $type) {
        foreach (['missing', 'unknown', 'inactive'] as $case) {
            yield "$type: $case" => [$type, $case];
        }
    }
});

test('an account cannot get a second method of the same type, even if the first one is inactive', function () {
    $account = BankAccount::factory()->create();
    PaymentMethod::factory()->forBankAccount()->deactivated()->create(['bank_account_id' => $account->id, 'name' => 'โอนเดิม']);

    startMethodForm(methodPage(), 'bank_transfer', ['name' => 'โอนใหม่', 'bank_account_id' => (string) $account->id])
        ->call('save')
        ->assertHasErrors(['bank_account_id' => 'unique']);

    expect(PaymentMethod::count())->toBe(1);
});

test('a card method does not need a bank account and a transfer does not need a card', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->create();
    $account = BankAccount::factory()->create();

    startMethodForm(methodPage(), 'card', ['name' => 'A', 'credit_card_id' => (string) $card->id, 'bank_account_id' => 'ignored'])
        ->call('save')->assertHasNoErrors();
    startMethodForm(methodPage(), 'bank_transfer', ['name' => 'B', 'bank_account_id' => (string) $account->id, 'credit_card_id' => 'ignored'])
        ->call('save')->assertHasNoErrors();

    expect(PaymentMethod::where('name', 'A')->sole()->bank_account_id)->toBeNull()
        ->and(PaymentMethod::where('name', 'B')->sole()->credit_card_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Edit
|--------------------------------------------------------------------------
*/

test('the owner can rename a method and change how payment is confirmed', function () {
    $owner = User::factory()->owner()->create();
    $creator = User::factory()->owner()->create();
    $method = PaymentMethod::factory()->forBankAccount()->create(['name' => 'ชื่อเดิม', 'created_by' => $creator->id]);

    methodPage($owner)
        ->call('openEdit', $method->id)
        ->assertSet('name', 'ชื่อเดิม')
        ->assertSet('type', 'bank_transfer')
        ->set('name', 'ชื่อใหม่')
        ->set('settlement', 'auto')
        ->call('save')
        ->assertHasNoErrors();

    $method->refresh();

    expect(PaymentMethod::count())->toBe(1)
        ->and($method->name)->toBe('ชื่อใหม่')
        ->and($method->settlement)->toBe(SettlementMode::Auto)
        ->and($method->created_by)->toBe($creator->id)
        ->and($method->updated_by)->toBe($owner->id);
});

test('editing never changes the type, the card or the bank account', function () {
    $other = BankAccount::factory()->create();
    $otherCard = CreditCard::factory()->withoutPaymentMethod()->create();
    $transfer = PaymentMethod::factory()->forBankAccount()->create();
    $card = PaymentMethod::factory()->create();   // a card method
    $before = [$transfer->only(['type', 'bank_account_id', 'credit_card_id']), $card->only(['type', 'bank_account_id', 'credit_card_id'])];

    methodPage()->call('openEdit', $transfer->id)
        ->set('type', 'cash')->set('bank_account_id', (string) $other->id)->set('credit_card_id', (string) $otherCard->id)
        ->set('name', 'ชื่อ 1')->call('save')->assertHasNoErrors();
    methodPage()->call('openEdit', $card->id)
        ->set('type', 'bank_transfer')->set('credit_card_id', (string) $otherCard->id)->set('bank_account_id', (string) $other->id)
        ->set('name', 'ชื่อ 2')->call('save')->assertHasNoErrors();

    expect($transfer->fresh()->only(['type', 'bank_account_id', 'credit_card_id']))->toEqual($before[0])
        ->and($card->fresh()->only(['type', 'bank_account_id', 'credit_card_id']))->toEqual($before[1])
        ->and($transfer->fresh()->name)->toBe('ชื่อ 1')
        ->and($card->fresh()->name)->toBe('ชื่อ 2');
});

test('a method can keep its own name when edited but not take another method\'s name', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);
    $method = PaymentMethod::factory()->forBankAccount()->create(['name' => 'โอนค่าเช่า']);

    methodPage()->call('openEdit', $method->id)->set('settlement', 'auto')->call('save')->assertHasNoErrors();
    methodPage()->call('openEdit', $method->id)->set('name', 'เงินสดย่อย')->call('save')->assertHasErrors(['name']);

    expect($method->fresh()->name)->toBe('โอนค่าเช่า');
});

/*
|--------------------------------------------------------------------------
| Deactivate / reactivate instead of delete
|--------------------------------------------------------------------------
*/

test('the owner can deactivate a method without deleting it', function () {
    $owner = User::factory()->owner()->create();
    $method = PaymentMethod::factory()->cash()->create();

    methodPage($owner)->call('deactivate', $method->id);

    $method->refresh();

    expect(PaymentMethod::count())->toBe(1)
        ->and($method->isActive())->toBeFalse()
        ->and($method->updated_by)->toBe($owner->id);
});

test('deactivating a method that is in use keeps every subscription and charge as it was', function () {
    $subscription = Subscription::factory()->create();
    $charge = SubscriptionCharge::factory()->for($subscription)->create();
    $methodId = $subscription->payment_method_id;
    $before = [DB::table('subscriptions')->get()->all(), DB::table('subscription_charges')->get()->all()];

    methodPage()->call('deactivate', $methodId);

    expect(PaymentMethod::find($methodId)->isActive())->toBeFalse()
        ->and([DB::table('subscriptions')->get()->all(), DB::table('subscription_charges')->get()->all()])->toEqual($before)
        ->and($charge->fresh()->payment_method_id)->toBe($methodId);
});

test('the owner can reactivate a method', function () {
    $method = PaymentMethod::factory()->cash()->deactivated()->create();

    methodPage()->call('reactivate', $method->id);

    expect($method->fresh()->isActive())->toBeTrue();
});

test('an inactive method cannot be deactivated again and an active one cannot be reactivated', function () {
    $active = PaymentMethod::factory()->cash()->create();
    $inactive = PaymentMethod::factory()->cash()->deactivated()->create();
    $page = fn () => methodPage();

    $page()->call('deactivate', $inactive->id)->assertForbidden();
    $page()->call('reactivate', $active->id)->assertForbidden();
});

test('a method cannot be switched back on while its card or bank account is off', function () {
    $cardMethod = PaymentMethod::factory()->deactivated()->create();
    $cardMethod->creditCard->forceFill(['deactivated_at' => now()])->save();

    $accountMethod = PaymentMethod::factory()->forBankAccount()->deactivated()->create();
    $accountMethod->bankAccount->forceFill(['deactivated_at' => now()])->save();

    methodPage()->call('reactivate', $cardMethod->id);
    methodPage()->call('reactivate', $accountMethod->id);

    expect($cardMethod->fresh()->isActive())->toBeFalse()
        ->and($accountMethod->fresh()->isActive())->toBeFalse();
});

test('the database refuses to delete a card or account that a method uses', function () {
    $card = PaymentMethod::factory()->create();
    $account = PaymentMethod::factory()->forBankAccount()->create();

    expect(fn () => DB::table('credit_cards')->where('id', $card->credit_card_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('bank_accounts')->where('id', $account->bank_account_id)->delete())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Relations with subscriptions and charges
|--------------------------------------------------------------------------
*/

test('a method knows its subscriptions and charges', function () {
    $subscription = Subscription::factory()->create();
    SubscriptionCharge::factory()->count(2)
        ->sequence(['due_date' => '2026-09-14', 'charged_on' => '2026-09-14'], ['due_date' => '2026-10-14', 'charged_on' => '2026-10-14'])
        ->for($subscription)
        ->create();
    $method = $subscription->paymentMethod;

    expect($method->subscriptions)->toHaveCount(1)
        ->and($method->charges)->toHaveCount(2)
        ->and($method->subscriptions->first()->is($subscription))->toBeTrue();
});

test('the list shows what still uses a method, without counting cancelled subscriptions', function () {
    $open = Subscription::factory()->create();
    Subscription::factory()->create(['credit_card_id' => $open->credit_card_id, 'status' => SubscriptionStatus::Cancelled]);
    SubscriptionCharge::factory()->for($open)->create();

    methodPage()->assertSeeText('ใช้กับ Subscription 1 รายการ · รายการตัดเงิน 1 รายการ');
});

test('the list says when nothing uses a method', function () {
    PaymentMethod::factory()->cash()->create();

    methodPage()->assertSee('ยังไม่มีรายการที่ใช้ช่องทางนี้');
});

/*
|--------------------------------------------------------------------------
| List, filters and search
|--------------------------------------------------------------------------
*/

test('the list shows what each method is linked to, with masked numbers only', function () {
    $card = CreditCard::factory()->create(['name' => 'บัตรโฆษณา', 'last_four' => '4242']);
    $account = BankAccount::factory()->create(['name' => 'บัญชีหลัก', 'bank' => 'ธนาคารกสิกรไทย', 'last_four' => '9876']);
    PaymentMethod::factory()->forBankAccount()->create(['bank_account_id' => $account->id, 'name' => 'โอนค่าเช่า']);
    PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);

    methodPage()
        ->assertSee('บัตรโฆษณา')
        ->assertSee('•••• 4242')
        ->assertSee('บัญชีหลัก')
        ->assertSee('ธนาคารกสิกรไทย')
        ->assertSee('••••9876')
        ->assertSee('ไม่ผูกกับบัตรหรือบัญชีธนาคาร');
});

test('the list shows active methods by default and can filter by status', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'ช่องทางใช้งาน']);
    PaymentMethod::factory()->cash()->deactivated()->create(['name' => 'ช่องทางเก่า']);

    $page = methodPage();

    $page->assertSee('ช่องทางใช้งาน')->assertDontSee('ช่องทางเก่า');
    $page->call('setStatus', 'inactive')->assertSee('ช่องทางเก่า')->assertDontSee('ช่องทางใช้งาน');
    $page->call('setStatus', 'all')->assertSee('ช่องทางใช้งาน')->assertSee('ช่องทางเก่า');
});

test('an unknown status or type filter falls back to showing everything active', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'ช่องทางใช้งาน']);
    PaymentMethod::factory()->cash()->deactivated()->create(['name' => 'ช่องทางเก่า']);

    methodPage()->set('status', 'bogus')->set('typeFilter', 'bogus')
        ->assertSee('ช่องทางใช้งาน')
        ->assertDontSee('ช่องทางเก่า');
});

test('methods can be filtered by type', function () {
    PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);
    PaymentMethod::factory()->forBankAccount()->create(['name' => 'โอนเงินเดือน']);
    PaymentMethod::factory()->forBankAccount(PaymentMethodType::DirectDebit)->create(['name' => 'หักค่าน้ำ']);

    $page = methodPage();

    $page->set('typeFilter', 'cash')->assertSee('เงินสดย่อย')->assertDontSee('โอนเงินเดือน')->assertDontSee('หักค่าน้ำ');
    $page->set('typeFilter', 'bank_transfer')->assertSee('โอนเงินเดือน')->assertDontSee('เงินสดย่อย');
    $page->set('typeFilter', 'direct_debit')->assertSee('หักค่าน้ำ')->assertDontSee('โอนเงินเดือน');
    $page->set('typeFilter', 'all')->assertSee('เงินสดย่อย')->assertSee('โอนเงินเดือน')->assertSee('หักค่าน้ำ');
});

test('methods can be searched by name, card or bank account', function (string $term) {
    $card = CreditCard::factory()->create(['name' => 'บัตรการตลาด', 'issuer' => 'ธนาคารกรุงไทย', 'last_four' => '5151']);
    $account = BankAccount::factory()->create(['name' => 'บัญชีเงินเดือน', 'bank' => 'ธนาคารไทยพาณิชย์', 'last_four' => '7788']);
    $card->paymentMethod->forceFill(['name' => 'ช่องทางบัตร'])->save();
    PaymentMethod::factory()->forBankAccount()->create(['bank_account_id' => $account->id, 'name' => 'ช่องทางโอน']);
    PaymentMethod::factory()->cash()->create(['name' => 'ช่องทางเงินสด']);

    $expected = in_array($term, ['ช่องทางบัตร', 'การตลาด', 'กรุงไทย', '5151'], true) ? 'ช่องทางบัตร' : 'ช่องทางโอน';
    $other = $expected === 'ช่องทางบัตร' ? 'ช่องทางโอน' : 'ช่องทางบัตร';

    methodPage()->set('search', $term)
        ->assertSee($expected)
        ->assertDontSee($other)
        ->assertDontSee('ช่องทางเงินสด');
})->with(['name' => 'ช่องทางบัตร', 'card name' => 'การตลาด', 'card issuer' => 'กรุงไทย', 'card last four' => '5151', 'account name' => 'เงินเดือน', 'account bank' => 'ไทยพาณิชย์', 'account last four' => '7788']);

test('an empty state is shown when there are no methods', function () {
    DB::table('payment_methods')->delete();

    methodPage()->assertSee('ยังไม่มีช่องทางชำระเงินในระบบ');
});

test('the page works with the payment methods of a new card', function () {
    $card = CreditCard::factory()->withoutPaymentMethod()->make(['name' => 'บัตรใหม่ล่าสุด']);
    $card->saveWithPaymentMethod();

    methodPage()->assertSee('บัตรใหม่ล่าสุด')->assertSee('ตัดเงินอัตโนมัติ');
});

/*
|--------------------------------------------------------------------------
| Phase 3.1d: deactivating a method that is still referenced
|--------------------------------------------------------------------------
*/

test('the deactivate confirmation names the open subscriptions affected and skips cancelled ones', function () {
    $open = Subscription::factory()->create(['name' => 'Figma ทีมออกแบบ']);
    Subscription::factory()->create(['credit_card_id' => $open->credit_card_id, 'name' => 'Notion ทีมขาย']);
    Subscription::factory()->create(['credit_card_id' => $open->credit_card_id, 'name' => 'บริการที่ยกเลิกแล้ว', 'status' => SubscriptionStatus::Cancelled]);

    methodPage()
        ->assertSee('Figma ทีมออกแบบ')
        ->assertSee('Notion ทีมขาย')
        ->assertSee('ระบบจะไม่เปลี่ยนช่องทางให้เอง')
        ->assertDontSee('บริการที่ยกเลิกแล้ว');
});

test('a method with only past charges can be deactivated and the history stays', function () {
    $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Cancelled]);
    SubscriptionCharge::factory()->for($subscription)->create();
    $methodId = $subscription->payment_method_id;
    $before = DB::table('subscription_charges')->get()->all();

    methodPage()->call('deactivate', $methodId)->assertHasNoErrors();

    expect(PaymentMethod::find($methodId)->isActive())->toBeFalse()
        ->and(DB::table('subscription_charges')->get()->all())->toEqual($before)
        ->and(Subscription::find($subscription->id)->payment_method_id)->toBe($methodId);
});

test('deactivating a method flags its open subscriptions without changing them', function () {
    $subscription = Subscription::factory()->create();
    $methodId = $subscription->payment_method_id;

    expect($subscription->fresh()->needsNewPaymentMethod())->toBeFalse();

    methodPage()->call('deactivate', $methodId);

    $fresh = $subscription->fresh();

    expect($fresh->needsNewPaymentMethod())->toBeTrue()
        ->and($fresh->payment_method_id)->toBe($methodId)
        ->and($fresh->credit_card_id)->toBe($subscription->credit_card_id);

    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(route('subscriptions.show', $subscription))->assertSee('data-test="method-deactivated-warning"', false);
    $this->actingAs($owner)->get(route('subscriptions.index'))->assertSee('data-test="method-deactivated-badge"', false);
    $this->actingAs($owner)->get(route('dashboard'))->assertSee('ช่องทางชำระเงินถูกปิดใช้งาน');
});

test('a cancelled subscription is not flagged for its deactivated method', function () {
    $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::Cancelled]);
    $subscription->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    expect($subscription->fresh()->needsNewPaymentMethod())->toBeFalse();
});

test('a deactivated card is flagged as a card problem, not twice', function () {
    $subscription = Subscription::factory()->create();
    $subscription->creditCard->forceFill(['deactivated_at' => now()])->save();

    $fresh = $subscription->fresh();

    expect($fresh->needsNewCard())->toBeTrue()
        ->and($fresh->needsNewPaymentMethod())->toBeFalse();
});

test('a deactivated bank account makes its method unusable and flags the subscriptions using it', function () {
    $method = PaymentMethod::factory()->forBankAccount()->create();
    $method->bankAccount->forceFill(['deactivated_at' => now()])->save();

    expect($method->fresh()->isActive())->toBeTrue()
        ->and($method->fresh()->isUsable())->toBeFalse();
});

test('a new subscription cannot take a deactivated method but an existing one keeps saving', function () {
    $subscription = Subscription::factory()->create();
    $subscription->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    // Old row still saves (value untouched).
    $subscription->fresh()->forceFill(['notes' => 'แก้หมายเหตุ'])->save();
    expect($subscription->fresh()->notes)->toBe('แก้หมายเหตุ');

    // A new row on the card whose method is off is refused.
    expect(fn () => Subscription::factory()->create(['credit_card_id' => $subscription->credit_card_id]))
        ->toThrow(PaymentMethodMismatchException::class);
});

test('a charge may still record a deactivated method', function () {
    $subscription = Subscription::factory()->create();
    $subscription->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    $charge = SubscriptionCharge::factory()->for($subscription)->create();

    expect($charge->payment_method_id)->toBe($subscription->payment_method_id);
});

test('the subscription form does not offer a card whose method is deactivated, but keeps the current one', function () {
    $current = Subscription::factory()->create();
    $offAndUnused = CreditCard::factory()->create(['name' => 'บัตรช่องทางปิด']);
    $offAndUnused->paymentMethod->forceFill(['deactivated_at' => now()])->save();
    $current->paymentMethod->forceFill(['deactivated_at' => now()])->save();

    $cards = Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::subscriptions.form', ['subscription' => $current])
        ->instance()->cards;

    expect($cards->pluck('id')->all())->toContain($current->credit_card_id)
        ->and($cards->pluck('id')->all())->not->toContain($offAndUnused->id);
});
