<?php

use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Models\CreditCard;
use App\Rules\NoFullCardNumber;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Company payment cards. Owner and Accountant can view (CreditCardPolicy);
 * only the Owner can add, edit, deactivate and reactivate. Every action is
 * authorized again here, not only by the route, because Livewire actions
 * can be called directly.
 *
 * Only the last four digits are stored. Free-text fields reject anything
 * that looks like a full card number (NoFullCardNumber). There is
 * deliberately no delete: cards are deactivated instead.
 *
 * Possible duplicates (see CreditCard::possibleDuplicatesOf) only warn: the
 * Owner sees the matching cards and may confirm to save anyway. The
 * confirmation is tied to the exact values that were warned about, so
 * changing them shows the warning again.
 */
new #[Title('บัตรที่ใช้ชำระเงิน')] class extends Component {
    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $issuer = '';
    public string $network = 'visa';
    public string $kind = 'credit';
    public string $last_four = '';
    public string $holder_name = '';
    public string $notes = '';

    /** @var list<array{name: string, detail: string, active: bool}> */
    #[Locked]
    public array $duplicateWarnings = [];

    #[Locked]
    public ?string $warnedFingerprint = null;

    #[Locked]
    public ?string $acknowledgedFingerprint = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CreditCard::class);
    }

    /**
     * Active cards first, then by name.
     *
     * @return Collection<int, CreditCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        $search = trim($this->search);

        return CreditCard::query()
            ->withCount(['subscriptions as open_subscriptions_count' => fn (Builder $query) => $query->where('status', '!=', 'cancelled')])
            ->when($this->statusFilter() === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->statusFilter() === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('issuer', 'like', $like)
                    ->orWhere('holder_name', 'like', $like)
                    ->orWhere('last_four', 'like', $like));
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
        $active = CreditCard::query()->whereNull('deactivated_at')->count();
        $all = CreditCard::query()->count();

        return ['active' => $active, 'inactive' => $all - $active, 'all' => $all];
    }

    public function statusFilter(): string
    {
        return in_array($this->status, ['active', 'inactive', 'all'], true) ? $this->status : 'active';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        unset($this->cards);
    }

    /**
     * Changing what identifies a card hides an earlier duplicate warning.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['issuer', 'network', 'kind', 'last_four'], true)) {
            $this->duplicateWarnings = [];
        }
    }

    public function openCreate(): void
    {
        $this->authorize('create', CreditCard::class);

        $this->resetForm();

        Flux::modal('card-form')->show();
    }

    public function openEdit(int $cardId): void
    {
        $card = CreditCard::findOrFail($cardId);
        $this->authorize('update', $card);

        $this->resetForm();
        $this->editingId = $card->id;
        $this->name = $card->name;
        $this->issuer = $card->issuer;
        $this->network = $card->network->value;
        $this->kind = $card->kind->value;
        $this->last_four = $card->last_four;
        $this->holder_name = $card->holder_name;
        $this->notes = $card->notes ?? '';

        Flux::modal('card-form')->show();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? CreditCard::findOrFail($this->editingId) : null;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', CreditCard::class);

        $this->name = trim($this->name);
        $this->issuer = trim($this->issuer);
        $this->last_four = (string) preg_replace('/\s+/', '', $this->last_four);
        $this->holder_name = trim($this->holder_name);
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', new NoFullCardNumber],
            'issuer' => ['required', 'string', 'max:100', new NoFullCardNumber],
            'network' => ['required', Rule::enum(CardNetwork::class)],
            'kind' => ['required', Rule::enum(CardKind::class)],
            'last_four' => ['required', 'digits:4'],
            'holder_name' => ['required', 'string', 'max:100', new NoFullCardNumber],
            'notes' => ['nullable', 'string', 'max:1000', new NoFullCardNumber],
        ], [
            'last_four.required' => 'กรุณากรอกเลขท้าย 4 หลักของบัตร',
            'last_four.digits' => 'เลขท้ายบัตรต้องเป็นตัวเลข 4 หลักพอดี',
            'network.required' => 'กรุณาเลือกเครือข่ายบัตร',
            'network.enum' => 'เครือข่ายบัตรไม่ถูกต้อง',
            'kind.required' => 'กรุณาเลือกชนิดบัตร',
            'kind.enum' => 'ชนิดบัตรไม่ถูกต้อง',
        ], [
            'name' => 'ชื่อเรียกบัตร',
            'issuer' => 'ธนาคาร/ผู้ออกบัตร',
            'network' => 'เครือข่ายบัตร',
            'kind' => 'ชนิดบัตร',
            'last_four' => 'เลขท้าย 4 หลัก',
            'holder_name' => 'ชื่อเจ้าของบัตร',
            'notes' => 'หมายเหตุ',
        ]);

        $network = CardNetwork::from($validated['network']);
        $kind = CardKind::from($validated['kind']);

        $duplicates = CreditCard::possibleDuplicatesOf(
            $validated['last_four'], $network, $kind, $validated['issuer'], $editing?->id,
        );
        $fingerprint = $this->fingerprint($validated['last_four'], $network, $kind, $validated['issuer']);

        if ($duplicates->isNotEmpty() && $this->acknowledgedFingerprint !== $fingerprint) {
            $this->warnedFingerprint = $fingerprint;
            $this->duplicateWarnings = $duplicates->map(fn (CreditCard $card) => [
                'name' => $card->name,
                'detail' => "{$card->issuer} · {$card->kind->label()} · {$card->network->label()} · {$card->maskedNumber()}",
                'active' => $card->isActive(),
            ])->all();

            return;
        }

        $card = $editing ?? new CreditCard;

        $card->fill([
            'name' => $validated['name'],
            'issuer' => $validated['issuer'],
            'network' => $network,
            'kind' => $kind,
            'last_four' => $validated['last_four'],
            'holder_name' => $validated['holder_name'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ]);

        if ($editing === null) {
            $card->forceFill(['created_by' => auth()->id()]);
        }

        $card->forceFill(['updated_by' => auth()->id()]);

        // A new card also gets its card payment method, in one transaction.
        $editing ? $card->save() : $card->saveWithPaymentMethod();

        $this->resetForm();
        unset($this->cards, $this->counts);

        Flux::modal('card-form')->close();
        Flux::toast(variant: 'success', text: $editing ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มบัตรเรียบร้อยแล้ว');
    }

    /**
     * The Owner has seen the possible duplicates and still wants to save.
     */
    public function saveAnyway(): void
    {
        $this->acknowledgedFingerprint = $this->warnedFingerprint;

        $this->save();
    }

    public function deactivate(int $cardId): void
    {
        $card = CreditCard::findOrFail($cardId);
        $this->authorize('deactivate', $card);

        $card->forceFill([
            'deactivated_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->cards, $this->counts);

        Flux::toast(variant: 'success', text: "ปิดใช้งานบัตร {$card->name} แล้ว");
    }

    public function reactivate(int $cardId): void
    {
        $card = CreditCard::findOrFail($cardId);
        $this->authorize('reactivate', $card);

        $card->forceFill([
            'deactivated_at' => null,
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->cards, $this->counts);

        Flux::toast(variant: 'success', text: "เปิดใช้งานบัตร {$card->name} อีกครั้งแล้ว");
    }

    private function fingerprint(string $lastFour, CardNetwork $network, CardKind $kind, string $issuer): string
    {
        return implode('|', [$this->editingId ?? 'new', $lastFour, $network->value, $kind->value, CreditCard::normalizeIssuer($issuer)]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'issuer', 'network', 'kind', 'last_four', 'holder_name', 'notes',
            'duplicateWarnings', 'warnedFingerprint', 'acknowledgedFingerprint',
        ]);
        $this->resetValidation();
    }
}; ?>

@php
    $canCreate = auth()->user()->can('create', CreditCard::class);
    $filters = ['active' => 'ใช้งานอยู่', 'inactive' => 'ปิดใช้งาน', 'all' => 'ทั้งหมด'];
    $currentFilter = $this->statusFilter();
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    {{-- Page header --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">บัตรที่ใช้ชำระเงิน</h1>
            <p class="mt-1 text-sm text-slate-500">บัตรที่บริษัทใช้จ่ายค่าบริการและ Subscription สำหรับเชื่อมกับการแจ้งเตือนรายการตัดบัตร</p>
        </div>

        @if ($canCreate)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="add-card-button" class="w-full sm:w-auto">
                เพิ่มบัตร
            </flux:button>
        @endif
    </header>

    <flux:callout icon="shield-check" color="sky">
        <flux:callout.text>
            ระบบเก็บเฉพาะ<strong>เลขท้าย 4 หลัก</strong>ของบัตร ไม่เก็บเลขบัตรเต็ม รหัส CVV วันหมดอายุ หรือ PIN
            @unless ($canCreate)
                <span class="block mt-1" data-test="read-only-notice">คุณดูรายการได้อย่างเดียว การเพิ่มหรือแก้ไขบัตรทำได้โดยเจ้าของบริษัท</span>
            @endunless
        </flux:callout.text>
    </flux:callout>

    {{-- Filters --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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

        <div class="w-full sm:w-72">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาชื่อ ธนาคาร หรือเลขท้าย" aria-label="ค้นหาบัตร" clearable />
        </div>
    </div>

    {{-- Card list (stacked rows: one layout for phone and desktop) --}}
    @if ($this->counts['all'] === 0)
        <x-ui.empty-state icon="credit-card" title="ยังไม่มีบัตรในระบบ" description="เพิ่มบัตรที่บริษัทใช้จ่ายค่าบริการ เพื่อใช้เชื่อมกับ Subscription และการแจ้งเตือนรายการตัดบัตร">
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" wire:click="openCreate">เพิ่มบัตรใบแรก</flux:button>
            @endif
        </x-ui.empty-state>
    @elseif ($this->cards->isEmpty())
        <x-ui.empty-state icon="magnifying-glass" title="ไม่พบบัตรที่ตรงกับเงื่อนไข" description="ลองเปลี่ยนคำค้นหาหรือตัวกรองสถานะ" compact />
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="card-list">
                @foreach ($this->cards as $card)
                    <li wire:key="card-{{ $card->id }}" @class([
                        'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                        'bg-slate-50/70' => ! $card->isActive(),
                    ])>
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class([
                                'flex size-10 shrink-0 items-center justify-center rounded-xl ring-1',
                                'bg-indigo-50 text-indigo-600 ring-indigo-100' => $card->isActive(),
                                'bg-slate-100 text-slate-400 ring-slate-200' => ! $card->isActive(),
                            ])>
                                <flux:icon.credit-card class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p @class(['truncate font-medium', 'text-slate-900' => $card->isActive(), 'text-slate-500 line-through' => ! $card->isActive()])>
                                    {{ $card->name }}
                                </p>
                                <p class="truncate text-sm text-slate-500">
                                    {{ $card->issuer }} · {{ $card->kind->label() }} · {{ $card->network->label() }}
                                </p>
                                <p class="mt-0.5 text-sm text-slate-600">
                                    <span class="font-mono tracking-wider">{{ $card->maskedNumber() }}</span>
                                    <span class="text-slate-400">·</span>
                                    เจ้าของบัตร {{ $card->holder_name }}
                                </p>
                                @if ($card->notes)
                                    <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $card->notes }}</p>
                                @endif
                                <p @class(['mt-1 text-xs', 'text-slate-500' => $card->isActive() || $card->open_subscriptions_count === 0, 'font-medium text-red-600' => ! $card->isActive() && $card->open_subscriptions_count > 0]) data-test="card-subscription-count">
                                    @if ($card->open_subscriptions_count > 0)
                                        ใช้กับ Subscription {{ $card->open_subscriptions_count }} รายการ{{ $card->isActive() ? '' : ' — ควรเปลี่ยนบัตร' }}
                                    @else
                                        ยังไม่มี Subscription ที่ใช้บัตรนี้
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            @if ($card->isActive())
                                <x-ui.status-badge tone="emerald">ใช้งานอยู่</x-ui.status-badge>
                            @else
                                <x-ui.status-badge tone="slate">ปิดใช้งาน</x-ui.status-badge>
                            @endif

                            @can('update', $card)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="'จัดการ '.$card->name" />

                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="openEdit({{ $card->id }})">แก้ไขข้อมูล</flux:menu.item>

                                        @can('deactivate', $card)
                                            <flux:menu.separator />
                                            <flux:menu.item
                                                icon="no-symbol"
                                                variant="danger"
                                                wire:click="deactivate({{ $card->id }})"
                                                wire:confirm="ปิดใช้งานบัตร {{ $card->name }}?{{ $card->open_subscriptions_count > 0 ? ' ⚠️ ยังมี Subscription '.$card->open_subscriptions_count.' รายการใช้บัตรนี้อยู่ ต้องเปลี่ยนบัตรของรายการเหล่านั้นด้วย มิฉะนั้นการตัดเงินอาจไม่ผ่าน' : '' }} บัตรจะเลือกใช้กับรายการใหม่ไม่ได้ แต่ข้อมูลและประวัติเดิมยังอยู่ครบ"
                                            >ปิดใช้งาน</flux:menu.item>
                                        @endcan

                                        @can('reactivate', $card)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $card->id }})">เปิดใช้งานอีกครั้ง</flux:menu.item>
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
        <flux:modal name="card-form" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'แก้ไขข้อมูลบัตร' : 'เพิ่มบัตรใหม่' }}</flux:heading>
                    <flux:text class="mt-1">ห้ามกรอกเลขบัตรเต็ม รหัส CVV หรือวันหมดอายุในช่องใด ๆ</flux:text>
                </div>

                <flux:input wire:model="name" label="ชื่อเรียกบัตร" placeholder="เช่น บัตรฝ่ายการตลาด" required autocomplete="off" maxlength="100" />
                <flux:input wire:model="issuer" label="ธนาคาร/ผู้ออกบัตร" placeholder="เช่น ธนาคารกสิกรไทย" required autocomplete="off" maxlength="100" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="kind" label="ชนิดบัตร">
                        @foreach (CardKind::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="network" label="เครือข่ายบัตร">
                        @foreach (CardNetwork::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="last_four"
                        label="เลขท้าย 4 หลัก"
                        description="เฉพาะ 4 หลักสุดท้ายเท่านั้น"
                        required
                        inputmode="numeric"
                        maxlength="4"
                        pattern="[0-9]{4}"
                        autocomplete="off"
                        class="font-mono"
                    />
                    <flux:input wire:model="holder_name" label="ชื่อเจ้าของบัตร" description="ตามที่ระบุบนบัตร" required autocomplete="off" maxlength="100" />
                </div>

                <flux:textarea wire:model="notes" label="หมายเหตุ" rows="3" maxlength="1000" placeholder="เช่น ใช้จ่ายค่าโฆษณาออนไลน์ วงเงินแยกจากบัตรหลัก" />

                @if ($duplicateWarnings)
                    <flux:callout icon="exclamation-triangle" color="amber" data-test="duplicate-warning">
                        <flux:callout.heading>อาจเป็นบัตรที่มีอยู่แล้ว</flux:callout.heading>
                        <flux:callout.text>
                            พบบัตรที่เลขท้าย เครือข่าย และธนาคารหรือชนิดบัตรตรงกัน:
                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                @foreach ($duplicateWarnings as $warning)
                                    <li>
                                        <strong>{{ $warning['name'] }}</strong>
                                        <span class="block text-xs">{{ $warning['detail'] }}{{ $warning['active'] ? '' : ' · ปิดใช้งานอยู่' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <span class="mt-2 block">ถ้าเป็นบัตรเดียวกัน แนะนำให้แก้ไขหรือเปิดใช้งานรายการเดิมแทน</span>
                        </flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" variant="primary" wire:click="saveAnyway" data-test="save-anyway-button">เป็นบัตรคนละใบ บันทึกต่อ</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-card-button">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
