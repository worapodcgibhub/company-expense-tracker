<?php

use App\Enums\ChargeRevisionAction;
use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Exceptions\ChargeConflictException;
use App\Models\AccountingPeriodClosure;
use App\Models\ChargeReceipt;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use App\Support\ReceiptStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    Storage::fake(ReceiptStorage::DISK);
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
});

function statusPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%status test\n");
}

function namedCharge(string $name, ?User $responsible = null, string $date = '2026-11-10'): SubscriptionCharge
{
    $attributes = ['name' => $name];
    if ($responsible !== null) {
        $attributes['responsible_user_id'] = $responsible->id;
    }

    return SubscriptionCharge::factory()->for(Subscription::factory()->create($attributes))->chargedOn(CarbonImmutable::parse($date))->create();
}

test('"no receipt" needs a reason and is different from "waiting for receipt"', function () {
    $charge = namedCharge('Zoom');

    $page = Livewire::actingAs($this->accountant)->test('pages::charges.show', ['charge' => $charge])->call('openUnavailable');
    $page->set('unavailable_reason', '  ')->call('confirmUnavailable')->assertHasErrors('unavailable_reason');
    expect($charge->fresh()->receiptStatus())->toBe(ReceiptStatus::Waiting);

    $page->set('unavailable_reason', 'ผู้ให้บริการไม่ออกใบเสร็จ')->call('confirmUnavailable')->assertHasNoErrors();

    $charge->refresh();
    expect($charge->receiptStatus())->toBe(ReceiptStatus::Unavailable)
        ->and($charge->receipt_unavailable_reason)->toBe('ผู้ให้บริการไม่ออกใบเสร็จ')
        ->and($charge->receipt_unavailable_by)->toBe($this->accountant->id)
        ->and($charge->revisions()->latest('id')->first()->action)->toBe(ChargeRevisionAction::ReceiptUnavailable);

    $this->actingAs($this->accountant)->get(route('charges.show', $charge))
        ->assertSee('data-test="receipt-unavailable-note"', false)
        ->assertSee('เหตุผล: ผู้ให้บริการไม่ออกใบเสร็จ');
});

test('"no receipt" cannot be set when a document is attached', function () {
    $charge = namedCharge('Zoom');
    $charge->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->owner);

    expect(fn () => $charge->markReceiptUnavailable($this->owner, 'ไม่มี'))->toThrow(ChargeConflictException::class);
});

test('attaching a document later clears "no receipt" and the history says so', function () {
    $charge = namedCharge('Zoom');
    $charge->markReceiptUnavailable($this->owner, 'หาไม่เจอ');

    $charge->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->accountant);

    $charge->refresh();
    expect($charge->receiptStatus())->toBe(ReceiptStatus::Attached)
        ->and($charge->receipt_unavailable_at)->toBeNull()
        ->and($charge->revisions()->latest('id')->first()->diff['cleared_unavailable'])->toBeTrue();
});

test('"no receipt" can be taken back to "waiting"', function () {
    $charge = namedCharge('Zoom');
    $charge->markReceiptUnavailable($this->owner, 'หาไม่เจอ');

    Livewire::actingAs($this->owner)->test('pages::charges.show', ['charge' => $charge])->call('clearUnavailable')->assertHasNoErrors();

    $charge->refresh();
    expect($charge->receiptStatus())->toBe(ReceiptStatus::Waiting)
        ->and($charge->revisions()->latest('id')->first()->diff['previous_reason'])->toBe('หาไม่เจอ');
});

test('the database query and the model agree on every status', function () {
    $waiting = namedCharge('A');
    $attached = namedCharge('B');
    $attached->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->owner);
    $unavailable = namedCharge('C');
    $unavailable->markReceiptUnavailable($this->owner, 'ไม่มี');
    $removedOnly = namedCharge('D');
    ChargeReceipt::factory()->for($removedOnly, 'charge')->removed()->create();
    $skipped = SubscriptionCharge::factory()->skipped()->create();

    foreach ([$waiting, $attached, $unavailable, $removedOnly, $skipped] as $charge) {
        $status = $charge->fresh()->receiptStatus();
        expect(SubscriptionCharge::query()->receiptStatusIs($status)->pluck('id'))->toContain($charge->id);
    }

    expect(SubscriptionCharge::query()->receiptStatusIs(ReceiptStatus::Waiting)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$waiting->id, $removedOnly->id])->sort()->values()->all());
});

test('the dashboard counts only charges waiting for a receipt', function () {
    namedCharge('A');
    namedCharge('B');
    namedCharge('C')->markReceiptUnavailable($this->owner, 'ไม่มี');
    namedCharge('D')->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->owner);

    expect((new ChargeOverview($this->owner))->waitingForReceiptCount())->toBe(2);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertSee('data-test="missing-receipt-card"', false)
        ->assertSee('2 รายการ')
        ->assertSee('รอใบเสร็จ ต้องติดตาม');
});

test('staff only count and see their own subscriptions', function () {
    $staff = User::factory()->create();
    namedCharge('ของฉัน', $staff);
    namedCharge('ของคนอื่น');
    namedCharge('ของคนอื่น 2');

    expect((new ChargeOverview($staff))->waitingForReceiptCount())->toBe(1);

    $this->actingAs($staff)->get(route('charges.index', ['receipt' => 'waiting']))
        ->assertSee('ของฉัน')
        ->assertDontSee('ของคนอื่น');
});

test('the charge list filters by document status for the accountant', function () {
    namedCharge('Canva รอใบเสร็จ');
    namedCharge('Slack ไม่มีใบเสร็จ')->markReceiptUnavailable($this->owner, 'ไม่มี');
    namedCharge('Figma มีเอกสาร')->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->owner);

    $this->actingAs($this->accountant)->get(route('charges.index', ['receipt' => 'waiting']))
        ->assertSee('Canva รอใบเสร็จ')->assertDontSee('Slack ไม่มีใบเสร็จ')->assertDontSee('Figma มีเอกสาร');

    $this->actingAs($this->accountant)->get(route('charges.index', ['receipt' => 'unavailable']))
        ->assertSee('Slack ไม่มีใบเสร็จ')->assertDontSee('Canva รอใบเสร็จ');

    $this->actingAs($this->accountant)->get(route('charges.index'))
        ->assertSee('data-test="receipt-waiting-badge"', false)
        ->assertSee('data-test="receipt-unavailable-badge"', false)
        ->assertSee('data-test="receipt-attached-badge"', false);
});

test('the accounting periods page shows document status per month', function () {
    namedCharge('A', null, '2026-10-05');
    namedCharge('B', null, '2026-10-06')->markReceiptUnavailable($this->owner, 'ไม่มี');
    namedCharge('C', null, '2026-10-07')->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->owner);

    expect(ChargeOverview::receiptCountsFor('2026-10'))->toBe(['waiting' => 1, 'unavailable' => 1, 'attached' => 1]);

    $this->actingAs($this->accountant)->get(route('accounting-periods.index'))
        ->assertSee('data-test="receipts-2026-10"', false)
        ->assertSee('รอใบเสร็จ 1');
});

test('the accountant is warned when verifying a charge without a receipt', function () {
    $charge = namedCharge('A');

    $this->actingAs($this->accountant)->get(route('charges.show', $charge))
        ->assertSee('data-test="verify-without-receipt-note"', false)
        ->assertSee('data-test="verify-button"', false);
});

test('documents in a closed month can still be attached without changing the period', function () {
    $charge = namedCharge('A', null, '2026-10-05');
    $closure = AccountingPeriodClosure::close('2026-10', $this->accountant);

    $charge->attachReceipt(statusPdf(), ReceiptKind::Receipt, null, null, $this->accountant);

    expect($charge->fresh()->entered_after_close)->toBeFalse()
        ->and(AccountingPeriodClosure::count())->toBe(1)
        ->and($closure->fresh()->snapshot)->toBe($closure->snapshot);
});
