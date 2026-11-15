<?php

use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Enums\UserRole;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const CARD_PAGE = 'pages::credit-cards.index';

/**
 * The create form filled with valid values (override any of them).
 *
 * @param  array<string, string>  $overrides
 */
function fillCardForm(Testable $component, array $overrides = []): Testable
{
    $values = array_merge([
        'name' => 'บัตรฝ่ายการตลาด',
        'issuer' => 'ธนาคารกสิกรไทย',
        'network' => 'visa',
        'kind' => 'credit',
        'last_four' => '4242',
        'holder_name' => 'สมชาย ใจดี',
        'notes' => 'ใช้จ่ายค่าโฆษณาออนไลน์',
    ], $overrides);

    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('credit-cards.index'))->assertRedirect(route('login'));
});

test('staff cannot open the card page or use the component directly', function () {
    $staff = User::factory()->create(['role' => UserRole::Staff]);

    $this->actingAs($staff)->get(route('credit-cards.index'))->assertForbidden();

    Livewire::actingAs($staff)->test(CARD_PAGE)->assertForbidden();
});

test('a deactivated owner cannot use the card component', function () {
    Livewire::actingAs(User::factory()->owner()->deactivated()->create())
        ->test(CARD_PAGE)
        ->assertForbidden();
});

test('the owner can open the page and sees the cards', function () {
    CreditCard::factory()->create(['name' => 'บัตรค่าโฆษณา', 'last_four' => '1234']);

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('credit-cards.index'))
        ->assertOk()
        ->assertSee('บัตรค่าโฆษณา')
        ->assertSee('•••• 1234')
        ->assertSee('data-test="add-card-button"', false)
        ->assertDontSee('data-test="read-only-notice"', false);
});

test('the accountant can view the list but sees no management controls', function () {
    CreditCard::factory()->create(['name' => 'บัตรค่าโฆษณา']);

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('credit-cards.index'))
        ->assertOk()
        ->assertSee('บัตรค่าโฆษณา')
        ->assertSee('data-test="read-only-notice"', false)
        ->assertDontSee('data-test="add-card-button"', false)
        ->assertDontSee('แก้ไขข้อมูล');
});

test('the accountant cannot add, edit, deactivate or reactivate cards even when calling actions directly', function () {
    $active = CreditCard::factory()->create();
    $inactive = CreditCard::factory()->deactivated()->create();
    $accountant = User::factory()->accountant()->create();
    // A fresh component per call: a forbidden call ends that component.
    $page = fn () => Livewire::actingAs($accountant)->test(CARD_PAGE);

    $page()->call('openCreate')->assertForbidden();
    fillCardForm($page())->call('save')->assertForbidden();
    $page()->call('openEdit', $active->id)->assertForbidden();
    $page()->call('deactivate', $active->id)->assertForbidden();
    $page()->call('reactivate', $inactive->id)->assertForbidden();

    expect(CreditCard::count())->toBe(2)
        ->and($active->fresh()->isActive())->toBeTrue()
        ->and($inactive->fresh()->isActive())->toBeFalse();
});

test('the finance menu is shown to the owner and accountant only', function (UserRole $role, bool $visible) {
    $response = $this->actingAs(User::factory()->create(['role' => $role]))->get(route('dashboard'));

    $visible
        ? $response->assertSee('data-test="finance-menu"', false)
        : $response->assertDontSee('data-test="finance-menu"', false);
})->with([
    'owner' => [UserRole::Owner, true],
    'accountant' => [UserRole::Accountant, true],
    'staff' => [UserRole::Staff, false],
]);

/*
|--------------------------------------------------------------------------
| Create and edit
|--------------------------------------------------------------------------
*/

test('the owner can add a card', function () {
    $owner = User::factory()->owner()->create();

    $component = Livewire::actingAs($owner)->test(CARD_PAGE)->call('openCreate');
    fillCardForm($component, ['last_four' => ' 0042 ', 'name' => '  บัตรฝ่ายการตลาด  '])
        ->call('save')
        ->assertHasNoErrors();

    $card = CreditCard::sole();

    expect($card->name)->toBe('บัตรฝ่ายการตลาด')
        ->and($card->issuer)->toBe('ธนาคารกสิกรไทย')
        ->and($card->network)->toBe(CardNetwork::Visa)
        ->and($card->kind)->toBe(CardKind::Credit)
        ->and($card->last_four)->toBe('0042')
        ->and($card->holder_name)->toBe('สมชาย ใจดี')
        ->and($card->notes)->toBe('ใช้จ่ายค่าโฆษณาออนไลน์')
        ->and($card->isActive())->toBeTrue()
        ->and($card->created_by)->toBe($owner->id)
        ->and($card->updated_by)->toBe($owner->id);
});

test('empty notes are stored as null', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['notes' => '   '])->call('save')->assertHasNoErrors();

    expect(CreditCard::sole()->notes)->toBeNull();
});

test('the owner can edit a card', function () {
    $creator = User::factory()->owner()->create();
    $editor = User::factory()->owner()->create();
    $card = CreditCard::factory()->create(['name' => 'ชื่อเดิม', 'kind' => CardKind::Credit]);
    $card->forceFill(['created_by' => $creator->id])->save();

    Livewire::actingAs($editor)->test(CARD_PAGE)
        ->call('openEdit', $card->id)
        ->assertSet('name', 'ชื่อเดิม')
        ->set('name', 'ชื่อใหม่')
        ->set('kind', 'virtual')
        ->set('network', 'mastercard')
        ->call('save')
        ->assertHasNoErrors();

    $card->refresh();

    expect($card->name)->toBe('ชื่อใหม่')
        ->and($card->kind)->toBe(CardKind::Virtual)
        ->and($card->network)->toBe(CardNetwork::Mastercard)
        ->and($card->created_by)->toBe($creator->id)
        ->and($card->updated_by)->toBe($editor->id);
});

test('protected columns cannot be mass assigned', function () {
    $card = new CreditCard([
        'name' => 'x',
        'deactivated_at' => now(),
        'created_by' => 999,
        'updated_by' => 999,
    ]);

    expect($card->deactivated_at)->toBeNull()
        ->and($card->created_by)->toBeNull()
        ->and($card->updated_by)->toBeNull();
});

test('the table has no column for sensitive card data', function () {
    expect(Schema::getColumnListing('credit_cards'))->toEqualCanonicalizing([
        'id', 'name', 'issuer', 'network', 'kind', 'last_four', 'holder_name', 'notes',
        'deactivated_at', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ]);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

test('required fields are validated', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['name' => '', 'issuer' => ' ', 'last_four' => '', 'holder_name' => ''])
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'issuer' => 'required', 'last_four' => 'required', 'holder_name' => 'required']);

    expect(CreditCard::count())->toBe(0);
});

test('the last four digits must be exactly four digits', function (string $value) {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['last_four' => $value])
        ->call('save')
        ->assertHasErrors(['last_four']);

    expect(CreditCard::count())->toBe(0);
})->with(['12a4', '123', '12345', '4111111111111111', '-123', '12.3']);

test('network and kind must be known values', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['network' => 'diners', 'kind' => 'gift'])
        ->call('save')
        ->assertHasErrors(['network', 'kind']);
});

test('text fields have length limits', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, [
        'name' => str_repeat('ก', 101),
        'issuer' => str_repeat('ก', 101),
        'holder_name' => str_repeat('ก', 101),
        'notes' => str_repeat('ก', 1001),
    ])->call('save')->assertHasErrors(['name' => 'max', 'issuer' => 'max', 'holder_name' => 'max', 'notes' => 'max']);
});

test('a full card number is rejected in every text field', function (string $field, string $value) {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, [$field => $value])
        ->call('save')
        ->assertHasErrors([$field]);

    expect(CreditCard::count())->toBe(0);
})->with([
    'notes, plain' => ['notes', 'เลขบัตร 4111111111111111'],
    'notes, spaced' => ['notes', '4111 1111 1111 1111 หมดอายุ 12/28'],
    'notes, dashed' => ['notes', '5555-5555-5555-4444'],
    'name' => ['name', 'บัตร 378282246310005'],
    'issuer' => ['issuer', 'KBank 4111111111111111'],
    'holder name' => ['holder_name', 'สมชาย 4111111111111111'],
]);

test('ordinary numbers in notes are allowed', function () {
    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['notes' => 'ติดต่อ 081-234-5678 ตัดบัตรทุกวันที่ 25 วงเงิน 50,000 บาท'])
        ->call('save')
        ->assertHasNoErrors();

    expect(CreditCard::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Possible duplicates (warn, never block)
|--------------------------------------------------------------------------
*/

test('a card with the same last four, network and issuer triggers a warning before saving', function () {
    CreditCard::factory()->create([
        'name' => 'บัตรเดิม', 'issuer' => 'ธนาคารกสิกรไทย', 'network' => CardNetwork::Visa,
        'kind' => CardKind::Debit, 'last_four' => '4242',
    ]);

    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component, ['issuer' => ' ธนาคาร กสิกรไทย '])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('บัตรเดิม')
        ->assertSeeHtml('data-test="duplicate-warning"');

    expect(CreditCard::count())->toBe(1);

    $component->call('saveAnyway')->assertHasNoErrors();

    expect(CreditCard::count())->toBe(2);
});

test('a card with the same last four, network and kind but another issuer also warns', function () {
    CreditCard::factory()->create([
        'issuer' => 'ธนาคารกรุงเทพ', 'network' => CardNetwork::Visa, 'kind' => CardKind::Credit, 'last_four' => '4242',
    ]);

    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component)->call('save');

    expect($component->get('duplicateWarnings'))->toHaveCount(1)
        ->and(CreditCard::count())->toBe(1);
});

test('the same last four alone is not treated as a duplicate', function (array $existing) {
    CreditCard::factory()->create(array_merge(['last_four' => '4242'], $existing));

    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component)->call('save')->assertHasNoErrors();

    expect($component->get('duplicateWarnings'))->toBe([])
        ->and(CreditCard::count())->toBe(2);
})->with([
    'other network' => [['network' => CardNetwork::Mastercard, 'issuer' => 'ธนาคารกสิกรไทย', 'kind' => CardKind::Credit]],
    'other issuer and kind' => [['network' => CardNetwork::Visa, 'issuer' => 'ธนาคารกรุงเทพ', 'kind' => CardKind::Debit]],
]);

test('deactivated cards are also checked for duplicates', function () {
    CreditCard::factory()->deactivated()->create([
        'name' => 'บัตรที่ปิดไปแล้ว', 'issuer' => 'ธนาคารกสิกรไทย', 'network' => CardNetwork::Visa, 'last_four' => '4242',
    ]);

    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component)->call('save')->assertSee('บัตรที่ปิดไปแล้ว')->assertSee('ปิดใช้งานอยู่');

    expect(CreditCard::count())->toBe(1);
});

test('confirming a warning does not carry over to different card details', function () {
    CreditCard::factory()->create(['issuer' => 'ธนาคารกสิกรไทย', 'network' => CardNetwork::Visa, 'kind' => CardKind::Credit, 'last_four' => '4242']);
    CreditCard::factory()->create(['issuer' => 'ธนาคารกรุงเทพ', 'network' => CardNetwork::Visa, 'kind' => CardKind::Credit, 'last_four' => '9999']);

    $component = Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE);

    fillCardForm($component)->call('save');
    expect($component->get('duplicateWarnings'))->toHaveCount(1);

    // Switch to details that match the other card, then confirm the old warning.
    $component->set('last_four', '9999')->call('saveAnyway');

    expect(CreditCard::count())->toBe(2)
        ->and($component->get('duplicateWarnings'))->toHaveCount(1);

    $component->call('saveAnyway');

    expect(CreditCard::count())->toBe(3);
});

test('editing a card does not warn about the card itself', function () {
    $card = CreditCard::factory()->create(['last_four' => '4242', 'network' => CardNetwork::Visa]);

    Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE)
        ->call('openEdit', $card->id)
        ->set('name', 'ชื่อใหม่')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('duplicateWarnings', []);

    expect($card->fresh()->name)->toBe('ชื่อใหม่');
});

/*
|--------------------------------------------------------------------------
| Deactivation (never delete)
|--------------------------------------------------------------------------
*/

test('the owner can deactivate a card without deleting it', function () {
    $owner = User::factory()->owner()->create();
    $card = CreditCard::factory()->create();

    Livewire::actingAs($owner)->test(CARD_PAGE)->call('deactivate', $card->id);

    $card->refresh();

    expect(CreditCard::count())->toBe(1)
        ->and($card->isActive())->toBeFalse()
        ->and($card->updated_by)->toBe($owner->id)
        ->and(CreditCard::active()->count())->toBe(0);
});

test('the owner can reactivate a card', function () {
    $card = CreditCard::factory()->deactivated()->create();

    Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE)->call('reactivate', $card->id);

    expect($card->fresh()->isActive())->toBeTrue();
});

test('an inactive card cannot be deactivated again and an active one cannot be reactivated', function () {
    $active = CreditCard::factory()->create();
    $inactive = CreditCard::factory()->deactivated()->create(['deactivated_at' => now()->subYear()]);
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(CARD_PAGE)->call('deactivate', $inactive->id)->assertForbidden();
    Livewire::actingAs($owner)->test(CARD_PAGE)->call('reactivate', $active->id)->assertForbidden();

    expect($inactive->fresh()->deactivated_at->isSameDay(now()->subYear()))->toBeTrue()
        ->and($active->fresh()->isActive())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Listing, filters and search
|--------------------------------------------------------------------------
*/

test('the list shows active cards by default and can filter by status', function () {
    CreditCard::factory()->create(['name' => 'บัตรใช้งาน']);
    CreditCard::factory()->deactivated()->create(['name' => 'บัตรเลิกใช้']);

    $component = Livewire::actingAs(User::factory()->accountant()->create())->test(CARD_PAGE);

    $component->assertSee('บัตรใช้งาน')->assertDontSee('บัตรเลิกใช้');

    $component->call('setStatus', 'inactive')->assertSee('บัตรเลิกใช้')->assertDontSee('บัตรใช้งาน');

    // "All" lists active cards before deactivated ones.
    $component->call('setStatus', 'all');

    expect($component->instance()->cards()->pluck('name')->all())->toBe(['บัตรใช้งาน', 'บัตรเลิกใช้']);
});

test('an unknown status filter falls back to active cards', function () {
    CreditCard::factory()->deactivated()->create(['name' => 'บัตรเลิกใช้']);

    Livewire::actingAs(User::factory()->owner()->create())
        ->withQueryParams(['status' => 'anything'])
        ->test(CARD_PAGE)
        ->assertDontSee('บัตรเลิกใช้');
});

test('cards can be searched by name, issuer, holder or last four digits', function (string $term) {
    CreditCard::factory()->create(['name' => 'บัตรค่าโฆษณา', 'issuer' => 'ธนาคารกรุงไทย', 'holder_name' => 'วิภา สุขใจ', 'last_four' => '7788']);
    CreditCard::factory()->create(['name' => 'บัตรค่าเซิร์ฟเวอร์', 'issuer' => 'ธนาคารกรุงเทพ', 'holder_name' => 'สมชาย ใจดี', 'last_four' => '1100']);

    Livewire::actingAs(User::factory()->owner()->create())->test(CARD_PAGE)
        ->set('search', $term)
        ->assertSee('บัตรค่าโฆษณา')
        ->assertDontSee('บัตรค่าเซิร์ฟเวอร์');
})->with(['โฆษณา', 'กรุงไทย', 'วิภา', '7788']);

test('an empty state is shown when there are no cards', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->get(route('credit-cards.index'))
        ->assertSee('ยังไม่มีบัตรในระบบ');
});
