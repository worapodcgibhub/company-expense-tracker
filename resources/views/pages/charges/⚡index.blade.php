<?php

use App\Enums\ReceiptStatus;
use App\Enums\SubscriptionCategory;
use App\Models\AccountingPeriodClosure;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Support\ChargeOverview;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Every recorded charge. Owner and Accountant see all; Staff only charges
 * of subscriptions they are responsible for (SubscriptionCharge::visibleTo,
 * applied in the query). Baht totals never include USD amounts directly:
 * USD charges count in baht only once their baht amount is filled in.
 */
new #[Title('รายการตัดเงิน')] class extends Component {
    public const LIMIT = 200;

    /** "YYYY-MM", or "all" */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $card = '';

    #[Url(except: '')]
    public string $category = '';

    /** "", "missing_thb", "after_close", "unverified" */
    #[Url(except: '')]
    public string $flag = '';

    /** ReceiptStatus value, or "" */
    #[Url(except: '')]
    public string $receipt = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SubscriptionCharge::class);
    }

    public function monthFilter(): ?string
    {
        if ($this->month === 'all') {
            return null;
        }

        return AccountingPeriodClosure::isValidPeriod($this->month) ? $this->month : AccountingPeriodClosure::periodOf(now());
    }

    /**
     * @return Builder<SubscriptionCharge>
     */
    private function query(): Builder
    {
        $period = $this->monthFilter();
        $category = SubscriptionCategory::tryFrom($this->category);

        return SubscriptionCharge::query()
            ->visibleTo(auth()->user())
            ->when($period !== null, function (Builder $query) use ($period) {
                // A month shows what was charged in it, plus skipped cycles due in it.
                $start = AccountingPeriodClosure::startOf((string) $period);
                $range = [$start->format('Y-m-d'), $start->endOfMonth()->format('Y-m-d')];

                $query->where(fn (Builder $query) => $query
                    ->whereBetween('charged_on', $range)
                    ->orWhere(fn (Builder $query) => $query->whereNull('charged_on')->whereBetween('due_date', $range)));
            })
            ->when($this->canFilterByCard() && ctype_digit($this->card), fn (Builder $query) => $query->where('credit_card_id', (int) $this->card))
            ->when($category !== null, fn (Builder $query) => $query->whereIn('subscription_id', Subscription::query()->select('id')->where('category', $category?->value)))
            ->when($this->flag === 'missing_thb', fn (Builder $query) => $query->charged()->where('currency', '!=', 'THB')->whereNull('amount_thb'))
            ->when($this->flag === 'after_close', fn (Builder $query) => $query->where('entered_after_close', true))
            ->when($this->flag === 'unverified', fn (Builder $query) => $query->charged()->whereNull('verified_at'))
            ->when(ReceiptStatus::tryFrom($this->receipt), fn (Builder $query, ReceiptStatus $status) => $query->receiptStatusIs($status));
    }

    /**
     * @return Collection<int, SubscriptionCharge>
     */
    #[Computed]
    public function charges(): Collection
    {
        return $this->query()
            ->with(['subscription', 'creditCard'])
            ->withCurrentReceiptCount()
            ->orderByRaw('coalesce(charged_on, due_date) desc')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}
     */
    #[Computed]
    public function totals(): array
    {
        return ChargeOverview::totals($this->query()->charged()->get());
    }

    #[Computed]
    public function periodClosure(): ?AccountingPeriodClosure
    {
        $period = $this->monthFilter();

        return $period !== null ? (AccountingPeriodClosure::closedAmong([$period])[$period] ?? null) : null;
    }

    /**
     * @return Collection<int, CreditCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        return $this->canFilterByCard() ? CreditCard::query()->orderByRaw('deactivated_at IS NOT NULL')->orderBy('name')->get() : new Collection;
    }

    public function resetFilters(): void
    {
        $this->reset(['month', 'card', 'category', 'flag', 'receipt']);
    }

    /**
     * Staff must not see the company's whole card list.
     */
    private function canFilterByCard(): bool
    {
        return auth()->user()->can('viewAny', CreditCard::class);
    }
}; ?>

@php
    $user = auth()->user();
    $seesAll = $user->isOwner() || $user->isAccountant();
    $period = $this->monthFilter();
    $totals = $this->totals;
    $closure = $this->periodClosure;
    $months = collect(ThaiFormat::recentMonths(12))->reverse();
    $hasFilters = $month !== '' || $card !== '' || $category !== '' || $flag !== '' || $receipt !== '';
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <header class="flex flex-col gap-1">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">รายการตัดเงิน</h1>
        <p class="text-sm text-slate-500">
            {{ $seesAll ? 'ยอดที่ตัดบัตรจริงของทุก Subscription' : 'ยอดที่ตัดบัตรจริงของ Subscription ที่คุณรับผิดชอบ' }}
            · บันทึกได้จากหน้ารายละเอียดของแต่ละ Subscription
        </p>
    </header>

    {{-- Totals (baht actually paid; USD without baht shown apart) --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" data-test="charge-totals">
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">ยอดรวมเป็นบาท</p>
            <p class="mt-1 text-2xl font-bold text-slate-900" data-test="thb-total">{{ ThaiFormat::money($totals['thb_total']) }}</p>
            <p class="mt-1 text-xs text-slate-500">ตามยอดเงินบาทที่ธนาคารตัดจริง</p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">จำนวนรายการที่ตัดเงิน</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['charge_count']) }} <span class="text-sm font-normal text-slate-500">รายการ</span></p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">USD ที่ยังไม่มียอดบาท</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-amber-600' => $totals['missing_thb_count'] > 0, 'text-slate-900' => $totals['missing_thb_count'] === 0]) data-test="missing-thb-total">
                {{ number_format($totals['missing_thb_count']) }} <span class="text-sm font-normal text-slate-500">รายการ</span>
            </p>
            @if ($totals['missing_thb_count'] > 0)
                <p class="mt-1 text-xs text-slate-500">รวม {{ ThaiFormat::money($totals['missing_thb_usd'], 'USD') }} ยังไม่นับในยอดบาท</p>
            @endif
        </x-ui.card>
    </div>

    @if ($closure)
        <flux:callout icon="lock-closed" color="amber" data-test="period-closed-notice">
            <flux:callout.text>
                งวด {{ ThaiFormat::monthYear(AccountingPeriodClosure::startOf($period)) }} ปิดแล้วเมื่อ {{ ThaiFormat::date($closure->created_at) }}
                ยอด ณ วันปิดงวด {{ ThaiFormat::money((int) ($closure->snapshot['thb_total'] ?? 0)) }}
                รายการที่บันทึกหรือแก้ไขหลังปิดงวดจะมีป้าย "ย้อนหลัง"
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Filters --}}
    <div @class(['grid gap-3 sm:grid-cols-2', 'lg:grid-cols-5' => $seesAll, 'lg:grid-cols-4' => ! $seesAll])>
        <flux:select wire:model.live="month" aria-label="เดือน">
            <flux:select.option value="">เดือนนี้</flux:select.option>
            @foreach ($months as $m)
                @continue($m->isSameMonth(now()))
                <flux:select.option value="{{ $m->format('Y-m') }}">{{ ThaiFormat::monthYear($m) }}</flux:select.option>
            @endforeach
            <flux:select.option value="all">ทุกเดือน (ล่าสุด {{ $this::LIMIT }} รายการ)</flux:select.option>
        </flux:select>

        @if ($seesAll)
            <flux:select wire:model.live="card" aria-label="บัตร">
                <flux:select.option value="">ทุกบัตร</flux:select.option>
                @foreach ($this->cards as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }} ({{ $option->maskedNumber() }}){{ $option->isActive() ? '' : ' · ปิดใช้งาน' }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:select wire:model.live="category" aria-label="หมวดหมู่">
            <flux:select.option value="">ทุกหมวดหมู่</flux:select.option>
            @foreach (SubscriptionCategory::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="flag" aria-label="สถานะ">
            <flux:select.option value="">ทุกสถานะ</flux:select.option>
            <flux:select.option value="missing_thb">รอยอดเงินบาท (USD)</flux:select.option>
            <flux:select.option value="after_close">ย้อนหลัง (หลังปิดงวด)</flux:select.option>
            <flux:select.option value="unverified">ยังไม่ตรวจสอบ</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="receipt" aria-label="สถานะเอกสาร" data-test="receipt-filter">
            <flux:select.option value="">เอกสารทุกสถานะ</flux:select.option>
            @foreach ([ReceiptStatus::Waiting, ReceiptStatus::Unavailable, ReceiptStatus::Attached] as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->charges->isEmpty())
        <x-ui.empty-state icon="receipt-percent" tone="cyan" title="ไม่มีรายการตัดเงินตามเงื่อนไขนี้" description="บันทึกยอดตัดจริงได้จากหน้ารายละเอียดของแต่ละ Subscription เมื่อใกล้ถึงหรือเลยวันตัดบัตร" compact>
            @if ($hasFilters)
                <flux:button size="sm" wire:click="resetFilters">ล้างตัวกรอง</flux:button>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="charge-list">
                @foreach ($this->charges as $charge)
                    <li wire:key="charge-{{ $charge->id }}">
                        <a href="{{ route('charges.show', $charge) }}" wire:navigate class="flex flex-col gap-2 p-4 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $charge->subscription->name }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ $charge->charged_on ? 'ตัดเงิน '.ThaiFormat::date($charge->charged_on) : 'รอบ '.ThaiFormat::date($charge->due_date) }}
                                    · <span class="font-mono tracking-wider">{{ $charge->creditCard->maskedNumber() }}</span>
                                    · {{ $charge->subscription->category->label() }}
                                </p>
                            </div>
                            <div class="flex flex-col gap-1 sm:items-end">
                                <p class="font-semibold text-slate-900">
                                    {{ $charge->amountLabel() }}
                                    @if ($charge->isCharged() && $charge->currency->value !== 'THB' && $charge->amount_thb !== null)
                                        <span class="text-sm font-normal text-slate-500">({{ ThaiFormat::money($charge->amount_thb) }})</span>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <x-charge-badges :charge="$charge" />
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
