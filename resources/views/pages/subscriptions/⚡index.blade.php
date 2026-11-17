<?php

use App\Enums\BillingCycle;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Support\SubscriptionOverview;
use App\Support\ThaiFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Subscription list. Owner and Accountant see every subscription; Staff see
 * only the ones they are responsible for. The restriction is applied in the
 * query (Subscription::visibleTo), not only by hiding things in the UI.
 * Amounts shown are estimates and are never added across currencies.
 */
new #[Title('Subscription')] class extends Component {
    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $cycle = '';

    #[Url(except: '')]
    public string $card = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Subscription::class);
    }

    /**
     * @return Collection<int, Subscription>
     */
    #[Computed]
    public function subscriptions(): Collection
    {
        $search = trim($this->search);
        $status = SubscriptionStatus::tryFrom($this->statusFilter());

        return Subscription::query()
            ->visibleTo(auth()->user())
            ->with(['creditCard', 'paymentMethod.creditCard', 'paymentMethod.bankAccount', 'responsible'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status?->value))
            ->when(SubscriptionCategory::tryFrom($this->category), fn (Builder $query, SubscriptionCategory $category) => $query->where('category', $category->value))
            ->when(BillingCycle::tryFrom($this->cycle), fn (Builder $query, BillingCycle $cycle) => $query->where('billing_cycle', $cycle->value))
            ->when($this->canFilterByCard() && ctype_digit($this->card), fn (Builder $query) => $query->where('credit_card_id', (int) $this->card))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('vendor', 'like', $like));
            })
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_charge_date')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = Subscription::query()
            ->visibleTo(auth()->user())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $byStatus->sum()];

        foreach (SubscriptionStatus::cases() as $case) {
            $counts[$case->value] = (int) ($byStatus[$case->value] ?? 0);
        }

        return $counts;
    }

    #[Computed]
    public function overview(): SubscriptionOverview
    {
        return new SubscriptionOverview(auth()->user());
    }

    /**
     * @return Collection<int, CreditCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        return $this->canFilterByCard() ? CreditCard::query()->orderByRaw('deactivated_at IS NOT NULL')->orderBy('name')->get() : new Collection;
    }

    public function statusFilter(): string
    {
        return in_array($this->status, ['active', 'paused', 'cancelled', 'all'], true) ? $this->status : 'active';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        unset($this->subscriptions);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'category', 'cycle', 'card']);
        unset($this->subscriptions);
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
    $canCreate = $user->can('create', Subscription::class);
    $filters = ['active' => 'ใช้งานอยู่', 'paused' => 'พักชั่วคราว', 'cancelled' => 'ยกเลิกแล้ว', 'all' => 'ทั้งหมด'];
    $currentFilter = $this->statusFilter();
    $overview = $this->overview;
    $monthly = $overview->monthlyEstimateByCurrency();
    $hasExtraFilters = $search !== '' || $category !== '' || $cycle !== '' || $card !== '';
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    {{-- Page header --}}
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $seesAll ? 'Subscription' : 'Subscription ของฉัน' }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $seesAll ? 'บริการที่บริษัทจ่ายเป็นรอบผ่านบัตร พร้อมวันที่คาดว่าจะตัดบัตรครั้งถัดไป' : 'บริการที่คุณเป็นผู้รับผิดชอบ' }}
            </p>
        </div>

        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('subscriptions.create')" wire:navigate data-test="add-subscription-button" class="w-full sm:w-auto">
                เพิ่ม Subscription
            </flux:button>
        @endif
    </header>

    {{-- Summary (estimates; per currency) --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-test="subscription-summary">
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">ใช้งานอยู่</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($overview->activeCount()) }} <span class="text-sm font-normal text-slate-500">บริการ</span></p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">จะตัดบัตรใน 7 วัน</p>
            <p class="mt-1 text-2xl font-bold text-amber-600">{{ number_format($overview->upcoming(7)->count()) }} <span class="text-sm font-normal text-slate-500">รายการ</span></p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">เลยกำหนด ยังไม่อัปเดต</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-red-600' => $overview->overdue()->isNotEmpty(), 'text-slate-900' => $overview->overdue()->isEmpty()])>
                {{ number_format($overview->overdue()->count()) }} <span class="text-sm font-normal text-slate-500">รายการ</span>
            </p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-sm text-slate-500">ประมาณการต่อเดือน</p>
            @forelse ($monthly as $currency => $amount)
                <p class="mt-1 font-bold text-slate-900">{{ ThaiFormat::money($amount, $currency) }}</p>
            @empty
                <p class="mt-1 text-2xl font-bold text-slate-900">–</p>
            @endforelse
            <p class="mt-1 text-xs text-slate-500">แยกตามสกุลเงิน ไม่ใช่ยอดตัดจริง</p>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="grid w-full grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 sm:inline-flex sm:w-auto" role="tablist" aria-label="กรองตามสถานะ">
                @foreach ($filters as $value => $label)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $currentFilter === $value ? 'true' : 'false' }}"
                        wire:click="setStatus('{{ $value }}')"
                        @class([
                            'whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-white text-slate-900 shadow-sm' => $currentFilter === $value,
                            'text-slate-600 hover:text-slate-900' => $currentFilter !== $value,
                        ])
                    >
                        {{ $label }} <span class="text-xs text-slate-500">({{ $this->counts[$value] }})</span>
                    </button>
                @endforeach
            </div>

            <div class="w-full lg:w-72">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="ค้นหาชื่อบริการหรือผู้ให้บริการ" aria-label="ค้นหา Subscription" clearable />
            </div>
        </div>

        <div @class(['grid gap-3', 'sm:grid-cols-3' => $seesAll, 'sm:grid-cols-2' => ! $seesAll])>
            <flux:select wire:model.live="category" aria-label="หมวดหมู่">
                <flux:select.option value="">ทุกหมวดหมู่</flux:select.option>
                @foreach (SubscriptionCategory::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="cycle" aria-label="รอบบิล">
                <flux:select.option value="">ทุกรอบบิล</flux:select.option>
                @foreach (BillingCycle::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($seesAll)
                <flux:select wire:model.live="card" aria-label="บัตรที่ใช้ชำระ">
                    <flux:select.option value="">ทุกบัตร</flux:select.option>
                    @foreach ($this->cards as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }} ({{ $option->maskedNumber() }}){{ $option->isActive() ? '' : ' · ปิดใช้งาน' }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>
    </div>

    {{-- List --}}
    @if ($this->counts['all'] === 0)
        <x-ui.empty-state
            icon="calendar-days"
            :title="$seesAll ? 'ยังไม่มี Subscription ในระบบ' : 'ยังไม่มี Subscription ที่คุณรับผิดชอบ'"
            description="เพิ่มบริการที่จ่ายเป็นรอบ เช่น Adobe, Microsoft 365 หรือค่า Hosting เพื่อให้รู้ล่วงหน้าว่าบัตรจะถูกตัดเงินเมื่อไร"
        >
            @if ($canCreate)
                <flux:button variant="primary" icon="plus" :href="route('subscriptions.create')" wire:navigate>เพิ่ม Subscription แรก</flux:button>
            @endif
        </x-ui.empty-state>
    @elseif ($this->subscriptions->isEmpty())
        <x-ui.empty-state icon="magnifying-glass" title="ไม่พบรายการที่ตรงกับเงื่อนไข" description="ลองเปลี่ยนคำค้นหาหรือตัวกรอง" compact>
            @if ($hasExtraFilters)
                <flux:button size="sm" wire:click="resetFilters">ล้างตัวกรอง</flux:button>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="subscription-list">
                @foreach ($this->subscriptions as $subscription)
                    @php
                        $overdue = $subscription->isOverdue();
                        $soon = $subscription->isActive() && ! $overdue && $subscription->daysUntilCharge() <= 7;
                    @endphp
                    <li wire:key="subscription-{{ $subscription->id }}" @class(['bg-slate-50/70' => ! $subscription->isActive()])>
                        <a href="{{ route('subscriptions.show', $subscription) }}" wire:navigate class="flex flex-col gap-3 p-4 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="min-w-0">
                                <p @class(['truncate font-medium', 'text-slate-900' => ! $subscription->isCancelled(), 'text-slate-500 line-through' => $subscription->isCancelled()])>
                                    {{ $subscription->name }}
                                </p>
                                <p class="truncate text-sm text-slate-500">{{ $subscription->vendor }} · {{ $subscription->category->label() }}</p>
                                <p class="mt-0.5 text-sm text-slate-600">
                                    <span class="font-mono tracking-wider">{{ $subscription->creditCard->maskedNumber() }}</span>
                                    <span class="text-slate-400">·</span>
                                    ผู้รับผิดชอบ {{ $subscription->responsible->name }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:items-end">
                                <p class="font-semibold text-slate-900">{{ $subscription->amountLabel() }} <span class="text-xs font-normal text-slate-500">(ประมาณการ)</span></p>
                                <p class="text-sm text-slate-600">
                                    ตัดบัตรถัดไป {{ ThaiFormat::date($subscription->next_charge_date) }}
                                    @if ($subscription->isActive())
                                        <span @class(['font-medium', 'text-red-600' => $overdue, 'text-amber-600' => $soon, 'text-slate-500' => ! $overdue && ! $soon])>· {{ $subscription->dueLabel() }}</span>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <x-ui.status-badge :tone="$subscription->status->tone()">{{ $subscription->status->label() }}</x-ui.status-badge>
                                    @if ($overdue)
                                        <x-ui.status-badge tone="red" :dot="false">เลยกำหนด</x-ui.status-badge>
                                    @endif
                                    @if ($subscription->needsNewCard())
                                        <x-ui.status-badge tone="red" :dot="false" data-test="card-deactivated-badge">บัตรถูกปิดใช้งาน</x-ui.status-badge>
                                    @endif
                                    @if ($subscription->needsNewPaymentMethod())
                                        <x-ui.status-badge tone="red" :dot="false" data-test="method-deactivated-badge">ช่องทางชำระถูกปิดใช้งาน</x-ui.status-badge>
                                    @endif
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
