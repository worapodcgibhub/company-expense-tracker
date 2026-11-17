<?php

use App\Enums\BankAccountKind;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Rules\NoFullAccountNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const ACCOUNT_PAGE = 'pages::bank-accounts.index';

/**
 * The create form filled with valid values (override any of them).
 *
 * @param  array<string, string>  $overrides
 */
function fillAccountForm(Testable $component, array $overrides = []): Testable
{
    $values = array_merge([
        'name' => 'บัญชีออมทรัพย์หลัก',
        'bank' => 'ธนาคารกสิกรไทย',
        'account_name' => 'บริษัท ตัวอย่าง จำกัด',
        'kind' => 'savings',
        'last_four' => '1234',
        'notes' => 'ใช้โอนค่าเช่าสำนักงาน',
    ], $overrides);

    foreach ($values as $property => $value) {
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
    $this->get(route('bank-accounts.index'))->assertRedirect(route('login'));
});

test('staff cannot open the page or use the component directly', function () {
    $staff = User::factory()->create(['role' => UserRole::Staff]);

    $this->actingAs($staff)->get(route('bank-accounts.index'))->assertForbidden();

    Livewire::actingAs($staff)->test(ACCOUNT_PAGE)->assertForbidden();
});

test('a deactivated owner cannot use the component', function () {
    Livewire::actingAs(User::factory()->owner()->deactivated()->create())
        ->test(ACCOUNT_PAGE)
        ->assertForbidden();
});

test('the owner can open the page and sees the accounts', function () {
    BankAccount::factory()->create(['name' => 'บัญชีเงินเดือน', 'last_four' => '9876']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertSee('บัญชีเงินเดือน')
        ->assertSee('••••9876')
        ->assertSee('data-test="add-account-button"', false)
        ->assertDontSee('data-test="read-only-notice"', false);
});

test('the accountant can view the list but sees no management controls', function () {
    BankAccount::factory()->create(['name' => 'บัญชีเงินเดือน']);

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertSee('บัญชีเงินเดือน')
        ->assertSee('data-test="read-only-notice"', false)
        ->assertDontSee('data-test="add-account-button"', false)
        ->assertDontSee('แก้ไขข้อมูล');
});

test('the accountant cannot add, edit, deactivate or reactivate accounts even when calling actions directly', function () {
    $active = BankAccount::factory()->create();
    $inactive = BankAccount::factory()->deactivated()->create();
    $accountant = User::factory()->accountant()->create();
    // A fresh component per call: a forbidden call ends that component.
    $page = fn () => Livewire::actingAs($accountant)->test(ACCOUNT_PAGE);

    $page()->call('openCreate')->assertForbidden();
    fillAccountForm($page())->call('save')->assertForbidden();
    $page()->call('openEdit', $active->id)->assertForbidden();
    $page()->call('deactivate', $active->id)->assertForbidden();
    $page()->call('reactivate', $inactive->id)->assertForbidden();

    expect(BankAccount::count())->toBe(2)
        ->and($active->fresh()->isActive())->toBeTrue()
        ->and($inactive->fresh()->isActive())->toBeFalse();
});

test('the bank account menu is shown to the owner and accountant only', function (UserRole $role, bool $visible) {
    $response = $this->actingAs(User::factory()->create(['role' => $role]))->get(route('dashboard'));

    $visible
        ? $response->assertSee('data-test="bank-accounts-menu"', false)
        : $response->assertDontSee('data-test="bank-accounts-menu"', false);
})->with([
    'owner' => [UserRole::Owner, true],
    'accountant' => [UserRole::Accountant, true],
    'staff' => [UserRole::Staff, false],
]);

test('nobody, not even the owner, is allowed to delete an account', function () {
    $account = BankAccount::factory()->create();
    $owner = User::factory()->owner()->create();

    expect(Gate::forUser($owner)->denies('delete', $account))->toBeTrue()
        ->and(Gate::forUser($owner)->denies('forceDelete', $account))->toBeTrue()
        ->and(method_exists(Livewire::actingAs($owner)->test(ACCOUNT_PAGE)->instance(), 'delete'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Create and edit
|--------------------------------------------------------------------------
*/

test('the owner can add an account', function () {
    $owner = User::factory()->owner()->create();

    $component = Livewire::actingAs($owner)->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, ['last_four' => ' 0042 ', 'name' => '  บัญชีหลัก  '])
        ->call('save')
        ->assertHasNoErrors();

    $account = BankAccount::sole();

    expect($account->name)->toBe('บัญชีหลัก')
        ->and($account->bank)->toBe('ธนาคารกสิกรไทย')
        ->and($account->account_name)->toBe('บริษัท ตัวอย่าง จำกัด')
        ->and($account->kind)->toBe(BankAccountKind::Savings)
        ->and($account->last_four)->toBe('0042')
        ->and($account->notes)->toBe('ใช้โอนค่าเช่าสำนักงาน')
        ->and($account->isActive())->toBeTrue()
        ->and($account->created_by)->toBe($owner->id)
        ->and($account->updated_by)->toBe($owner->id);
});

test('adding an account does not create a payment method yet', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component)->call('save')->assertHasNoErrors();

    expect(PaymentMethod::count())->toBe(0);
});

test('empty notes are stored as null', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, ['notes' => ''])->call('save')->assertHasNoErrors();

    expect(BankAccount::sole()->notes)->toBeNull();
});

test('the owner can edit an account', function () {
    $owner = User::factory()->owner()->create();
    $creator = User::factory()->owner()->create();
    $account = BankAccount::factory()->create(['name' => 'ชื่อเดิม', 'last_four' => '1111', 'created_by' => $creator->id]);

    Livewire::actingAs($owner)->test(ACCOUNT_PAGE)
        ->call('openEdit', $account->id)
        ->assertSet('name', 'ชื่อเดิม')
        ->assertSet('last_four', '1111')
        ->set('name', 'ชื่อใหม่')
        ->set('kind', 'savings')
        ->set('last_four', '2222')
        ->call('save')
        ->assertHasNoErrors();

    $account->refresh();

    expect(BankAccount::count())->toBe(1)
        ->and($account->name)->toBe('ชื่อใหม่')
        ->and($account->kind)->toBe(BankAccountKind::Savings)
        ->and($account->last_four)->toBe('2222')
        ->and($account->created_by)->toBe($creator->id)
        ->and($account->updated_by)->toBe($owner->id);
});

test('protected columns cannot be mass assigned', function () {
    $account = new BankAccount;
    $account->fill(['name' => 'x', 'deactivated_at' => now(), 'created_by' => 5, 'updated_by' => 6]);

    expect($account->deactivated_at)->toBeNull()
        ->and($account->created_by)->toBeNull()
        ->and($account->updated_by)->toBeNull();
});

test('the table has no column for sensitive bank data', function () {
    $columns = Schema::getColumnListing('bank_accounts');

    foreach (['account_number', 'number', 'password', 'pin', 'otp', 'promptpay', 'username', 'token'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }

    expect($columns)->toContain('last_four');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

test('required fields are validated', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    $component->set('kind', '')->call('save')
        ->assertHasErrors(['name', 'bank', 'account_name', 'kind', 'last_four']);

    expect(BankAccount::count())->toBe(0);
});

test('the last four digits must be exactly four digits', function (string $value) {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, ['last_four' => $value])->call('save')->assertHasErrors(['last_four']);

    expect(BankAccount::count())->toBe(0);
})->with(['too short' => '123', 'too long' => '12345', 'letters' => 'abcd', 'full number' => '1234567890']);

test('the account kind must be a known value', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, ['kind' => 'crypto'])->call('save')->assertHasErrors(['kind']);
});

test('text fields have length limits', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, [
        'name' => str_repeat('ก', 101),
        'bank' => str_repeat('ก', 101),
        'account_name' => str_repeat('ก', 151),
        'notes' => str_repeat('ก', 1001),
    ])->call('save')->assertHasErrors(['name', 'bank', 'account_name', 'notes']);
});

test('a full account, card or PromptPay number is rejected in every text field', function (string $field, string $value) {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, [$field => $value])->call('save')->assertHasErrors([$field]);

    expect(BankAccount::count())->toBe(0);
})->with(function () {
    $numbers = [
        'plain account number' => 'เลขที่ 1234567890',
        'dashed account number' => '123-4-56789-0',
        'spaced account number' => '123 456 7890',
        'phone-style PromptPay id' => 'พร้อมเพย์ 0812345678',
        'national id' => '1101700203451',
        'card number' => '4111 1111 1111 1111',
    ];

    foreach (['name', 'bank', 'account_name', 'notes'] as $field) {
        foreach ($numbers as $label => $value) {
            yield "$field: $label" => [$field, $value];
        }
    }
});

test('short numbers in names and notes are allowed', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('openCreate');
    fillAccountForm($component, ['name' => 'บัญชี 2', 'notes' => 'โอนทุกวันที่ 5 ยอดไม่เกิน 100,000 บาท โทรสาขา 02-123'])
        ->call('save')
        ->assertHasNoErrors();

    expect(BankAccount::count())->toBe(1);
});

test('the number rule only blocks runs of ten digits or more', function () {
    expect(NoFullAccountNumber::containsLongNumber('123456789'))->toBeFalse()
        ->and(NoFullAccountNumber::containsLongNumber('1234567890'))->toBeTrue()
        ->and(NoFullAccountNumber::containsLongNumber('123456 and 7890'))->toBeFalse()
        ->and(NoFullAccountNumber::containsLongNumber('12-34-56-78-90'))->toBeTrue()
        ->and(NoFullAccountNumber::containsLongNumber('ไม่มีตัวเลข'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Deactivate / reactivate instead of delete
|--------------------------------------------------------------------------
*/

test('the owner can deactivate an account without deleting it', function () {
    $owner = User::factory()->owner()->create();
    $account = BankAccount::factory()->create();

    Livewire::actingAs($owner)->test(ACCOUNT_PAGE)->call('deactivate', $account->id);

    $account->refresh();

    expect(BankAccount::count())->toBe(1)
        ->and($account->isActive())->toBeFalse()
        ->and($account->updated_by)->toBe($owner->id);
});

test('the owner can reactivate an account', function () {
    $account = BankAccount::factory()->deactivated()->create();

    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('reactivate', $account->id);

    expect($account->fresh()->isActive())->toBeTrue();
});

test('an inactive account cannot be deactivated again and an active one cannot be reactivated', function () {
    $owner = User::factory()->owner()->create();
    $active = BankAccount::factory()->create();
    $inactive = BankAccount::factory()->deactivated()->create();
    $page = fn () => Livewire::actingAs($owner)->test(ACCOUNT_PAGE);

    $page()->call('deactivate', $inactive->id)->assertForbidden();
    $page()->call('reactivate', $active->id)->assertForbidden();
});

test('deactivating keeps the payment methods and their history', function () {
    $method = PaymentMethod::factory()->forBankAccount()->create();

    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)->call('deactivate', $method->bank_account_id);

    expect($method->bankAccount->fresh()->isActive())->toBeFalse()
        ->and(PaymentMethod::count())->toBe(1)
        ->and($method->fresh()->bank_account_id)->toBe($method->bank_account_id);
});

test('the database refuses to delete an account that a payment method uses', function () {
    $method = PaymentMethod::factory()->forBankAccount()->create();

    expect(fn () => DB::table('bank_accounts')->where('id', $method->bank_account_id)->delete())
        ->toThrow(QueryException::class);

    expect(BankAccount::whereKey($method->bank_account_id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| List, filters and search
|--------------------------------------------------------------------------
*/

test('the list shows active accounts by default and can filter by status', function () {
    BankAccount::factory()->create(['name' => 'บัญชีใช้งาน']);
    BankAccount::factory()->deactivated()->create(['name' => 'บัญชีเก่า']);

    $page = Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE);

    $page->assertSee('บัญชีใช้งาน')->assertDontSee('บัญชีเก่า');
    $page->call('setStatus', 'inactive')->assertSee('บัญชีเก่า')->assertDontSee('บัญชีใช้งาน');
    $page->call('setStatus', 'all')->assertSee('บัญชีใช้งาน')->assertSee('บัญชีเก่า');
});

test('an unknown status filter falls back to active accounts', function () {
    BankAccount::factory()->create(['name' => 'บัญชีใช้งาน']);
    BankAccount::factory()->deactivated()->create(['name' => 'บัญชีเก่า']);

    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)
        ->set('status', 'bogus')
        ->assertSee('บัญชีใช้งาน')
        ->assertDontSee('บัญชีเก่า');
});

test('accounts can be searched by name, bank, owner name or last four digits', function (string $term) {
    BankAccount::factory()->create(['name' => 'บัญชีเงินเดือน', 'bank' => 'ธนาคารไทยพาณิชย์', 'account_name' => 'บริษัท สมชาย จำกัด', 'last_four' => '7788']);
    BankAccount::factory()->create(['name' => 'บัญชีอื่น', 'bank' => 'ธนาคารกรุงเทพ', 'account_name' => 'บริษัท อื่น จำกัด', 'last_four' => '0001']);

    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)
        ->set('search', $term)
        ->assertSee('บัญชีเงินเดือน')
        ->assertDontSee('บัญชีอื่น');
})->with(['name' => 'เงินเดือน', 'bank' => 'ไทยพาณิชย์', 'owner' => 'สมชาย', 'last four' => '7788']);

test('an empty state is shown when there are no accounts', function () {
    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)
        ->assertSee('ยังไม่มีบัญชีธนาคารในระบบ');
});

test('the list shows how many payment methods use an account', function () {
    $method = PaymentMethod::factory()->forBankAccount()->create();
    PaymentMethod::factory()->forBankAccount()->deactivated()->create(['bank_account_id' => $method->bank_account_id]);

    Livewire::actingAs(User::factory()->owner()->create())->test(ACCOUNT_PAGE)
        ->assertSee('ใช้เป็นช่องทางชำระเงิน 1 ช่องทาง');
});

test('the page never shows more than the last four digits', function () {
    BankAccount::factory()->create(['last_four' => '5566']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertSee('••••5566');
});
