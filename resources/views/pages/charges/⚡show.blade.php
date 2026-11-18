<?php

use App\Enums\ChargeRevisionAction;
use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Exceptions\ChargeConflictException;
use App\Models\ChargeReceipt;
use App\Models\CreditCard;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionChargeRevision;
use App\Rules\NoFullCardNumber;
use App\Support\ReceiptStorage;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
 * One actual charge with its documents and full change history.
 * Viewing follows SubscriptionChargePolicy::view (Staff only charges of
 * their own subscriptions). The accountant verifies here; the Owner opens
 * the correction form. Nothing can be deleted.
 *
 * Documents: the charge can be recorded without one and documents added
 * later. Owner, Accountant and the responsible person attach; Owner and
 * Accountant change, replace, remove (kept, with a reason) or record
 * "no receipt available". Files open only through receipts.show.
 */
new #[Title('รายการตัดเงิน')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $chargeId;

    public string $unverify_reason = '';

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $receipt_kind = 'receipt';
    public string $document_number = '';
    public string $document_date = '';
    public string $receipt_reason = '';
    public string $unavailable_reason = '';

    #[Locked]
    public ?int $replacingId = null;

    #[Locked]
    public ?int $editingReceiptId = null;

    #[Locked]
    public ?int $removingId = null;

    public function mount(SubscriptionCharge $charge): void
    {
        $this->authorize('view', $charge);

        $this->chargeId = $charge->id;
    }

    #[Computed]
    public function charge(): SubscriptionCharge
    {
        return SubscriptionCharge::with(['subscription', 'creditCard', 'paymentMethod.bankAccount', 'recorder', 'updater', 'verifier'])->findOrFail($this->chargeId);
    }

    /**
     * @return Collection<int, SubscriptionChargeRevision>
     */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->charge->revisions()->with('changer')->latest('id')->get();
    }

    /**
     * Cards mentioned in the history, by id (for "บัตร: A → B" lines).
     *
     * @return array<int, CreditCard>
     */
    #[Computed]
    public function historyCards(): array
    {
        $ids = [$this->charge->credit_card_id];

        foreach ($this->revisions as $revision) {
            foreach ((array) ($revision->diff['credit_card_id'] ?? []) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return CreditCard::query()->whereIn('id', array_unique($ids))->get()->keyBy('id')->all();
    }

    public function verify(): void
    {
        $charge = $this->charge;
        $this->authorize('verify', $charge);

        $charge->verify(auth()->user());

        unset($this->charge, $this->revisions);
        Flux::toast(variant: 'success', text: 'ทำเครื่องหมายตรวจสอบแล้ว');
    }

    public function openUnverify(): void
    {
        $this->authorize('unverify', $this->charge);

        $this->unverify_reason = '';
        $this->resetValidation();

        Flux::modal('unverify-form')->show();
    }

    public function confirmUnverify(): void
    {
        $charge = $this->charge;
        $this->authorize('unverify', $charge);

        $validated = $this->validate(
            ['unverify_reason' => ['nullable', 'string', 'max:500', new NoFullCardNumber]],
            [],
            ['unverify_reason' => 'เหตุผล'],
        );

        $charge->unverify(auth()->user(), $validated['unverify_reason']);

        unset($this->charge, $this->revisions);
        Flux::modal('unverify-form')->close();
        Flux::toast(variant: 'success', text: 'ยกเลิกการตรวจสอบแล้ว');
    }

    /*
    |--------------------------------------------------------------------------
    | Documents (Phase 2.5). Every action is authorized again here, because
    | Livewire actions can be called directly.
    |--------------------------------------------------------------------------
    */

    /**
     * Every document, current ones first.
     *
     * @return Collection<int, ChargeReceipt>
     */
    #[Computed]
    public function receipts(): Collection
    {
        return $this->charge->receipts()
            ->with(['uploader', 'remover'])
            ->orderByRaw('removed_at IS NOT NULL')
            ->orderByDesc('id')
            ->get();
    }

    public function openUpload(): void
    {
        $this->authorize('uploadReceipt', $this->charge);

        $this->resetReceiptForm();
        Flux::modal('receipt-form')->show();
    }

    /**
     * Replace: upload a new file for an existing document (Owner/Accountant).
     */
    public function openReplace(int $receiptId): void
    {
        $this->authorize('manageReceipts', $this->charge);
        $receipt = $this->currentReceipt($receiptId);

        $this->resetReceiptForm();
        $this->replacingId = $receipt->id;
        $this->receipt_kind = $receipt->kind->value;
        $this->document_number = $receipt->document_number ?? '';
        $this->document_date = $receipt->document_date?->format('Y-m-d') ?? '';

        Flux::modal('receipt-form')->show();
    }

    public function saveReceipt(): void
    {
        $charge = $this->charge;
        $replacing = $this->replacingId !== null ? $this->currentReceipt($this->replacingId) : null;

        $replacing !== null
            ? $this->authorize('manageReceipts', $charge)
            : $this->authorize('uploadReceipt', $charge);

        $this->document_number = trim($this->document_number);
        $this->receipt_reason = trim($this->receipt_reason);

        $validated = $this->validate([
            'upload' => ReceiptStorage::rules(),
            ...$this->detailRules(),
            'receipt_reason' => [Rule::requiredIf($replacing !== null), 'nullable', 'string', 'max:500', new NoFullCardNumber],
        ], self::RECEIPT_MESSAGES, self::RECEIPT_ATTRIBUTES);

        /** @var TemporaryUploadedFile $file */
        $file = $validated['upload'];

        try {
            $receipt = $charge->attachReceipt(
                $file,
                ReceiptKind::from($validated['receipt_kind']),
                $validated['document_number'] ?? null,
                $this->parsedDocumentDate(),
                auth()->user(),
                $replacing,
                $validated['receipt_reason'] ?? null,
            );
        } catch (ChargeConflictException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $duplicates = ChargeReceipt::query()
            ->current()
            ->where('sha256', $receipt->sha256)
            ->where('subscription_charge_id', '!=', $charge->id)
            ->count();

        $this->resetReceiptForm();
        unset($this->charge, $this->receipts, $this->revisions);
        Flux::modal('receipt-form')->close();

        $duplicates > 0
            ? Flux::toast(variant: 'warning', text: 'แนบเอกสารแล้ว แต่ไฟล์นี้เหมือนกับเอกสารของรายการตัดเงินอื่น '.$duplicates.' รายการ กรุณาตรวจว่าแนบถูกรายการ')
            : Flux::toast(variant: 'success', text: $replacing !== null ? 'เปลี่ยนไฟล์เอกสารแล้ว' : 'แนบเอกสารแล้ว');
    }

    public function openEditReceipt(int $receiptId): void
    {
        $this->authorize('manageReceipts', $this->charge);
        $receipt = $this->currentReceipt($receiptId);

        $this->resetReceiptForm();
        $this->editingReceiptId = $receipt->id;
        $this->receipt_kind = $receipt->kind->value;
        $this->document_number = $receipt->document_number ?? '';
        $this->document_date = $receipt->document_date?->format('Y-m-d') ?? '';

        Flux::modal('receipt-details')->show();
    }

    public function saveReceiptDetails(): void
    {
        $this->authorize('manageReceipts', $this->charge);
        $receipt = $this->currentReceipt((int) $this->editingReceiptId);

        $this->document_number = trim($this->document_number);
        $validated = $this->validate($this->detailRules(), self::RECEIPT_MESSAGES, self::RECEIPT_ATTRIBUTES);

        $changed = $receipt->updateDetails(
            ReceiptKind::from($validated['receipt_kind']),
            $validated['document_number'] ?? null,
            $this->parsedDocumentDate(),
            auth()->user(),
        );

        $this->resetReceiptForm();
        unset($this->receipts, $this->revisions);
        Flux::modal('receipt-details')->close();
        Flux::toast(variant: $changed ? 'success' : 'warning', text: $changed ? 'บันทึกข้อมูลเอกสารแล้ว' : 'ไม่มีข้อมูลที่เปลี่ยน');
    }

    public function openRemove(int $receiptId): void
    {
        $this->authorize('manageReceipts', $this->charge);
        $receipt = $this->currentReceipt($receiptId);

        $this->resetReceiptForm();
        $this->removingId = $receipt->id;

        Flux::modal('receipt-remove')->show();
    }

    public function confirmRemove(): void
    {
        $this->authorize('manageReceipts', $this->charge);
        $receipt = $this->currentReceipt((int) $this->removingId);

        $this->receipt_reason = trim($this->receipt_reason);
        $validated = $this->validate(
            ['receipt_reason' => ['required', 'string', 'max:500', new NoFullCardNumber]],
            ['receipt_reason.required' => 'กรุณาระบุเหตุผลที่นำเอกสารออก'],
            self::RECEIPT_ATTRIBUTES,
        );

        $receipt->remove(auth()->user(), $validated['receipt_reason']);

        $this->resetReceiptForm();
        unset($this->charge, $this->receipts, $this->revisions);
        Flux::modal('receipt-remove')->close();
        Flux::toast(variant: 'success', text: 'นำเอกสารออกแล้ว (ไฟล์ยังเก็บไว้ในประวัติ)');
    }

    public function openUnavailable(): void
    {
        $this->authorize('markReceiptUnavailable', $this->charge);

        $this->unavailable_reason = '';
        $this->resetValidation();

        Flux::modal('receipt-unavailable')->show();
    }

    public function confirmUnavailable(): void
    {
        $charge = $this->charge;
        $this->authorize('markReceiptUnavailable', $charge);

        $this->unavailable_reason = trim($this->unavailable_reason);
        $validated = $this->validate(
            ['unavailable_reason' => ['required', 'string', 'max:500', new NoFullCardNumber]],
            ['unavailable_reason.required' => 'กรุณาระบุเหตุผลที่ไม่มีใบเสร็จ'],
            ['unavailable_reason' => 'เหตุผล'],
        );

        try {
            $charge->markReceiptUnavailable(auth()->user(), $validated['unavailable_reason']);
        } catch (ChargeConflictException $e) {
            $this->addError('unavailable_reason', $e->getMessage());

            return;
        }

        unset($this->charge, $this->revisions);
        Flux::modal('receipt-unavailable')->close();
        Flux::toast(variant: 'success', text: 'บันทึกว่าไม่มีใบเสร็จแล้ว');
    }

    public function clearUnavailable(): void
    {
        $charge = $this->charge;
        $this->authorize('markReceiptUnavailable', $charge);

        $charge->clearReceiptUnavailable(auth()->user());

        unset($this->charge, $this->revisions);
        Flux::toast(variant: 'success', text: 'กลับเป็นสถานะรอใบเสร็จแล้ว');
    }

    private const RECEIPT_MESSAGES = [
        'upload.required' => 'กรุณาเลือกไฟล์',
        'upload.file' => 'อัปโหลดไม่สำเร็จ กรุณาลองใหม่',
        'upload.uploaded' => 'อัปโหลดไม่สำเร็จ ไฟล์อาจใหญ่เกิน 2 MB',
        'upload.max' => 'ไฟล์ต้องมีขนาดไม่เกิน 2 MB',
        'upload.extensions' => 'รับเฉพาะไฟล์ PDF, JPG หรือ PNG',
        'upload.mimetypes' => 'รับเฉพาะไฟล์ PDF, JPG หรือ PNG (ตรวจจากเนื้อไฟล์จริง)',
        'receipt_reason.required' => 'กรุณาระบุเหตุผลที่เปลี่ยนไฟล์',
    ];

    private const RECEIPT_ATTRIBUTES = [
        'upload' => 'ไฟล์',
        'receipt_kind' => 'ชนิดเอกสาร',
        'document_number' => 'เลขที่เอกสาร',
        'document_date' => 'วันที่ในเอกสาร',
        'receipt_reason' => 'เหตุผล',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    private function detailRules(): array
    {
        return [
            'receipt_kind' => ['required', Rule::enum(ReceiptKind::class)],
            'document_number' => ['nullable', 'string', 'max:100', new NoFullCardNumber],
            'document_date' => ['nullable', 'bail', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
        ];
    }

    private function parsedDocumentDate(): ?CarbonImmutable
    {
        return $this->document_date !== '' ? (CarbonImmutable::createFromFormat('!Y-m-d', $this->document_date) ?: null) : null;
    }

    private function currentReceipt(int $receiptId): ChargeReceipt
    {
        return ChargeReceipt::query()
            ->where('subscription_charge_id', $this->chargeId)
            ->current()
            ->findOrFail($receiptId);
    }

    private function resetReceiptForm(): void
    {
        $this->reset(['upload', 'receipt_kind', 'document_number', 'document_date', 'receipt_reason', 'replacingId', 'editingReceiptId', 'removingId']);
        $this->resetValidation();
    }
}; ?>

@php
    $c = $this->charge;
    $s = $c->subscription;
    $late = $c->daysRecordedLate();
@endphp

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <header class="flex flex-col gap-4">
        <a href="{{ route('subscriptions.show', $s) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> กลับไปหน้า {{ $s->name }}
        </a>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-sm text-slate-500">รายการตัดเงิน · รอบครบกำหนด {{ ThaiFormat::date($c->due_date) }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $s->name }}</h1>
                <div class="mt-2 flex flex-wrap gap-2" data-test="charge-badges">
                    <x-charge-badges :charge="$c" />
                    @if ($s->is_sample)
                        <x-ui.status-badge tone="amber" :dot="false" data-test="sample-badge">ข้อมูลตัวอย่าง</x-ui.status-badge>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('update', $c)
                    <flux:button icon="pencil-square" :href="route('charges.edit', $c)" wire:navigate data-test="edit-charge-button">แก้ไข</flux:button>
                @endcan
                @can('verify', $c)
                    <flux:button icon="check-badge" variant="primary" wire:click="verify" data-test="verify-button">ตรวจสอบแล้ว</flux:button>
                @endcan
                @can('unverify', $c)
                    <flux:button icon="arrow-uturn-left" wire:click="openUnverify" data-test="unverify-button">ยกเลิกการตรวจสอบ</flux:button>
                @endcan
            </div>
        </div>
    </header>

    @if ($c->entered_after_close)
        <flux:callout icon="lock-closed" color="amber" data-test="after-close-notice">
            <flux:callout.text>รายการนี้ถูกบันทึกหรือแก้ไขหลังจากงวดบัญชีของเดือนนั้นปิดแล้ว ยอดของงวดที่ปิดไว้ไม่ได้ถูกเปลี่ยน ฝ่ายบัญชีควรตรวจและปรับปรุงตามขั้นตอนของบริษัท</flux:callout.text>
        </flux:callout>
    @endif

    <x-ui.card>
        <x-ui.section-header title="รายละเอียด" icon="receipt-percent" />
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-slate-500">ผลการตัดเงิน</dt>
                <dd class="mt-0.5 text-slate-900">{{ $c->outcome->label() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">ยอดที่ตัดจริง</dt>
                <dd class="mt-0.5 text-lg font-semibold text-slate-900" data-test="charge-amount">{{ $c->amountLabel() }}</dd>
                <dd class="text-xs text-slate-500">ยอดประมาณการ {{ ThaiFormat::money($s->estimated_amount, $s->currency->value) }}</dd>
            </div>
            @if ($c->isCharged() && $c->currency->value !== 'THB')
                <div>
                    <dt class="text-sm text-slate-500">ยอดเงินบาทที่ธนาคารตัด</dt>
                    <dd class="mt-0.5 text-slate-900" data-test="charge-amount-thb">
                        {{ $c->amount_thb !== null ? ThaiFormat::money($c->amount_thb) : 'ยังไม่ได้กรอก' }}
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-slate-500">{{ $c->paymentMethod !== null && ! $c->paymentMethod->isAuto() ? 'วันที่ชำระจริง' : 'วันที่ตัดเงินจริง' }}</dt>
                <dd class="mt-0.5 text-slate-900">{{ $c->charged_on ? ThaiFormat::date($c->charged_on) : '–' }}</dd>
            </div>
            @php $paidManually = $c->paymentMethod !== null && ! $c->paymentMethod->isAuto(); @endphp
            <div>
                <dt class="text-sm text-slate-500">ช่องทางที่ใช้ชำระจริง</dt>
                <dd class="mt-0.5 text-slate-900" data-test="charge-method">
                    @if ($c->creditCard)
                        {{ $c->creditCard->name }} <span class="font-mono tracking-wider">{{ $c->creditCard->maskedNumber() }}</span>
                    @elseif ($c->paymentMethod)
                        {{ $c->paymentMethod->name }}
                        @if ($c->paymentMethod->bankAccount)
                            <span class="font-mono tracking-wider">{{ $c->paymentMethod->bankAccount->maskedNumber() }}</span>
                        @endif
                    @else
                        –
                    @endif
                    @if ($c->paymentMethod)
                        <span class="text-xs text-slate-500">({{ $c->paymentMethod->isAuto() ? 'Auto' : 'Manual' }})</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">{{ $paidManually && $c->isCharged() ? 'ยืนยันการชำระโดย' : 'บันทึกโดย' }}</dt>
                <dd class="mt-0.5 text-slate-900" data-test="recorded-by">
                    {{ $c->recorder?->name ?? '–' }} · {{ ThaiFormat::date($c->created_at) }} {{ $c->created_at?->format('H:i') }}
                </dd>
                @if ($late > 0)
                    <dd class="text-xs text-amber-700">บันทึกหลังวันที่ตัดเงิน {{ $late }} วัน</dd>
                @endif
            </div>
            <div>
                <dt class="text-sm text-slate-500">การตรวจสอบของฝ่ายบัญชี</dt>
                <dd class="mt-0.5 text-slate-900">
                    {{ $c->isVerified() ? 'ตรวจสอบแล้วโดย '.($c->verifier?->name ?? '–').' · '.ThaiFormat::date($c->verified_at) : 'ยังไม่ได้ตรวจสอบ' }}
                </dd>
            </div>
        </dl>

        <div class="mt-6 border-t border-slate-100 pt-4">
            <p class="text-sm font-medium text-slate-700">หมายเหตุ</p>
            <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $c->notes ?: '–' }}</p>
        </div>
    </x-ui.card>

    {{-- Documents --}}
    @php
        $receiptStatus = $c->receiptStatus();
        $current = $this->receipts->reject->isRemoved();
        $removed = $this->receipts->filter->isRemoved();
        $canManageReceipts = auth()->user()->can('manageReceipts', $c);
    @endphp
    <x-ui.card data-test="receipts">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-ui.section-header title="เอกสาร (ใบเสร็จ / ใบกำกับภาษี)" description="PDF, JPG หรือ PNG ไม่เกิน 2 MB ต่อไฟล์ · แนบภายหลังได้" icon="document-text" class="!mb-0" />
            <div class="flex flex-wrap gap-2">
                @can('uploadReceipt', $c)
                    <flux:button size="sm" icon="paper-clip" variant="primary" wire:click="openUpload" data-test="upload-receipt-button">แนบเอกสาร</flux:button>
                @endcan
                @if ($receiptStatus === ReceiptStatus::Waiting)
                    @can('markReceiptUnavailable', $c)
                        <flux:button size="sm" icon="no-symbol" wire:click="openUnavailable" data-test="receipt-unavailable-button">ไม่มีใบเสร็จ</flux:button>
                    @endcan
                @endif
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm" data-test="receipt-status">
            <span class="text-slate-500">สถานะเอกสาร:</span>
            <x-ui.status-badge :tone="$receiptStatus->tone()">{{ $receiptStatus->label() }}</x-ui.status-badge>
        </div>

        @if ($receiptStatus === ReceiptStatus::Unavailable)
            <div class="mb-4 flex flex-col gap-2 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200 sm:flex-row sm:items-start sm:justify-between" data-test="receipt-unavailable-note">
                <div>
                    <p class="text-slate-700">เหตุผล: {{ $c->receipt_unavailable_reason }}</p>
                    <p class="text-xs text-slate-500">ระบุโดย {{ $c->receiptUnavailableMarker?->name ?? '–' }} · {{ $c->receipt_unavailable_at ? ThaiFormat::date($c->receipt_unavailable_at) : '' }}</p>
                </div>
                @can('markReceiptUnavailable', $c)
                    <flux:button size="xs" variant="ghost" wire:click="clearUnavailable" data-test="clear-unavailable-button">กลับเป็นรอใบเสร็จ</flux:button>
                @endcan
            </div>
        @endif

        @if ($receiptStatus === ReceiptStatus::Waiting && auth()->user()->can('verify', $c))
            <p class="mb-4 text-xs text-amber-700" data-test="verify-without-receipt-note">รายการนี้ยังไม่มีใบเสร็จ ตรวจสอบได้ แต่ควรติดตามเอกสารก่อน</p>
        @endif

        @if ($current->isEmpty())
            <p class="text-sm text-slate-500">{{ $c->isCharged() ? 'ยังไม่มีเอกสารแนบ' : 'รอบนี้ไม่ถูกตัดเงิน ไม่ต้องใช้เอกสาร' }}</p>
        @else
            <ul class="divide-y divide-slate-100" data-test="receipt-list">
                @foreach ($current as $receipt)
                    <li wire:key="receipt-{{ $receipt->id }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 text-sm">
                            <a href="{{ route('receipts.show', $receipt) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:text-indigo-700">{{ $receipt->original_name }}</a>
                            <p class="text-slate-500">
                                {{ $receipt->kind->label() }}
                                @if ($receipt->document_number) · เลขที่ {{ $receipt->document_number }} @endif
                                @if ($receipt->document_date) · ลงวันที่ {{ ThaiFormat::date($receipt->document_date) }} @endif
                                · {{ $receipt->sizeLabel() }}
                            </p>
                            <p class="text-xs text-slate-500">แนบโดย {{ $receipt->uploader?->name ?? '–' }} · {{ ThaiFormat::date($receipt->created_at) }} {{ $receipt->created_at?->format('H:i') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <flux:button size="xs" icon="arrow-down-tray" :href="route('receipts.show', ['receipt' => $receipt, 'download' => 1])">ดาวน์โหลด</flux:button>
                            @if ($canManageReceipts)
                                <flux:button size="xs" variant="ghost" wire:click="openEditReceipt({{ $receipt->id }})" data-test="edit-receipt-{{ $receipt->id }}">แก้ไขข้อมูล</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="openReplace({{ $receipt->id }})" data-test="replace-receipt-{{ $receipt->id }}">เปลี่ยนไฟล์</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="openRemove({{ $receipt->id }})" data-test="remove-receipt-{{ $receipt->id }}">นำออก</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($removed->isNotEmpty())
            <details class="mt-4 text-sm" data-test="removed-receipts">
                <summary class="cursor-pointer text-slate-500">เอกสารที่นำออกแล้ว ({{ $removed->count() }}) · ยังเก็บไว้เป็นหลักฐาน</summary>
                <ul class="mt-2 space-y-2">
                    @foreach ($removed as $receipt)
                        <li wire:key="removed-receipt-{{ $receipt->id }}" class="text-slate-500">
                            <a href="{{ route('receipts.show', $receipt) }}" target="_blank" rel="noopener" class="line-through hover:text-slate-700">{{ $receipt->original_name }}</a>
                            · นำออกโดย {{ $receipt->remover?->name ?? '–' }} {{ $receipt->removed_at ? ThaiFormat::date($receipt->removed_at) : '' }}
                            · เหตุผล: {{ $receipt->remove_reason }}
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-header title="ประวัติการเปลี่ยนแปลง" description="ทุกการบันทึก แก้ไข ตรวจสอบ และเอกสาร พร้อมผู้ทำและเวลา" icon="clock" />
        <ol class="space-y-4" data-test="charge-history">
            @foreach ($this->revisions as $revision)
                <li class="flex flex-col gap-1 text-sm sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <span class="font-medium text-slate-800">{{ $revision->action->label() }}</span>
                        @if ($revision->in_closed_period)
                            <x-ui.status-badge tone="amber" :dot="false" class="ms-1">หลังปิดงวด</x-ui.status-badge>
                        @endif
                        @if ($revision->action === ChargeRevisionAction::Updated)
                            <ul class="mt-1 space-y-0.5 text-slate-600">
                                @foreach ($revision->describeChanges($c->currency->value, $this->historyCards) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @elseif ($revision->action->isReceiptAction())
                            <ul class="mt-1 space-y-0.5 text-slate-600">
                                @foreach ($revision->describeReceipt() as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($revision->reason)
                            <span class="mt-1 block text-slate-600">เหตุผล: {{ $revision->reason }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 text-slate-500">{{ $revision->changer?->name ?? '–' }} · {{ ThaiFormat::date($revision->created_at) }} {{ $revision->created_at?->format('H:i') }}</span>
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    {{-- Attach / replace a document --}}
    @can('uploadReceipt', $c)
        <flux:modal name="receipt-form" class="w-full max-w-md">
            <form wire:submit="saveReceipt" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $replacingId ? 'เปลี่ยนไฟล์เอกสาร' : 'แนบเอกสาร' }}</flux:heading>
                    <flux:text class="mt-1">{{ $replacingId ? 'ไฟล์เดิมจะถูกนำออกแต่ยังเก็บไว้ในประวัติ' : 'ยอดตัดเงินจริงไม่เปลี่ยน' }}</flux:text>
                </div>

                <div>
                    <label for="receipt-upload" class="mb-1 block text-sm font-medium text-slate-800">ไฟล์ (PDF, JPG, PNG ไม่เกิน 2 MB)</label>
                    <input id="receipt-upload" type="file" wire:model="upload" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                           class="block w-full text-sm text-slate-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                    <div wire:loading wire:target="upload" class="mt-1 text-xs text-slate-500">กำลังอัปโหลด…</div>
                    @error('upload')
                        <flux:text class="mt-1 text-xs !text-red-600" data-test="upload-error">{{ $message }}</flux:text>
                    @enderror
                </div>

                <flux:select wire:model="receipt_kind" label="ชนิดเอกสาร">
                    @foreach (ReceiptKind::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="document_number" label="เลขที่เอกสาร (ไม่บังคับ)" maxlength="100" autocomplete="off" />
                <div>
                    <flux:input type="date" wire:model="document_date" label="วันที่ในเอกสาร (ไม่บังคับ)" :max="now()->format('Y-m-d')" />
                    <flux:text class="mt-1 text-xs">ตามที่พิมพ์ในเอกสาร อาจไม่ตรงกับวันที่ตัดบัตร ({{ $c->charged_on ? ThaiFormat::date($c->charged_on) : '–' }})</flux:text>
                </div>
                @if ($replacingId)
                    <flux:input wire:model="receipt_reason" label="เหตุผลที่เปลี่ยนไฟล์" maxlength="500" required autocomplete="off" />
                @endif

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto" wire:loading.attr="disabled" wire:target="upload,saveReceipt" data-test="save-receipt-button">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @if ($canManageReceipts)
        {{-- Edit document details --}}
        <flux:modal name="receipt-details" class="w-full max-w-md">
            <form wire:submit="saveReceiptDetails" class="space-y-5">
                <flux:heading size="lg">แก้ไขข้อมูลเอกสาร</flux:heading>
                <flux:select wire:model="receipt_kind" label="ชนิดเอกสาร">
                    @foreach (ReceiptKind::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="document_number" label="เลขที่เอกสาร (ไม่บังคับ)" maxlength="100" autocomplete="off" />
                <flux:input type="date" wire:model="document_date" label="วันที่ในเอกสาร (ไม่บังคับ)" :max="now()->format('Y-m-d')" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Remove a document (kept) --}}
        <flux:modal name="receipt-remove" class="w-full max-w-md">
            <form wire:submit="confirmRemove" class="space-y-5">
                <div>
                    <flux:heading size="lg">นำเอกสารออก</flux:heading>
                    <flux:text class="mt-1">ไฟล์จะไม่ถูกลบ ยังเปิดดูได้ในส่วน "เอกสารที่นำออกแล้ว" และในประวัติ ยอดตัดเงินจริงไม่เปลี่ยน</flux:text>
                </div>
                <flux:input wire:model="receipt_reason" label="เหตุผล" maxlength="500" required autocomplete="off" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ไม่ใช่ตอนนี้</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" class="w-full sm:w-auto">นำออก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @can('markReceiptUnavailable', $c)
        {{-- No receipt available --}}
        <flux:modal name="receipt-unavailable" class="w-full max-w-md">
            <form wire:submit="confirmUnavailable" class="space-y-5">
                <div>
                    <flux:heading size="lg">ไม่มีใบเสร็จ</flux:heading>
                    <flux:text class="mt-1">ใช้เมื่อผู้ให้บริการไม่ออกเอกสาร หรือหาเอกสารไม่ได้จริง ต่างจาก "รอใบเสร็จ" ซึ่งยังติดตามอยู่ ถ้าได้เอกสารภายหลังก็แนบได้ตามปกติ</flux:text>
                </div>
                <flux:input wire:model="unavailable_reason" label="เหตุผล" placeholder="เช่น ผู้ให้บริการไม่ออกใบเสร็จสำหรับบริการนี้" maxlength="500" required autocomplete="off" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">ยืนยัน</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @can('unverify', $c)
        <flux:modal name="unverify-form" class="w-full max-w-md">
            <form wire:submit="confirmUnverify" class="space-y-5">
                <flux:heading size="lg">ยกเลิกการตรวจสอบ</flux:heading>
                <flux:input wire:model="unverify_reason" label="เหตุผล (ไม่บังคับ)" maxlength="500" autocomplete="off" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ไม่ใช่ตอนนี้</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">ยืนยัน</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
