<?php

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Enums\SubscriptionStatus;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Rules\NoFullAccountNumber;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The ways the company pays: a card, a bank account (transfer / PromptPay /
 * QR or automatic debit) or cash. Owner and Accountant can view
 * (PaymentMethodPolicy); only the Owner can add, edit, deactivate and
 * reactivate. Every action is authorized again here, not only by the route,
 * because Livewire actions can be called directly.
 *
 * Rules:
 * - A card method must point at an existing, active card that has no method
 *   yet (one method per card, also enforced by a unique index). Cards are
 *   never created here.
 * - A transfer or direct-debit method must point at an existing, active bank
 *   account, and an account has at most one method of each type.
 * - Cash points at neither. Names are unique.
 * - Type, card and bank account cannot be changed after creation, because
 *   subscriptions and charges may already point at the method (their card
 *   must keep matching). Only the name and how payment is confirmed change.
 * - There is no delete. Deactivating works like for cards: it is allowed
 *   after a confirmation that shows what still uses the method.
 */
new #[Title('ช่องทางชำระเงิน')] class extends Component {
    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: 'all')]
    public string $typeFilter = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $type = 'bank_transfer';
    public string $settlement = 'manual';
    public string $credit_card_id = '';
    public string $bank_account_id = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PaymentMethod::class);
    }

    /**
     * Active methods first, then by name.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        $search = trim($this->search);
        $type = PaymentMethodType::tryFrom($this->typeFilter);

        return PaymentMethod::query()
            ->with(['creditCard', 'bankAccount', 'subscriptions' => fn ($query) => $query->select(['id', 'payment_method_id', 'name'])->where('status', '!=', SubscriptionStatus::Cancelled->value)->orderBy('name')])
            ->withCount([
                'subscriptions as open_subscriptions_count' => fn (Builder $query) => $query->where('status', '!=', SubscriptionStatus::Cancelled->value),
                'charges as charges_count',
            ])
            ->when($this->statusFilter() === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->statusFilter() === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type->value))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhereHas('creditCard', fn (Builder $card) => $card->where('name', 'like', $like)->orWhere('issuer', 'like', $like)->orWhere('last_four', 'like', $like))
                    ->orWhereHas('bankAccount', fn (Builder $account) => $account->where('name', 'like', $like)->orWhere('bank', 'like', $like)->orWhere('last_four', 'like', $like)));
            })
            ->orderByRaw('deactivated_at IS NOT NULL')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{active: int, inactive: int, all: int}
     */
    #[Computed]
    public function counts(): array
    {
        $active = PaymentMethod::query()->whereNull('deactivated_at')->count();
        $all = PaymentMethod::query()->count();

        return ['active' => $active, 'inactive' => $all - $active, 'all' => $all];
    }

    /**
     * Active cards that do not have a payment method yet.
     *
     * @return Collection<int, CreditCard>
     */
    #[Computed]
    public function availableCards(): Collection
    {
        return CreditCard::query()
            ->whereNull('deactivated_at')
            ->whereDoesntHave('paymentMethod')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function activeAccounts(): Collection
    {
        return BankAccount::query()->whereNull('deactivated_at')->orderBy('name')->get();
    }

    public function statusFilter(): string
    {
        return in_array($this->status, ['active', 'inactive', 'all'], true) ? $this->status : 'active';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        unset($this->methods);
    }

    /**
     * Changing the type suggests the usual way of confirming payment for it
     * and drops a link that does not belong to the new type.
     */
    public function updatedType(string $value): void
    {
        $type = PaymentMethodType::tryFrom($value);

        if ($type === null || $this->editingId !== null) {
            return;
        }

        $this->settlement = $type->defaultSettlement()->value;
        $this->credit_card_id = '';
        $this->bank_account_id = '';
    }

    public function openCreate(): void
    {
        $this->authorize('create', PaymentMethod::class);

        $this->resetForm();

        Flux::modal('method-form')->show();
    }

    public function openEdit(int $methodId): void
    {
        $method = PaymentMethod::findOrFail($methodId);
        $this->authorize('update', $method);

        $this->resetForm();
        $this->editingId = $method->id;
        $this->name = $method->name;
        $this->type = $method->type->value;
        $this->settlement = $method->settlement->value;
        $this->credit_card_id = (string) ($method->credit_card_id ?? '');
        $this->bank_account_id = (string) ($method->bank_account_id ?? '');

        Flux::modal('method-form')->show();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? PaymentMethod::findOrFail($this->editingId) : null;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', PaymentMethod::class);

        $this->name = trim($this->name);

        $rules = [
            'name' => [
                'required', 'string', 'max:100', new NoFullAccountNumber,
                function (string $attribute, mixed $value, Closure $fail) use ($editing) {
                    $taken = PaymentMethod::query()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($editing, fn (Builder $query) => $query->whereKeyNot($editing->id))
                        ->exists();

                    if ($taken) {
                        $fail('มีช่องทางชำระเงินชื่อนี้อยู่แล้ว กรุณาใช้ชื่ออื่น');
                    }
                },
            ],
            'settlement' => ['required', Rule::enum(SettlementMode::class)],
        ];
        $messages = [
            'settlement.required' => 'กรุณาเลือกวิธียืนยันการชำระเงิน',
            'settlement.enum' => 'วิธียืนยันการชำระเงินไม่ถูกต้อง',
            'type.required' => 'กรุณาเลือกประเภทช่องทางชำระเงิน',
            'type.enum' => 'ประเภทช่องทางชำระเงินไม่ถูกต้อง',
            'credit_card_id.required' => 'กรุณาเลือกบัตร',
            'credit_card_id.exists' => 'บัตรที่เลือกไม่ถูกต้องหรือถูกปิดใช้งานแล้ว',
            'credit_card_id.unique' => 'บัตรนี้มีช่องทางชำระเงินอยู่แล้ว ใช้ช่องทางเดิม (หรือเปิดใช้งานอีกครั้ง) แทนการสร้างซ้ำ',
            'bank_account_id.required' => 'กรุณาเลือกบัญชีธนาคาร',
            'bank_account_id.exists' => 'บัญชีที่เลือกไม่ถูกต้องหรือถูกปิดใช้งานแล้ว',
            'bank_account_id.unique' => 'บัญชีนี้มีช่องทางชำระเงินประเภทนี้อยู่แล้ว ใช้ช่องทางเดิม (หรือเปิดใช้งานอีกครั้ง) แทนการสร้างซ้ำ',
        ];
        $attributes = [
            'name' => 'ชื่อช่องทางชำระเงิน',
            'type' => 'ประเภท',
            'settlement' => 'วิธียืนยันการชำระเงิน',
            'credit_card_id' => 'บัตร',
            'bank_account_id' => 'บัญชีธนาคาร',
        ];

        if ($editing !== null) {
            // The type and the card / account behind a method never change.
            $validated = $this->validate($rules, $messages, $attributes);

            $editing->fill(['name' => $validated['name'], 'settlement' => SettlementMode::from($validated['settlement'])]);
            $editing->forceFill(['updated_by' => auth()->id()])->save();
        } else {
            $rules['type'] = ['required', Rule::enum(PaymentMethodType::class)];
            $type = PaymentMethodType::tryFrom($this->type);

            if ($type?->requiresCard()) {
                $rules['credit_card_id'] = [
                    'required', 'integer',
                    Rule::exists('credit_cards', 'id')->whereNull('deactivated_at'),
                    Rule::unique('payment_methods', 'credit_card_id'),
                ];
            }

            if ($type?->requiresBankAccount()) {
                $rules['bank_account_id'] = [
                    'required', 'integer',
                    Rule::exists('bank_accounts', 'id')->whereNull('deactivated_at'),
                    Rule::unique('payment_methods', 'bank_account_id')->where('type', $type->value),
                ];
            }

            $validated = $this->validate($rules, $messages, $attributes);
            $type = PaymentMethodType::from($validated['type']);

            $method = new PaymentMethod;
            $method->fill([
                'name' => $validated['name'],
                'type' => $type,
                'settlement' => SettlementMode::from($validated['settlement']),
                'credit_card_id' => $type->requiresCard() ? (int) $validated['credit_card_id'] : null,
                'bank_account_id' => $type->requiresBankAccount() ? (int) $validated['bank_account_id'] : null,
            ]);
            $method->forceFill(['created_by' => auth()->id(), 'updated_by' => auth()->id()]);

            try {
                $method->save();
            } catch (UniqueConstraintViolationException) {
                $this->addError($type->requiresCard() ? 'credit_card_id' : 'type', 'ช่องทางชำระเงินนี้ถูกสร้างไปแล้ว กรุณาโหลดหน้าใหม่แล้วตรวจอีกครั้ง');

                return;
            }
        }

        $this->resetForm();
        unset($this->methods, $this->counts, $this->availableCards);

        Flux::modal('method-form')->close();
        Flux::toast(variant: 'success', text: $editing ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มช่องทางชำระเงินเรียบร้อยแล้ว');
    }

    public function deactivate(int $methodId): void
    {
        $method = PaymentMethod::findOrFail($methodId);
        $this->authorize('deactivate', $method);

        $method->forceFill([
            'deactivated_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->methods, $this->counts);

        Flux::toast(variant: 'success', text: "ปิดใช้งานช่องทาง {$method->name} แล้ว");
    }

    public function reactivate(int $methodId): void
    {
        $method = PaymentMethod::findOrFail($methodId);
        $this->authorize('reactivate', $method);

        // A method cannot be on while the card or account behind it is off.
        if ($method->creditCard !== null && ! $method->creditCard->isActive()) {
            Flux::toast(variant: 'danger', text: 'เปิดใช้งานไม่ได้ เพราะบัตรของช่องทางนี้ถูกปิดใช้งานอยู่ กรุณาเปิดใช้งานบัตรก่อน');

            return;
        }

        if ($method->bankAccount !== null && ! $method->bankAccount->isActive()) {
            Flux::toast(variant: 'danger', text: 'เปิดใช้งานไม่ได้ เพราะบัญชีธนาคารของช่องทางนี้ถูกปิดใช้งานอยู่ กรุณาเปิดใช้งานบัญชีก่อน');

            return;
        }

        $method->forceFill([
            'deactivated_at' => null,
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->methods, $this->counts);

        Flux::toast(variant: 'success', text: "เปิดใช้งานช่องทาง {$method->name} อีกครั้งแล้ว");
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'type', 'settlement', 'credit_card_id', 'bank_account_id']);
        $this->resetValidation();
    }
}; ?>

@php
    $canCreate = auth()->user()->can('create', PaymentMethod::class);
    $filters = ['active' => 'ใช้งานอยู่', 'inactive' => 'ปิดใช้งาน', 'all' => 'ทั้งหมด'];
    $currentFilter = $this->statusFilter();
    $selectedType = PaymentMethodType::tryFrom($this->type);
    $typeIcons = ['card' => 'credit-card', 'bank_transfer' => 'arrows-right-left', 'direct_debit' => 'building-library', 'cash' => 'banknotes'];
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    {{-- Page header --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">ช่องทางชำระเงิน</h1>
            <p class="mt-1 text-sm text-slate-500">วิธีที่บริษัทใช้จ่ายค่าบริการ ได้แก่ บัตร โอนเงิน/PromptPay/QR หักบัญชีอัตโนมัติ และเงินสด</p>
        </div>

        @if ($canCreate)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="add-method-button" class="w-full sm:w-auto">
                เพิ่มช่องทาง
            </flux:button>
        @endif
    </header>

    <flux:callout icon="information-circle" color="sky">
        <flux:callout.text>
            ช่องทางแบบบัตรถูกสร้างให้อัตโนมัติเมื่อเพิ่มบัตร ส่วนโอนเงินและหักบัญชีต้องเลือก<strong>บัญชีธนาคาร</strong>ที่มีอยู่แล้ว ระบบไม่เก็บเลขบัญชีหรือเลขบัตรเต็ม
            @unless ($canCreate)
                <span class="block mt-1" data-test="read-only-notice">คุณดูรายการได้อย่างเดียว การเพิ่มหรือแก้ไขช่องทางทำได้โดยเจ้าของบริษัท</span>
            @endunless
        </flux:callout.text>
    </flux:callout>

    {{-- Filters --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="inline-flex w-full rounded-lg bg-slate-100 p-1 sm:w-auto" role="tablist" aria-label="กรองตามสถานะ">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $currentFilter === $value ? 'true' : 'false' }}"
                    wire:click="setStatus('{{ $value }}')"
                    @class([
                        'flex-1 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition sm:flex-none',
                        'bg-white text-slate-900 shadow-sm' => $currentFilter === $value,
                        'text-slate-600 hover:text-slate-900' => $currentFilter !== $value,
                    ])
                >
                    {{ $label }} <span class="text-xs text-slate-500">({{ $this->counts[$value] }})</span>
                </button>
            @endforeach
        </div>

        <div class="flex w-full flex-col gap-3 sm:flex-row lg:w-auto">
            <div class="sm:w-56">
                <flux:select wire:model.live="typeFilter" aria-label="กรองตามประเภท" data-test="type-filter">
                    <flux:select.option value="all">ทุกประเภท</flux:select.option>
                    @foreach (PaymentMethodType::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-72">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาชื่อ บัตร หรือบัญชี" aria-label="ค้นหาช่องทางชำระเงิน" clearable />
            </div>
        </div>
    </div>

    {{-- Method list (stacked rows: one layout for phone and desktop) --}}
    @if ($this->counts['all'] === 0)
        <x-ui.empty-state icon="wallet" title="ยังไม่มีช่องทางชำระเงินในระบบ" description="เพิ่มช่องทางที่บริษัทใช้จ่าย เพื่อใช้เลือกเป็นวิธีชำระเงินของรายการค่าใช้จ่าย">
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" wire:click="openCreate">เพิ่มช่องทางแรก</flux:button>
            @endif
        </x-ui.empty-state>
    @elseif ($this->methods->isEmpty())
        <x-ui.empty-state icon="magnifying-glass" title="ไม่พบช่องทางที่ตรงกับเงื่อนไข" description="ลองเปลี่ยนคำค้นหา ประเภท หรือตัวกรองสถานะ" compact />
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="method-list">
                @foreach ($this->methods as $method)
                    <li wire:key="method-{{ $method->id }}" @class([
                        'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                        'bg-slate-50/70' => ! $method->isActive(),
                    ])>
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class([
                                'flex size-10 shrink-0 items-center justify-center rounded-xl ring-1',
                                'bg-indigo-50 text-indigo-600 ring-indigo-100' => $method->isActive(),
                                'bg-slate-100 text-slate-400 ring-slate-200' => ! $method->isActive(),
                            ])>
                                <flux:icon :name="$typeIcons[$method->type->value]" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p @class(['truncate font-medium', 'text-slate-900' => $method->isActive(), 'text-slate-500 line-through' => ! $method->isActive()])>
                                    {{ $method->name }}
                                </p>
                                <p class="truncate text-sm text-slate-500">{{ $method->type->label() }}</p>
                                <p class="mt-0.5 text-sm text-slate-600" data-test="method-target">
                                    @if ($method->creditCard)
                                        บัตร {{ $method->creditCard->name }}
                                        <span class="font-mono tracking-wider">{{ $method->creditCard->maskedNumber() }}</span>
                                        @unless ($method->creditCard->isActive())
                                            <span class="text-red-600">· บัตรปิดใช้งานอยู่</span>
                                        @endunless
                                    @elseif ($method->bankAccount)
                                        บัญชี {{ $method->bankAccount->name }} · {{ $method->bankAccount->bank }}
                                        <span class="font-mono tracking-wider">{{ $method->bankAccount->maskedNumber() }}</span>
                                        @unless ($method->bankAccount->isActive())
                                            <span class="text-red-600">· บัญชีปิดใช้งานอยู่</span>
                                        @endunless
                                    @else
                                        ไม่ผูกกับบัตรหรือบัญชีธนาคาร
                                    @endif
                                </p>
                                <p @class(['mt-1 text-xs', 'text-slate-500' => $method->isActive() || $method->open_subscriptions_count === 0, 'font-medium text-red-600' => ! $method->isActive() && $method->open_subscriptions_count > 0]) data-test="method-usage">
                                    @if ($method->open_subscriptions_count > 0 || $method->charges_count > 0)
                                        ใช้กับ Subscription {{ $method->open_subscriptions_count }} รายการ · รายการตัดเงิน {{ $method->charges_count }} รายการ{{ ! $method->isActive() && $method->open_subscriptions_count > 0 ? ' — ควรเปลี่ยนช่องทาง' : '' }}
                                    @else
                                        ยังไม่มีรายการที่ใช้ช่องทางนี้
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            @if ($method->isAuto())
                                <x-ui.status-badge tone="cyan" :dot="false">ตัดเงินอัตโนมัติ</x-ui.status-badge>
                            @else
                                <x-ui.status-badge tone="amber" :dot="false">ต้องยืนยันการจ่าย</x-ui.status-badge>
                            @endif

                            @if ($method->isActive())
                                <x-ui.status-badge tone="emerald">ใช้งานอยู่</x-ui.status-badge>
                            @else
                                <x-ui.status-badge tone="slate">ปิดใช้งาน</x-ui.status-badge>
                            @endif

                            @can('update', $method)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="'จัดการ '.$method->name" />

                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="openEdit({{ $method->id }})">แก้ไขข้อมูล</flux:menu.item>

                                        @can('deactivate', $method)
                                            <flux:menu.separator />
                                            <flux:menu.item
                                                icon="no-symbol"
                                                variant="danger"
                                                wire:click="deactivate({{ $method->id }})"
                                                wire:confirm="ปิดใช้งานช่องทาง {{ $method->name }}?{{ $method->open_subscriptions_count > 0 ? ' ⚠️ ยังมี Subscription '.$method->open_subscriptions_count.' รายการใช้ช่องทางนี้อยู่ ('.$method->subscriptions->take(5)->pluck('name')->implode(', ').($method->subscriptions->count() > 5 ? ' และอื่น ๆ' : '').') ระบบจะไม่เปลี่ยนช่องทางให้เอง ผู้รับผิดชอบต้องแก้ไขรายการเหล่านั้นภายหลัง' : '' }} ช่องทางจะเลือกใช้กับรายการใหม่ไม่ได้ แต่ข้อมูลและประวัติเดิม({{ $method->charges_count }} รายการตัดเงิน) ยังอยู่ครบ"
                                            >ปิดใช้งาน</flux:menu.item>
                                        @endcan

                                        @can('reactivate', $method)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $method->id }})">เปิดใช้งานอีกครั้ง</flux:menu.item>
                                        @endcan
                                    </flux:menu>
                                </flux:dropdown>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Create / edit (Owner only) --}}
    @if ($canCreate)
        <flux:modal name="method-form" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'แก้ไขช่องทางชำระเงิน' : 'เพิ่มช่องทางชำระเงิน' }}</flux:heading>
                    <flux:text class="mt-1">
                        @if ($editingId)
                            ประเภท บัตร และบัญชีเปลี่ยนไม่ได้ เพราะอาจมีรายการค่าใช้จ่ายอ้างอิงอยู่ แก้ได้เฉพาะชื่อและวิธียืนยันการชำระเงิน
                        @else
                            ห้ามกรอกเลขบัญชี เลขบัตร หรือเลขพร้อมเพย์เต็มในช่องใด ๆ
                        @endif
                    </flux:text>
                </div>

                <flux:select wire:model.live="type" label="ประเภท" :disabled="(bool) $editingId" data-test="type-select">
                    @foreach (PaymentMethodType::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($selectedType?->requiresCard())
                    @if ($editingId)
                        <flux:select wire:model="credit_card_id" label="บัตร" disabled>
                            @foreach (CreditCard::query()->whereKey((int) $credit_card_id)->get() as $card)
                                <flux:select.option value="{{ $card->id }}">{{ $card->name }} {{ $card->maskedNumber() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif ($this->availableCards->isEmpty())
                        <flux:callout icon="information-circle" color="amber" data-test="no-available-cards">
                            <flux:callout.text>ไม่มีบัตรที่ยังไม่มีช่องทางชำระเงิน บัตรทุกใบได้ช่องทางอัตโนมัติตอนเพิ่มบัตร หากต้องการเพิ่มบัตรใหม่ ให้ไปที่หน้า "บัตรที่ใช้ชำระเงิน"</flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:select wire:model="credit_card_id" label="บัตร" placeholder="เลือกบัตร" description="แสดงเฉพาะบัตรที่ใช้งานอยู่และยังไม่มีช่องทาง">
                            @foreach ($this->availableCards as $card)
                                <flux:select.option value="{{ $card->id }}">{{ $card->name }} {{ $card->maskedNumber() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                @endif

                @if ($selectedType?->requiresBankAccount())
                    @if ($editingId)
                        <flux:select wire:model="bank_account_id" label="บัญชีธนาคาร" disabled>
                            @foreach (BankAccount::query()->whereKey((int) $bank_account_id)->get() as $account)
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }} {{ $account->maskedNumber() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif ($this->activeAccounts->isEmpty())
                        <flux:callout icon="information-circle" color="amber" data-test="no-active-accounts">
                            <flux:callout.text>ยังไม่มีบัญชีธนาคารที่ใช้งานอยู่ กรุณาเพิ่มบัญชีที่หน้า "บัญชีธนาคาร" ก่อน</flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:select wire:model="bank_account_id" label="บัญชีธนาคาร" placeholder="เลือกบัญชี">
                            @foreach ($this->activeAccounts as $account)
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }} · {{ $account->bank }} {{ $account->maskedNumber() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                @endif

                <flux:input wire:model="name" label="ชื่อช่องทางชำระเงิน" placeholder="เช่น โอนค่าเช่า (บัญชีหลัก)" required autocomplete="off" maxlength="100" />

                <flux:select wire:model="settlement" label="วิธียืนยันการชำระเงิน" data-test="settlement-select">
                    @foreach (SettlementMode::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-method-button">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
