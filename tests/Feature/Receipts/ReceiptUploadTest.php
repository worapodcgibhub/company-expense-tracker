<?php

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Models\ChargeReceipt;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ReceiptStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const RECEIPT_PAGE = 'pages::charges.show';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    Storage::fake(ReceiptStorage::DISK);
    $this->owner = User::factory()->owner()->create();
});

/**
 * A real-looking (but tiny) PDF: starts with the PDF signature.
 */
function fakePdf(string $name = 'receipt.pdf', int $kilobytes = 0): UploadedFile
{
    $content = "%PDF-1.4\n%test receipt\n".str_repeat('0', max(0, $kilobytes * 1024 - 24));

    return UploadedFile::fake()->createWithContent($name, $content);
}

/**
 * A charge recorded the normal way (no document yet).
 */
function chargeWithoutReceipt(array $subscription = []): SubscriptionCharge
{
    $s = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create($subscription);

    return $s->recordCharge(CarbonImmutable::parse('2026-11-14'), [
        'outcome' => ChargeOutcome::Charged,
        'charged_on' => CarbonImmutable::parse('2026-11-14'),
        'amount' => 59_000,
        'amount_thb' => null,
        'credit_card_id' => $s->credit_card_id,
        'notes' => null,
    ], test()->owner);
}

function receiptPage(SubscriptionCharge $charge, ?User $as = null): Testable
{
    return Livewire::actingAs($as ?? test()->owner)->test(RECEIPT_PAGE, ['charge' => $charge]);
}

test('a charge can be recorded without a receipt and is waiting for one', function () {
    $charge = chargeWithoutReceipt();

    expect($charge->receiptStatus())->toBe(ReceiptStatus::Waiting);

    $this->actingAs($this->owner)->get(route('charges.show', $charge))
        ->assertOk()
        ->assertSee('รอใบเสร็จ')
        ->assertSee('data-test="upload-receipt-button"', false);
});

test('a document is attached later, stored privately under a random name, with history', function () {
    $charge = chargeWithoutReceipt();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 14:00', 'Asia/Bangkok'));

    receiptPage($charge)
        ->call('openUpload')
        ->set('upload', fakePdf('ใบกำกับภาษี Adobe.pdf'))
        ->set('receipt_kind', 'tax_invoice')
        ->set('document_number', 'INV-2026-0042')
        ->set('document_date', '2026-11-12')
        ->call('saveReceipt')
        ->assertHasNoErrors();

    $receipt = ChargeReceipt::sole();
    expect($receipt->subscription_charge_id)->toBe($charge->id)
        ->and($receipt->kind)->toBe(ReceiptKind::TaxInvoice)
        ->and($receipt->document_number)->toBe('INV-2026-0042')
        // Document date is kept apart from the charge date (14 Nov).
        ->and($receipt->document_date?->format('Y-m-d'))->toBe('2026-11-12')
        ->and($charge->fresh()->charged_on?->format('Y-m-d'))->toBe('2026-11-14')
        ->and($receipt->original_name)->toBe('ใบกำกับภาษี Adobe.pdf')
        ->and($receipt->mime)->toBe('application/pdf')
        ->and($receipt->disk)->toBe('local')
        ->and($receipt->path)->toMatch('#^receipts/2026/11/[0-9a-f-]{36}\.pdf$#')
        ->and($receipt->sha256)->toHaveLength(64)
        ->and($receipt->uploaded_by)->toBe($this->owner->id);

    Storage::disk('local')->assertExists($receipt->path);

    expect($charge->fresh()->receiptStatus())->toBe(ReceiptStatus::Attached);

    $revision = $charge->revisions()->latest('id')->first();
    expect($revision->action)->toBe(ChargeRevisionAction::ReceiptAdded)
        ->and($revision->changed_by)->toBe($this->owner->id)
        ->and($revision->created_at->format('Y-m-d H:i'))->toBe('2026-11-15 14:00')
        ->and($revision->diff['file'])->toBe('ใบกำกับภาษี Adobe.pdf');

    $this->actingAs($this->owner)->get(route('charges.show', $charge))
        ->assertSee('แนบเอกสาร')
        ->assertSee('ไฟล์: ใบกำกับภาษี Adobe.pdf (ใบกำกับภาษี · เลขที่ INV-2026-0042 · ลงวันที่ 12 พ.ย. 2569)');
});

test('attaching a document never changes the charge amounts', function () {
    $charge = chargeWithoutReceipt();
    $before = $charge->only(['amount', 'amount_thb', 'charged_on', 'outcome', 'credit_card_id', 'verified_at']);

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf())->call('saveReceipt')->assertHasNoErrors();

    expect($charge->fresh()->only(array_keys($before)))->toEqual($before);
});

test('JPG and PNG images are accepted', function (string $name) {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', UploadedFile::fake()->image($name, 200, 300))->call('saveReceipt')->assertHasNoErrors();

    expect(ChargeReceipt::sole()->path)->toEndWith(str_ends_with($name, '.png') ? '.png' : '.jpg');
})->with(['photo.jpg', 'scan.png']);

test('files that are not real PDF, JPG or PNG are rejected', function (UploadedFile $file) {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', $file)->call('saveReceipt')->assertHasErrors('upload');

    expect(ChargeReceipt::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('receipts');
})->with([
    'text file' => fn () => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
    'php disguised as pdf' => fn () => UploadedFile::fake()->createWithContent('receipt.pdf', '<?php echo "hi"; ?>'),
    'html disguised as pdf' => fn () => UploadedFile::fake()->createWithContent('receipt.pdf', '<html><script>alert(1)</script></html>'),
    'empty pdf' => fn () => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
    'svg image' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    'exe' => fn () => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00binary"),
]);

test('files over 2 MB are rejected', function () {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf('big.pdf', 2049))->call('saveReceipt')->assertHasErrors(['upload' => 'max']);

    expect(ChargeReceipt::count())->toBe(0);
});

test('a file of exactly 2 MB is accepted', function () {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf('limit.pdf', 2048))->call('saveReceipt')->assertHasNoErrors();

    expect(ChargeReceipt::count())->toBe(1);
});

test('the stored name never comes from the uploaded name', function () {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf('..\\..\\evil.php.pdf'))->call('saveReceipt')->assertHasNoErrors();

    $receipt = ChargeReceipt::sole();
    expect($receipt->path)->not->toContain('evil')
        ->and($receipt->path)->toStartWith('receipts/')
        ->and($receipt->original_name)->not->toContain('\\')
        ->and($receipt->original_name)->not->toContain('/');
});

test('the document date cannot be in the future', function () {
    $charge = chargeWithoutReceipt();

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf())->set('document_date', '2026-11-16')->call('saveReceipt')->assertHasErrors('document_date');
});

test('at most 10 current documents per charge', function () {
    $charge = chargeWithoutReceipt();
    ChargeReceipt::factory()->count(10)->for($charge, 'charge')->create();

    receiptPage($charge)->call('openUpload')->set('upload', fakePdf())->call('saveReceipt')->assertHasErrors('upload');

    expect(ChargeReceipt::count())->toBe(10);
});

test('a skipped cycle has no documents to attach', function () {
    $charge = SubscriptionCharge::factory()->skipped()->create();

    expect($charge->receiptStatus())->toBe(ReceiptStatus::NotNeeded);
    receiptPage($charge)->call('openUpload')->assertForbidden();
});

test('uploading the same file to another charge warns but still attaches', function () {
    $first = chargeWithoutReceipt();
    $second = SubscriptionCharge::factory()->create();
    $file = fakePdf('same.pdf');

    $first->attachReceipt($file, ReceiptKind::Receipt, null, null, $this->owner);

    receiptPage($second)->call('openUpload')->set('upload', fakePdf('same.pdf'))->call('saveReceipt')->assertHasNoErrors();

    expect(ChargeReceipt::count())->toBe(2)
        ->and(ChargeReceipt::pluck('sha256')->unique()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Change, replace, remove (nothing is ever deleted)
|--------------------------------------------------------------------------
*/

test('document details can be corrected and the old values are kept', function () {
    $charge = chargeWithoutReceipt();
    $receipt = $charge->attachReceipt(fakePdf(), ReceiptKind::Receipt, 'R-1', null, $this->owner);

    receiptPage($charge)
        ->call('openEditReceipt', $receipt->id)
        ->set('receipt_kind', 'tax_invoice')
        ->set('document_number', 'INV-9')
        ->set('document_date', '2026-11-10')
        ->call('saveReceiptDetails')
        ->assertHasNoErrors();

    $receipt->refresh();
    expect($receipt->kind)->toBe(ReceiptKind::TaxInvoice)
        ->and($receipt->document_number)->toBe('INV-9');

    $revision = $charge->revisions()->latest('id')->first();
    expect($revision->action)->toBe(ChargeRevisionAction::ReceiptUpdated)
        ->and($revision->diff['changes'])->toBe([
            'kind' => ['receipt', 'tax_invoice'],
            'document_number' => ['R-1', 'INV-9'],
            'document_date' => [null, '2026-11-10'],
        ]);
});

test('replacing a file keeps the old one, linked, with the reason', function () {
    $charge = chargeWithoutReceipt();
    $old = $charge->attachReceipt(fakePdf('blurry.pdf'), ReceiptKind::Receipt, null, null, $this->owner);

    $page = receiptPage($charge)->call('openReplace', $old->id)->set('upload', fakePdf('clear.pdf'));
    $page->set('receipt_reason', '')->call('saveReceipt')->assertHasErrors('receipt_reason');
    $page->set('receipt_reason', 'ไฟล์เดิมไม่ชัด')->call('saveReceipt')->assertHasNoErrors();

    $old->refresh();
    $new = ChargeReceipt::query()->current()->sole();

    expect($new->replaces_id)->toBe($old->id)
        ->and($new->original_name)->toBe('clear.pdf')
        ->and($old->isRemoved())->toBeTrue()
        ->and($old->remove_reason)->toBe('ไฟล์เดิมไม่ชัด')
        ->and($old->removed_by)->toBe($this->owner->id);

    Storage::disk('local')->assertExists($old->path);
    Storage::disk('local')->assertExists($new->path);

    $revision = $charge->revisions()->latest('id')->first();
    expect($revision->action)->toBe(ChargeRevisionAction::ReceiptReplaced)
        ->and($revision->diff['replaced']['file'])->toBe('blurry.pdf')
        ->and($revision->reason)->toBe('ไฟล์เดิมไม่ชัด')
        ->and($charge->fresh()->amount)->toBe(59_000);
});

test('removing a document needs a reason, keeps the row and the file, and the charge', function () {
    $charge = chargeWithoutReceipt();
    $receipt = $charge->attachReceipt(fakePdf(), ReceiptKind::Receipt, null, null, $this->owner);

    $page = receiptPage($charge)->call('openRemove', $receipt->id);
    $page->set('receipt_reason', '')->call('confirmRemove')->assertHasErrors('receipt_reason');
    $page->set('receipt_reason', 'แนบผิดรายการ')->call('confirmRemove')->assertHasNoErrors();

    $receipt->refresh();
    expect($receipt->isRemoved())->toBeTrue()
        ->and(ChargeReceipt::count())->toBe(1)
        ->and(SubscriptionCharge::whereKey($charge->id)->exists())->toBeTrue()
        ->and($charge->fresh()->amount)->toBe(59_000)
        ->and($charge->fresh()->receiptStatus())->toBe(ReceiptStatus::Waiting)
        ->and($charge->revisions()->latest('id')->first()->action)->toBe(ChargeRevisionAction::ReceiptRemoved);

    Storage::disk('local')->assertExists($receipt->path);

    $this->actingAs($this->owner)->get(route('charges.show', $charge))
        ->assertSee('data-test="removed-receipts"', false)
        ->assertSee('เหตุผล: แนบผิดรายการ');
});

test('a removed document cannot be edited, replaced or removed again', function () {
    $charge = chargeWithoutReceipt();
    $receipt = ChargeReceipt::factory()->for($charge, 'charge')->removed()->create();

    receiptPage($charge)->call('openEditReceipt', $receipt->id)->assertNotFound();
    receiptPage($charge)->call('openReplace', $receipt->id)->assertNotFound();
    receiptPage($charge)->call('openRemove', $receipt->id)->assertNotFound();
});

test('a document of another charge cannot be changed from this page', function () {
    $charge = chargeWithoutReceipt();
    $other = ChargeReceipt::factory()->create();

    receiptPage($charge)->call('openRemove', $other->id)->assertNotFound();

    expect($other->fresh()->isRemoved())->toBeFalse();
});

test('if saving the row fails, the new file is not left behind', function () {
    $charge = chargeWithoutReceipt();

    // Simulate a database failure after the file has been stored.
    ChargeReceipt::creating(fn () => throw new RuntimeException('db down'));

    expect(fn () => $charge->attachReceipt(fakePdf(), ReceiptKind::Receipt, null, null, $this->owner))->toThrow(RuntimeException::class);

    expect(ChargeReceipt::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('receipts/2026/11');
});
