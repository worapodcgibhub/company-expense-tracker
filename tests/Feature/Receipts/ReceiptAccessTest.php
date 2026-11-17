<?php

use App\Enums\ReceiptKind;
use App\Models\ChargeReceipt;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
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
    $this->staff = User::factory()->create();
});

function accessPdf(string $name = 'receipt.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%access test\n");
}

function chargeFor(?User $responsible = null): SubscriptionCharge
{
    $subscription = Subscription::factory()->create(['responsible_user_id' => $responsible?->id ?? User::factory()->create()->id]);

    return SubscriptionCharge::factory()->for($subscription)->create();
}

function storedReceipt(SubscriptionCharge $charge, string $name = 'receipt.pdf'): ChargeReceipt
{
    return $charge->attachReceipt(accessPdf($name), ReceiptKind::Receipt, null, null, test()->owner);
}

/*
|--------------------------------------------------------------------------
| Who may attach / change
|--------------------------------------------------------------------------
*/

test('owner, accountant and the responsible staff member can attach a document', function (string $who) {
    $actor = $who === 'staff' ? $this->staff : ($who === 'owner' ? $this->owner : $this->accountant);
    $charge = chargeFor($this->staff);

    Livewire::actingAs($actor)->test('pages::charges.show', ['charge' => $charge])
        ->call('openUpload')
        ->set('upload', accessPdf())
        ->call('saveReceipt')
        ->assertHasNoErrors();

    expect(ChargeReceipt::sole()->uploaded_by)->toBe($actor->id);
})->with(['owner', 'accountant', 'staff']);

test('staff cannot attach to a subscription they are not responsible for', function () {
    $charge = chargeFor();

    expect($this->staff->can('uploadReceipt', $charge))->toBeFalse();
    Livewire::actingAs($this->staff)->test('pages::charges.show', ['charge' => $charge])->assertForbidden();
});

test('the server refuses an upload even when the action is called directly', function () {
    $charge = chargeFor(User::factory()->create());
    $outsider = User::factory()->create();

    // Outsider can't even open the page.
    Livewire::actingAs($outsider)->test('pages::charges.show', ['charge' => $charge])->assertForbidden();

    // Responsible staff can open it, but a deactivated one cannot upload.
    $deactivated = User::factory()->deactivated()->create();
    $charge->subscription->forceFill(['responsible_user_id' => $deactivated->id])->save();

    expect($deactivated->can('uploadReceipt', $charge->fresh()))->toBeFalse();
    expect(ChargeReceipt::count())->toBe(0);
});

test('the responsible staff member cannot edit, replace or remove documents, or mark "no receipt"', function () {
    $charge = chargeFor($this->staff);
    $receipt = storedReceipt($charge);
    $page = fn () => Livewire::actingAs($this->staff)->test('pages::charges.show', ['charge' => $charge]);

    $page()->call('openEditReceipt', $receipt->id)->assertForbidden();
    $page()->call('openReplace', $receipt->id)->assertForbidden();
    $page()->call('openRemove', $receipt->id)->assertForbidden();
    $page()->call('openUnavailable')->assertForbidden();
    $page()->call('confirmUnavailable')->assertForbidden();

    $this->actingAs($this->staff)->get(route('charges.show', $charge))
        ->assertOk()
        ->assertSee('data-test="upload-receipt-button"', false)
        ->assertDontSee('data-test="remove-receipt-'.$receipt->id.'"', false);

    expect($receipt->fresh()->isRemoved())->toBeFalse();
});

test('the accountant can remove a document', function () {
    $charge = chargeFor();
    $receipt = storedReceipt($charge);

    Livewire::actingAs($this->accountant)->test('pages::charges.show', ['charge' => $charge])
        ->call('openRemove', $receipt->id)
        ->set('receipt_reason', 'ซ้ำกับอีกไฟล์')
        ->call('confirmRemove')
        ->assertHasNoErrors();

    expect($receipt->fresh()->removed_by)->toBe($this->accountant->id);
});

/*
|--------------------------------------------------------------------------
| Opening / downloading files
|--------------------------------------------------------------------------
*/

test('the file opens through the protected route with safe headers', function () {
    $charge = chargeFor();
    $receipt = storedReceipt($charge, 'ใบเสร็จ.pdf');

    $response = $this->actingAs($this->owner)->get(route('receipts.show', $receipt));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->streamedContent())->toStartWith('%PDF-');

    $download = $this->actingAs($this->owner)->get(route('receipts.show', ['receipt' => $receipt, 'download' => 1]));
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment');
});

test('owner and accountant open every document; staff only their own', function () {
    $own = storedReceipt(chargeFor($this->staff));
    $other = storedReceipt(chargeFor());

    $this->actingAs($this->accountant)->get(route('receipts.show', $other))->assertOk();
    $this->actingAs($this->staff)->get(route('receipts.show', $own))->assertOk();
    $this->actingAs($this->staff)->get(route('receipts.show', $other))->assertForbidden();
});

test('guests are sent to the login page', function () {
    $receipt = storedReceipt(chargeFor());

    $this->get(route('receipts.show', $receipt))->assertRedirect(route('login'));
});

test('there is no public URL for a stored file', function () {
    $receipt = storedReceipt(chargeFor());

    expect($this->get('/storage/'.$receipt->path)->status())->toBeIn([403, 404]);
    $this->actingAs($this->owner);
    expect($this->get('/storage/'.$receipt->path)->status())->toBeIn([403, 404]);
});

test('a removed document stays viewable for audit', function () {
    $receipt = storedReceipt(chargeFor());
    $receipt->remove($this->owner, 'แนบผิด');

    $this->actingAs($this->accountant)->get(route('receipts.show', $receipt))->assertOk();
});

test('a missing file gives 404, never another file', function () {
    $receipt = storedReceipt(chargeFor());
    Storage::disk('local')->delete($receipt->path);

    $this->actingAs($this->owner)->get(route('receipts.show', $receipt))->assertNotFound();
});

test('a deactivated owner cannot open documents', function () {
    $receipt = storedReceipt(chargeFor());
    $former = User::factory()->owner()->deactivated()->create();

    expect($former->can('view', $receipt))->toBeFalse();
});

test('documents cannot be deleted through any route', function () {
    $receipt = storedReceipt(chargeFor());

    $this->actingAs($this->owner)->delete('/receipts/'.$receipt->id)->assertStatus(405);

    expect(ChargeReceipt::count())->toBe(1);
});
