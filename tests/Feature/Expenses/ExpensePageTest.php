<?php

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethodType;
use App\Enums\ReceiptStatus;
use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Phase One-off 1 (mini): the expense pages. Adding, changing and listing,
 * who may do what, the closed accounting month, and that subscription
 * charges are never touched.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
    $this->staff = User::factory()->create();
    $this->otherStaff = User::factory()->create();
    $this->cash = PaymentMethod::factory()->cash()->create(['name' => 'เงินสดย่อย']);
});

function expenseForm(User $as, ?Expense $expense = null)
{
    return Livewire::actingAs($as)->test('pages::expenses.form', $expense ? ['expense' => $expense] : []);
}

function fillExpense($form, array $overrides = [])
{
    return $form
        ->set('title', $overrides['title'] ?? 'ซื้อเมาส์')
        ->set('paid_on', $overrides['paid_on'] ?? '2026-11-10')
        ->set('amount', $overrides['amount'] ?? '1,250.50')
        ->set('category', $overrides['category'] ?? 'equipment')
        ->set('payment_method_id', (string) ($overrides['method'] ?? test()->cash->id))
        ->set('notes', $overrides['notes'] ?? '')
        ->set('receipt_received', $overrides['receipt'] ?? false);
}

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

test('Staff add their own expense in baht, waiting for a receipt', function () {
    fillExpense(expenseForm($this->staff), ['notes' => 'ร้านไอที'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::sole();

    expect($expense->title)->toBe('ซื้อเมาส์')
        ->and($expense->amount)->toBe(125_050)
        ->and($expense->currency->value)->toBe('THB')
        ->and($expense->amount_thb)->toBeNull()
        ->and($expense->category)->toBe(ExpenseCategory::Equipment)
        ->and($expense->paid_on->toDateString())->toBe('2026-11-10')
        ->and($expense->payment_method_id)->toBe($this->cash->id)
        ->and($expense->notes)->toBe('ร้านไอที')
        ->and($expense->owner_user_id)->toBe($this->staff->id)
        ->and($expense->recorded_by)->toBe($this->staff->id)
        ->and($expense->receiptStatus())->toBe(ReceiptStatus::Waiting);
});

test('Owner and Accountant can add expenses too, owned by themselves', function (string $role) {
    fillExpense(expenseForm($this->{$role}))->call('save')->assertHasNoErrors();

    expect(Expense::sole()->owner_user_id)->toBe($this->{$role}->id);
})->with(['owner', 'accountant']);

test('ticking "receipt received" saves the receipt status', function () {
    fillExpense(expenseForm($this->staff), ['receipt' => true])->call('save')->assertHasNoErrors();

    expect(Expense::sole()->receiptStatus())->toBe(ReceiptStatus::Attached);
});

test('works with bank transfer and card methods as well as cash', function () {
    $transfer = PaymentMethod::factory()->forBankAccount(PaymentMethodType::BankTransfer)->create();
    $card = PaymentMethod::factory()->create();

    foreach ([$transfer, $card] as $method) {
        fillExpense(expenseForm($this->staff), ['method' => $method->id])->call('save')->assertHasNoErrors();
    }

    expect(Expense::query()->pluck('payment_method_id')->all())->toEqualCanonicalizing([$transfer->id, $card->id]);
});

test('invalid input is refused and nothing is saved', function (array $overrides, string $field) {
    fillExpense(expenseForm($this->staff), $overrides)->call('save')->assertHasErrors($field);

    expect(Expense::count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'zero amount' => [['amount' => '0'], 'amount'],
    'too many decimals' => [['amount' => '12.345'], 'amount'],
    'not a number' => [['amount' => 'abc'], 'amount'],
    'future date' => [['paid_on' => '2026-11-16'], 'paid_on'],
    'bad date' => [['paid_on' => '2026-02-31'], 'paid_on'],
    'unknown category' => [['category' => 'nope'], 'category'],
    'unknown method' => [['method' => 99999], 'payment_method_id'],
]);

test('a deactivated payment method cannot be chosen for a new expense', function () {
    $off = PaymentMethod::factory()->cash()->deactivated()->create(['name' => 'ปิดแล้ว']);

    fillExpense(expenseForm($this->staff), ['method' => $off->id])->call('save')->assertHasErrors('payment_method_id');
});

test('a full card number is refused in the notes', function () {
    fillExpense(expenseForm($this->staff), ['notes' => 'บัตร 4242 4242 4242 4242'])->call('save')->assertHasErrors('notes');
});

test('adding expenses does not touch subscription charges', function () {
    fillExpense(expenseForm($this->staff))->call('save');

    expect(SubscriptionCharge::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Changing
|--------------------------------------------------------------------------
*/

test('Staff change their own expense and the row keeps its owner', function () {
    $expense = Expense::factory()->ownedBy($this->staff)->create(['title' => 'เดิม', 'amount' => 100_000, 'payment_method_id' => $this->cash->id]);

    expenseForm($this->staff, $expense)
        ->assertSet('title', 'เดิม')
        ->assertSet('amount', '1000.00')
        ->set('title', 'ใหม่')
        ->set('amount', '1,500')
        ->set('receipt_received', true)
        ->call('save')
        ->assertHasNoErrors();

    $expense->refresh();

    expect($expense->title)->toBe('ใหม่')
        ->and($expense->amount)->toBe(150_000)
        ->and($expense->owner_user_id)->toBe($this->staff->id)
        ->and($expense->updated_by)->toBe($this->staff->id)
        ->and($expense->receiptStatus())->toBe(ReceiptStatus::Attached);
});

test('Owner and Accountant change anyone\'s expense without taking it over', function (string $role) {
    $expense = Expense::factory()->ownedBy($this->staff)->create(['payment_method_id' => $this->cash->id]);

    expenseForm($this->{$role}, $expense)->set('title', 'แก้โดยฝ่ายบัญชี')->call('save')->assertHasNoErrors();

    expect($expense->fresh()->title)->toBe('แก้โดยฝ่ายบัญชี')
        ->and($expense->fresh()->owner_user_id)->toBe($this->staff->id)
        ->and($expense->fresh()->updated_by)->toBe($this->{$role}->id);
})->with(['owner', 'accountant']);

test('Staff cannot open or change another person\'s expense', function () {
    $theirs = Expense::factory()->ownedBy($this->otherStaff)->create();

    $this->actingAs($this->staff)->get(route('expenses.edit', $theirs))->assertForbidden();
    expenseForm($this->staff, $theirs)->assertForbidden();
});

test('an expense whose month is closed cannot be opened for change by anyone', function () {
    $expense = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-10-20'))->create();
    AccountingPeriodClosure::close('2026-10', $this->owner);

    foreach (['staff', 'owner', 'accountant'] as $role) {
        $this->actingAs($this->{$role})->get(route('expenses.edit', $expense))->assertForbidden();
    }
});

test('a date in a closed month is refused when adding or moving an expense', function () {
    AccountingPeriodClosure::close('2026-10', $this->owner);

    fillExpense(expenseForm($this->staff), ['paid_on' => '2026-10-20'])
        ->assertSee('งวดบัญชีปิดแล้ว')
        ->call('save')
        ->assertHasErrors('paid_on');

    $open = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-11-05'))->create(['payment_method_id' => $this->cash->id]);

    expenseForm($this->staff, $open)->set('paid_on', '2026-10-20')->call('save')->assertHasErrors('paid_on');

    expect(Expense::count())->toBe(1)
        ->and($open->fresh()->paid_on->toDateString())->toBe('2026-11-05');
});

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

test('Staff list shows only their own expenses, Owner and Accountant see all', function () {
    Expense::factory()->ownedBy($this->staff)->create(['title' => 'ของฉัน', 'paid_on' => '2026-11-10']);
    Expense::factory()->ownedBy($this->otherStaff)->create(['title' => 'ของคนอื่น', 'paid_on' => '2026-11-11']);

    $this->actingAs($this->staff)->get(route('expenses.index'))->assertOk()->assertSee('ของฉัน')->assertDontSee('ของคนอื่น');

    foreach (['owner', 'accountant'] as $role) {
        $this->actingAs($this->{$role})->get(route('expenses.index'))->assertOk()->assertSee('ของฉัน')->assertSee('ของคนอื่น');
    }
});

test('the list totals the month in baht and counts the ones waiting for a receipt', function () {
    Expense::factory()->ownedBy($this->staff)->create(['amount' => 100_000, 'paid_on' => '2026-11-02']);
    Expense::factory()->ownedBy($this->staff)->receiptReceived()->create(['amount' => 250_050, 'paid_on' => '2026-11-03']);
    Expense::factory()->ownedBy($this->staff)->create(['amount' => 999_900, 'paid_on' => '2026-10-31']);

    Livewire::actingAs($this->staff)->test('pages::expenses.index')
        ->assertSee('฿3,500.50')
        ->assertSeeHtml('data-test="expense-waiting-count"')
        ->set('receipt', 'waiting')
        ->assertSee('฿1,000.00')
        ->set('month', 'all')
        ->assertSee('฿10,999.00');
});

test('the list shows edit only where the person may change the expense', function () {
    $mine = Expense::factory()->ownedBy($this->staff)->create();
    $old = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-10-20'))->create();
    AccountingPeriodClosure::close('2026-10', $this->owner);

    Livewire::actingAs($this->staff)->test('pages::expenses.index')
        ->set('month', 'all')
        ->assertSeeHtml('data-test="edit-expense-'.$mine->id.'"')
        ->assertDontSeeHtml('data-test="edit-expense-'.$old->id.'"');
});

test('the menu entry and pages open for every role', function () {
    foreach (['owner', 'accountant', 'staff'] as $role) {
        $this->actingAs($this->{$role})->get(route('expenses.index'))->assertOk()->assertSee('ค่าใช้จ่ายทั่วไป');
        $this->actingAs($this->{$role})->get(route('expenses.create'))->assertOk();
    }
});

test('the empty state shows plain quotation marks, not HTML codes', function () {
    Livewire::actingAs($this->staff)->test('pages::expenses.index')
        ->assertSee('กด "เพิ่มค่าใช้จ่าย" เพื่อบันทึก')
        ->assertDontSee('&amp;quot;', false);
});

test('guests are sent to log in', function () {
    $this->get(route('expenses.index'))->assertRedirect(route('login'));
});
