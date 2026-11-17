<?php

use App\Enums\BankAccountKind;
use App\Models\BankAccount;
use App\Rules\NoFullAccountNumber;
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
 * Company bank accounts used to pay (transfer / PromptPay / direct debit).
 * Owner and Accountant can view (BankAccountPolicy); only the Owner can add,
 * edit, deactivate and reactivate. Every action is authorized again here, not
 * only by the route, because Livewire actions can be called directly.
 *
 * Only the last four digits of the account are stored. Free-text fields
 * reject long digit runs (NoFullAccountNumber). There is deliberately no
 * delete: accounts are deactivated, and the database also refuses to delete
 * an account that a payment method points at.
 */
new #[Title('บัญชีธนาคาร')] class extends Component {
    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $bank = '';
    public string $account_name = '';
    public string $kind = 'current';
    public string $last_four = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('viewAny', BankAccount::class);
    }

    /**
     * Active accounts first, then by name.
     *
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $search = trim($this->search);

        return BankAccount::query()
            ->withCount(['paymentMethods as active_methods_count' => fn (Builder $query) => $query->whereNull('deactivated_at')])
            ->when($this->statusFilter() === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($this->statusFilter() === 'inactive', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('bank', 'like', $like)
                    ->orWhere('account_name', 'like', $like)
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
        $active = BankAccount::query()->whereNull('deactivated_at')->count();
        $all = BankAccount::query()->count();

        return ['active' => $active, 'inactive' => $all - $active, 'all' => $all];
    }

    public function statusFilter(): string
    {
        return in_array($this->status, ['active', 'inactive', 'all'], true) ? $this->status : 'active';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        unset($this->accounts);
    }

    public function openCreate(): void
    {
        $this->authorize('create', BankAccount::class);

        $this->resetForm();

        Flux::modal('account-form')->show();
    }

    public function openEdit(int $accountId): void
    {
        $account = BankAccount::findOrFail($accountId);
        $this->authorize('update', $account);

        $this->resetForm();
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->bank = $account->bank;
        $this->account_name = $account->account_name;
        $this->kind = $account->kind->value;
        $this->last_four = $account->last_four;
        $this->notes = $account->notes ?? '';

        Flux::modal('account-form')->show();
    }

    public function save(): void
    {
        $editing = $this->editingId !== null ? BankAccount::findOrFail($this->editingId) : null;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', BankAccount::class);

        $this->name = trim($this->name);
        $this->bank = trim($this->bank);
        $this->account_name = trim($this->account_name);
        $this->last_four = (string) preg_replace('/\s+/', '', $this->last_four);
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', new NoFullAccountNumber],
            'bank' => ['required', 'string', 'max:100', new NoFullAccountNumber],
            'account_name' => ['required', 'string', 'max:150', new NoFullAccountNumber],
            'kind' => ['required', Rule::enum(BankAccountKind::class)],
            'last_four' => ['required', 'digits:4'],
            'notes' => ['nullable', 'string', 'max:1000', new NoFullAccountNumber],
        ], [
            'last_four.required' => 'กรุณากรอกเลขท้าย 4 หลักของบัญชี',
            'last_four.digits' => 'เลขท้ายบัญชีต้องเป็นตัวเลข 4 หลักพอดี',
            'kind.required' => 'กรุณาเลือกประเภทบัญชี',
            'kind.enum' => 'ประเภทบัญชีไม่ถูกต้อง',
        ], [
            'name' => 'ชื่อเรียกบัญชี',
            'bank' => 'ธนาคาร',
            'account_name' => 'ชื่อเจ้าของบัญชี',
            'kind' => 'ประเภทบัญชี',
            'last_four' => 'เลขท้าย 4 หลัก',
            'notes' => 'หมายเหตุ',
        ]);

        $account = $editing ?? new BankAccount;

        $account->fill([
            'name' => $validated['name'],
            'bank' => $validated['bank'],
            'account_name' => $validated['account_name'],
            'kind' => BankAccountKind::from($validated['kind']),
            'last_four' => $validated['last_four'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ]);

        if ($editing === null) {
            $account->forceFill(['created_by' => auth()->id()]);
        }

        $account->forceFill(['updated_by' => auth()->id()])->save();

        $this->resetForm();
        unset($this->accounts, $this->counts);

        Flux::modal('account-form')->close();
        Flux::toast(variant: 'success', text: $editing ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มบัญชีเรียบร้อยแล้ว');
    }

    public function deactivate(int $accountId): void
    {
        $account = BankAccount::findOrFail($accountId);
        $this->authorize('deactivate', $account);

        $account->forceFill([
            'deactivated_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->accounts, $this->counts);

        Flux::toast(variant: 'success', text: "ปิดใช้งานบัญชี {$account->name} แล้ว");
    }

    public function reactivate(int $accountId): void
    {
        $account = BankAccount::findOrFail($accountId);
        $this->authorize('reactivate', $account);

        $account->forceFill([
            'deactivated_at' => null,
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->accounts, $this->counts);

        Flux::toast(variant: 'success', text: "เปิดใช้งานบัญชี {$account->name} อีกครั้งแล้ว");
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'bank', 'account_name', 'kind', 'last_four', 'notes']);
        $this->resetValidation();
    }
}; ?>

@php
    $canCreate = auth()->user()->can('create', BankAccount::class);
    $filters = ['active' => 'ใช้งานอยู่', 'inactive' => 'ปิดใช้งาน', 'all' => 'ทั้งหมด'];
    $currentFilter = $this->statusFilter();
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    {{-- Page header --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">บัญชีธนาคาร</h1>
            <p class="mt-1 text-sm text-slate-500">บัญชีธนาคารของบริษัทที่ใช้โอนเงิน จ่ายผ่าน PromptPay/QR หรือให้ธนาคารหักบัญชีอัตโนมัติ</p>
        </div>

        @if ($canCreate)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="add-account-button" class="w-full sm:w-auto">
                เพิ่มบัญชี
            </flux:button>
        @endif
    </header>

    <flux:callout icon="shield-check" color="sky">
        <flux:callout.text>
            ระบบเก็บเฉพาะ<strong>เลขท้าย 4 หลัก</strong>ของบัญชี ไม่เก็บเลขบัญชีเต็ม รหัสผ่านธนาคาร PIN หรือ OTP
            @unless ($canCreate)
                <span class="block mt-1" data-test="read-only-notice">คุณดูรายการได้อย่างเดียว การเพิ่มหรือแก้ไขบัญชีทำได้โดยเจ้าของบริษัท</span>
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
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาชื่อ ธนาคาร หรือเลขท้าย" aria-label="ค้นหาบัญชี" clearable />
        </div>
    </div>

    {{-- Account list (stacked rows: one layout for phone and desktop) --}}
    @if ($this->counts['all'] === 0)
        <x-ui.empty-state icon="building-library" title="ยังไม่มีบัญชีธนาคารในระบบ" description="เพิ่มบัญชีที่บริษัทใช้โอนเงินหรือถูกหักอัตโนมัติ เพื่อใช้เป็นช่องทางชำระเงินของรายการค่าใช้จ่าย">
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" wire:click="openCreate">เพิ่มบัญชีแรก</flux:button>
            @endif
        </x-ui.empty-state>
    @elseif ($this->accounts->isEmpty())
        <x-ui.empty-state icon="magnifying-glass" title="ไม่พบบัญชีที่ตรงกับเงื่อนไข" description="ลองเปลี่ยนคำค้นหาหรือตัวกรองสถานะ" compact />
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="account-list">
                @foreach ($this->accounts as $account)
                    <li wire:key="account-{{ $account->id }}" @class([
                        'flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6',
                        'bg-slate-50/70' => ! $account->isActive(),
                    ])>
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class([
                                'flex size-10 shrink-0 items-center justify-center rounded-xl ring-1',
                                'bg-indigo-50 text-indigo-600 ring-indigo-100' => $account->isActive(),
                                'bg-slate-100 text-slate-400 ring-slate-200' => ! $account->isActive(),
                            ])>
                                <flux:icon.building-library class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p @class(['truncate font-medium', 'text-slate-900' => $account->isActive(), 'text-slate-500 line-through' => ! $account->isActive()])>
                                    {{ $account->name }}
                                </p>
                                <p class="truncate text-sm text-slate-500">
                                    {{ $account->bank }} · {{ $account->kind->label() }}
                                </p>
                                <p class="mt-0.5 text-sm text-slate-600">
                                    <span class="font-mono tracking-wider">{{ $account->maskedNumber() }}</span>
                                    <span class="text-slate-400">·</span>
                                    ชื่อบัญชี {{ $account->account_name }}
                                </p>
                                @if ($account->notes)
                                    <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $account->notes }}</p>
                                @endif
                                <p @class(['mt-1 text-xs', 'text-slate-500' => $account->isActive() || $account->active_methods_count === 0, 'font-medium text-red-600' => ! $account->isActive() && $account->active_methods_count > 0]) data-test="account-method-count">
                                    @if ($account->active_methods_count > 0)
                                        ใช้เป็นช่องทางชำระเงิน {{ $account->active_methods_count }} ช่องทาง{{ $account->isActive() ? '' : ' — ควรเปลี่ยนช่องทาง' }}
                                    @else
                                        ยังไม่มีช่องทางชำระเงินที่ใช้บัญชีนี้
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            @if ($account->isActive())
                                <x-ui.status-badge tone="emerald">ใช้งานอยู่</x-ui.status-badge>
                            @else
                                <x-ui.status-badge tone="slate">ปิดใช้งาน</x-ui.status-badge>
                            @endif

                            @can('update', $account)
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="'จัดการ '.$account->name" />

                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="openEdit({{ $account->id }})">แก้ไขข้อมูล</flux:menu.item>

                                        @can('deactivate', $account)
                                            <flux:menu.separator />
                                            <flux:menu.item
                                                icon="no-symbol"
                                                variant="danger"
                                                wire:click="deactivate({{ $account->id }})"
                                                wire:confirm="ปิดใช้งานบัญชี {{ $account->name }}?{{ $account->active_methods_count > 0 ? ' ⚠️ ยังมีช่องทางชำระเงิน '.$account->active_methods_count.' ช่องทางใช้บัญชีนี้อยู่ ควรเปลี่ยนช่องทางเหล่านั้นด้วย' : '' }} บัญชีจะเลือกใช้กับรายการใหม่ไม่ได้ แต่ข้อมูลและประวัติเดิมยังอยู่ครบ"
                                            >ปิดใช้งาน</flux:menu.item>
                                        @endcan

                                        @can('reactivate', $account)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="arrow-path" wire:click="reactivate({{ $account->id }})">เปิดใช้งานอีกครั้ง</flux:menu.item>
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
        <flux:modal name="account-form" class="w-full max-w-lg">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'แก้ไขข้อมูลบัญชี' : 'เพิ่มบัญชีใหม่' }}</flux:heading>
                    <flux:text class="mt-1">ห้ามกรอกเลขบัญชีเต็ม รหัสผ่าน PIN หรือ OTP ในช่องใด ๆ</flux:text>
                </div>

                <flux:input wire:model="name" label="ชื่อเรียกบัญชี" placeholder="เช่น บัญชีออมทรัพย์หลัก" required autocomplete="off" maxlength="100" />
                <flux:input wire:model="bank" label="ธนาคาร" placeholder="เช่น ธนาคารกสิกรไทย" required autocomplete="off" maxlength="100" />
                <flux:input wire:model="account_name" label="ชื่อเจ้าของบัญชี" description="ตามที่ระบุในสมุดบัญชี" required autocomplete="off" maxlength="150" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="kind" label="ประเภทบัญชี">
                        @foreach (BankAccountKind::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

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
                </div>

                <flux:textarea wire:model="notes" label="หมายเหตุ" rows="3" maxlength="1000" placeholder="เช่น ใช้โอนค่าเช่าสำนักงานและชำระผ่าน PromptPay" />

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-account-button">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
