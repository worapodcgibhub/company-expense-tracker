<?php

use App\Enums\ExpenseCategory;
use App\Enums\ReceiptStatus;
use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Support\ThaiFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * One-off expenses. Owner and Accountant see all; Staff only their own
 * (Expense::visibleTo, applied in the query). All amounts are baht.
 */
new #[Title('ค่าใช้จ่ายทั่วไป')] class extends Component {
    public const LIMIT = 100;

    /** "YYYY-MM", "all", or "" for this month */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $category = '';

    /** "waiting", "attached", or "" */
    #[Url(except: '')]
    public string $receipt = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Expense::class);
    }

    private function monthFilter(): ?string
    {
        if ($this->month === 'all') {
            return null;
        }

        return AccountingPeriodClosure::isValidPeriod($this->month) ? $this->month : AccountingPeriodClosure::periodOf(now());
    }

    /**
     * @return Builder<Expense>
     */
    private function query(): Builder
    {
        $period = $this->monthFilter();

        return Expense::query()
            ->visibleTo(auth()->user())
            ->when($period !== null, fn (Builder $query) => $query->paidInMonth(AccountingPeriodClosure::startOf((string) $period)))
            ->when(ExpenseCategory::tryFrom($this->category), fn (Builder $query, ExpenseCategory $category) => $query->where('category', $category->value))
            ->when($this->receipt === 'waiting', fn (Builder $query) => $query->whereNull('receipt_received_at'))
            ->when($this->receipt === 'attached', fn (Builder $query) => $query->whereNotNull('receipt_received_at'));
    }

    /**
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function expenses(): Collection
    {
        return $this->query()
            ->with(['paymentMethod', 'owner'])
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return array{count: int, total: int}
     */
    #[Computed]
    public function totals(): array
    {
        return ['count' => $this->query()->count(), 'total' => (int) $this->query()->sum('amount')];
    }

    #[Computed]
    public function waitingCount(): int
    {
        return $this->query()->whereNull('receipt_received_at')->count();
    }

    public function resetFilters(): void
    {
        $this->reset(['month', 'category', 'receipt']);
    }
}; ?>

@php
    $user = auth()->user();
    $seesAll = $user->isOwner() || $user->isAccountant();
    $totals = $this->totals;
    $months = collect(ThaiFormat::recentMonths(12))->reverse();
    $hasFilters = $month !== '' || $category !== '' || $receipt !== '';
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">ค่าใช้จ่ายทั่วไป</h1>
            <p class="text-sm text-slate-500">
                {{ $seesAll ? 'ค่าใช้จ่ายที่จ่ายครั้งเดียวของทุกคน' : 'ค่าใช้จ่ายที่จ่ายครั้งเดียวที่คุณบันทึก' }}
                · ไม่รวมค่าบริการรายเดือน/รายปี (อยู่ใน Subscription)
            </p>
        </div>
        @can('create', \App\Models\Expense::class)
            <flux:button variant="primary" icon="plus" :href="route('expenses.create')" wire:navigate class="w-full sm:w-auto" data-test="add-expense-button">เพิ่มค่าใช้จ่าย</flux:button>
        @endcan
    </header>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" data-test="expense-totals">
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">ยอดจ่ายจริงรวม (บาท)</p>
            <p class="mt-1 text-2xl font-bold text-slate-900" data-test="expense-total">{{ ThaiFormat::money($totals['total']) }}</p>
            <p class="mt-1 text-xs text-slate-500">ตามตัวกรองที่เลือก</p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">จำนวนรายการ</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['count']) }} <span class="text-sm font-normal text-slate-500">รายการ</span></p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">รอใบเสร็จ</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-red-600' => $this->waitingCount > 0, 'text-slate-900' => $this->waitingCount === 0]) data-test="expense-waiting-count">
                {{ number_format($this->waitingCount) }} <span class="text-sm font-normal text-slate-500">รายการ</span>
            </p>
        </x-ui.card>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="month" aria-label="เดือน">
            <flux:select.option value="">เดือนนี้</flux:select.option>
            @foreach ($months as $m)
                @continue($m->isSameMonth(now()))
                <flux:select.option value="{{ $m->format('Y-m') }}">{{ ThaiFormat::monthYear($m) }}</flux:select.option>
            @endforeach
            <flux:select.option value="all">ทุกเดือน (ล่าสุด {{ $this::LIMIT }} รายการ)</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="category" aria-label="หมวดหมู่">
            <flux:select.option value="">ทุกหมวดหมู่</flux:select.option>
            @foreach (ExpenseCategory::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="receipt" aria-label="สถานะใบเสร็จ" data-test="expense-receipt-filter">
            <flux:select.option value="">ใบเสร็จทุกสถานะ</flux:select.option>
            <flux:select.option value="waiting">{{ ReceiptStatus::Waiting->label() }}</flux:select.option>
            <flux:select.option value="attached">{{ ReceiptStatus::Attached->label() }}</flux:select.option>
        </flux:select>
    </div>

    @if ($this->expenses->isEmpty())
        <x-ui.empty-state icon="banknotes" tone="cyan" title="ยังไม่มีค่าใช้จ่ายทั่วไปตามเงื่อนไขนี้" :description='"กด \"เพิ่มค่าใช้จ่าย\" เพื่อบันทึกค่าใช้จ่ายที่จ่ายครั้งเดียว เช่น ซื้ออุปกรณ์ ค่าซ่อม ค่าเดินทาง"' compact>
            @if ($hasFilters)
                <flux:button size="sm" wire:click="resetFilters">ล้างตัวกรอง</flux:button>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="expense-list">
                @foreach ($this->expenses as $expense)
                    @php
                        $status = $expense->receiptStatus();
                        $canEdit = $user->can('update', $expense);
                    @endphp
                    <li wire:key="expense-{{ $expense->id }}">
                        <div class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $expense->title }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ ThaiFormat::date($expense->paid_on) }}
                                    · {{ $expense->category->label() }}
                                    · {{ $expense->paymentMethod->name }}
                                    @if ($seesAll)
                                        · {{ $expense->owner->name }}
                                    @endif
                                </p>
                                @if ($expense->notes)
                                    <p class="truncate text-xs text-slate-500">{{ $expense->notes }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 sm:justify-end">
                                <div class="flex flex-col gap-1 sm:items-end">
                                    <p class="font-semibold text-slate-900">{{ ThaiFormat::money($expense->amount) }}</p>
                                    <x-ui.status-badge :tone="$status->tone()">{{ $status->label() }}</x-ui.status-badge>
                                </div>
                                @if ($canEdit)
                                    <flux:button size="sm" :href="route('expenses.edit', $expense)" wire:navigate data-test="edit-expense-{{ $expense->id }}">แก้ไข</flux:button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
