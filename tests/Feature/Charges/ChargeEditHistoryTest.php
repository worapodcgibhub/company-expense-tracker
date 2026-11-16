<?php

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Exceptions\ChargeConflictException;
use App\Models\AccountingPeriodClosure;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const CHARGE_EDIT_FORM = 'pages::charges.form';
const CHARGE_SHOW = 'pages::charges.show';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
});

/**
 * A charge recorded the normal way (so it has its "recorded" revision).
 */
function recordedCharge(array $subscriptionAttributes = [], array $values = []): SubscriptionCharge
{
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create($subscriptionAttributes);

    return $subscription->recordCharge(CarbonImmutable::parse('2026-11-14'), array_merge([
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::parse('2026-11-14'),
        'amount' => 59_000,
        'amount_thb' => null,
        'credit_card_id' => $subscription->credit_card_id,
        'notes' => null,
    ], $values), test()->owner);
}

function editForm(SubscriptionCharge $charge, ?User $as = null): Testable
{
    return Livewire::actingAs($as ?? test()->owner)->test(CHARGE_EDIT_FORM, ['charge' => $charge]);
}

test('the owner corrects an amount and the old and new values are kept with who and when', function () {
    $charge = recordedCharge();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:30', 'Asia/Bangkok'));

    editForm($charge)
        ->assertSet('amount', '590.00')
        ->set('amount', '612.50')
        ->set('reason', 'ใบแจ้งยอดบัตรมาแล้ว')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('charges.show', $charge));

    $charge->refresh();
    expect($charge->amount)->toBe(61_250)
        ->and($charge->updated_by)->toBe($this->owner->id);

    $revision = $charge->revisions()->latest('id')->first();
    expect($revision->action)->toBe(ChargeRevisionAction::Updated)
        ->and($revision->diff)->toBe(['amount' => [59_000, 61_250]])
        ->and($revision->reason)->toBe('ใบแจ้งยอดบัตรมาแล้ว')
        ->and($revision->changed_by)->toBe($this->owner->id)
        ->and($revision->created_at->format('Y-m-d H:i'))->toBe('2026-11-16 09:30');

    // History on the detail page.
    $this->actingAs($this->owner)->get(route('charges.show', $charge))
        ->assertOk()
        ->assertSee('ยอดเงิน: ฿590.00 → ฿612.50')
        ->assertSee('เหตุผล: ใบแจ้งยอดบัตรมาแล้ว')
        ->assertSee($this->owner->name);
});

test('a reason is required to correct a charge', function () {
    $charge = recordedCharge();

    editForm($charge)->set('amount', '600')->set('reason', '  ')->call('save')->assertHasErrors('reason');

    expect($charge->fresh()->amount)->toBe(59_000)
        ->and($charge->revisions()->count())->toBe(1);
});

test('saving without any change writes no revision', function () {
    $charge = recordedCharge();

    editForm($charge)->set('reason', 'ตรวจดูเฉยๆ')->call('save')->assertHasNoErrors();

    expect($charge->revisions()->count())->toBe(1);
});

test('the baht amount of a USD charge can be filled in later', function () {
    $charge = recordedCharge(['currency' => Currency::USD, 'estimated_amount' => 2_000], ['amount' => 2_000]);
    expect($charge->needsThbAmount())->toBeTrue();

    editForm($charge)->set('amount_thb', '702.40')->set('reason', 'ได้ใบแจ้งยอดบัตรแล้ว')->call('save')->assertHasNoErrors();

    $charge->refresh();
    expect($charge->amount_thb)->toBe(70_240)
        ->and($charge->amount)->toBe(2_000)
        ->and($charge->needsThbAmount())->toBeFalse()
        ->and($charge->revisions()->latest('id')->first()->diff)->toBe(['amount_thb' => [null, 70_240]]);
});

test('several fields changed at once are all recorded', function () {
    $charge = recordedCharge();
    $otherCard = CreditCard::factory()->create();

    editForm($charge)
        ->set('charged_on', '2026-11-13')
        ->set('credit_card_id', (string) $otherCard->id)
        ->set('notes', 'ตัดจากบัตรสำรอง')
        ->set('reason', 'บันทึกบัตรผิด')
        ->call('save')
        ->assertHasNoErrors();

    $changes = $charge->revisions()->latest('id')->first()->diff;
    expect(array_keys($changes))->toBe(['charged_on', 'credit_card_id', 'notes'])
        ->and($changes['charged_on'])->toBe(['2026-11-14', '2026-11-13'])
        ->and($changes['credit_card_id'])->toBe([$charge->credit_card_id, $otherCard->id]);
});

test('correcting a charge never moves the due date or the next charge date', function () {
    $charge = recordedCharge();

    editForm($charge)->set('outcome', 'skipped')->set('reason', 'ผู้ให้บริการคืนเงิน')->call('save')->assertHasNoErrors();

    $charge->refresh();
    expect($charge->due_date->format('Y-m-d'))->toBe('2026-11-14')
        ->and($charge->outcome)->toBe(ChargeOutcome::Skipped)
        ->and($charge->amount)->toBe(0)
        ->and($charge->subscription->next_charge_date->format('Y-m-d'))->toBe('2026-12-14');
});

test('a correction takes back the accountant verification', function () {
    $charge = recordedCharge();
    $charge->verify($this->accountant);

    editForm($charge)->set('amount', '600')->set('reason', 'แก้ยอด')->call('save')->assertHasNoErrors();

    $charge->refresh();
    expect($charge->isVerified())->toBeFalse()
        ->and($charge->revisions()->latest('id')->first()->diff['verified'])->toBe([true, false]);
});

test('changing only the notes keeps the verification', function () {
    $charge = recordedCharge();
    $charge->verify($this->accountant);

    editForm($charge)->set('notes', 'เพิ่มหมายเหตุ')->set('reason', 'เพิ่มข้อมูล')->call('save')->assertHasNoErrors();

    expect($charge->fresh()->isVerified())->toBeTrue();
});

test('verifying and taking it back are both kept in the history', function () {
    $charge = recordedCharge();

    Livewire::actingAs($this->accountant)->test(CHARGE_SHOW, ['charge' => $charge])
        ->call('verify')
        ->call('openUnverify')
        ->set('unverify_reason', 'ยอดไม่ตรงใบแจ้งยอด')
        ->call('confirmUnverify')
        ->assertHasNoErrors();

    $actions = $charge->revisions()->orderBy('id')->get()->map(fn ($r) => $r->action)->all();
    expect($actions)->toBe([ChargeRevisionAction::Recorded, ChargeRevisionAction::Verified, ChargeRevisionAction::Unverified])
        ->and($charge->revisions()->latest('id')->first()->reason)->toBe('ยอดไม่ตรงใบแจ้งยอด')
        ->and($charge->revisions()->where('action', 'verified')->sole()->changed_by)->toBe($this->accountant->id);
});

test('a correction in a closed month needs confirmation and flags the charge', function () {
    $charge = recordedCharge();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00', 'Asia/Bangkok'));
    $closure = AccountingPeriodClosure::close('2026-11', $this->accountant);

    editForm($charge)
        ->set('amount', '650')
        ->set('reason', 'ยอดจริงจากใบแจ้งยอด')
        ->assertSee('data-test="closed-period-warning"', false)
        ->call('save')
        ->assertHasErrors('confirm_closed_period');

    expect($charge->fresh()->amount)->toBe(59_000);

    editForm($charge)
        ->set('amount', '650')
        ->set('reason', 'ยอดจริงจากใบแจ้งยอด')
        ->set('confirm_closed_period', true)
        ->call('save')
        ->assertHasNoErrors();

    $charge->refresh();
    expect($charge->amount)->toBe(65_000)
        ->and($charge->entered_after_close)->toBeTrue()
        ->and($charge->revisions()->latest('id')->first()->in_closed_period)->toBeTrue()
        // The closing figure is not changed automatically.
        ->and($closure->fresh()->snapshot['thb_total'])->toBe(59_000)
        ->and(AccountingPeriodClosure::isClosed('2026-11'))->toBeTrue();
});

test('moving a charge out of a closed month still counts as touching it', function () {
    $charge = recordedCharge();
    $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00', 'Asia/Bangkok'));
    AccountingPeriodClosure::close('2026-11', $this->accountant);

    expect(fn () => $charge->revise([
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::parse('2026-12-01'),
        'amount' => 59_000,
        'amount_thb' => null,
        'credit_card_id' => $charge->credit_card_id,
        'notes' => null,
    ], $this->owner, 'ย้ายเดือน'))->toThrow(ChargeConflictException::class);
});

test('charges are never deleted', function () {
    $charge = recordedCharge();

    $this->actingAs($this->owner)->delete('/charges/'.$charge->id)->assertStatus(405);

    expect(SubscriptionCharge::count())->toBe(1);
});
