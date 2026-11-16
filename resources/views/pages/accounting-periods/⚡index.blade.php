<?php

use App\Models\AccountingPeriodClosure;
use App\Models\SubscriptionCharge;
use App\Rules\NoFullCardNumber;
use App\Support\ChargeOverview;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Accounting periods (months). Owner and Accountant close a month once it
 * has ended; closing saves the month's figures (snapshot). Reopening needs
 * a reason. Every close/reopen is kept in the log below — nothing happens
 * automatically, and entries made later in a closed month are only flagged
 * as late entries.
 */
new #[Title('งวดบัญชี')] class extends Component {
    public const MONTHS = 12;

    #[Locked]
    public string $selectedPeriod = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AccountingPeriodClosure::class);
    }

    /**
     * The last MONTHS months, newest first, with their current state.
     *
     * @return list<array{period: string, month: CarbonImmutable, closure: ?AccountingPeriodClosure, closable: bool, live: array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}, late_count: int}>
     */
    #[Computed]
    public function periods(): array
    {
        $months = array_reverse(ThaiFormat::recentMonths(self::MONTHS));
        $periods = array_map(fn ($m) => AccountingPeriodClosure::periodOf($m), $months);
        $latest = AccountingPeriodClosure::latestFor($periods);

        $rows = [];

        foreach ($months as $i => $month) {
            $period = $periods[$i];
            $closure = $latest[$period] ?? null;
            $start = CarbonImmutable::instance($month)->startOfMonth();

            $rows[] = [
                'period' => $period,
                'month' => $start,
                'closure' => $closure?->isClosing() ? $closure : null,
                'closable' => AccountingPeriodClosure::canBeClosed($period),
                'live' => ChargeOverview::snapshotFor($period),
                'late_count' => SubscriptionCharge::query()->chargedInMonth($start)->where('entered_after_close', true)->count(),
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, AccountingPeriodClosure>
     */
    #[Computed]
    public function log(): Collection
    {
        return AccountingPeriodClosure::query()->with('actor')->latest('id')->limit(50)->get();
    }

    public function openClose(string $period): void
    {
        $this->authorize('close', AccountingPeriodClosure::class);
        abort_unless(AccountingPeriodClosure::canBeClosed($period), 404);

        $this->selectedPeriod = $period;
        $this->reason = '';
        $this->resetValidation();

        Flux::modal('close-period')->show();
    }

    public function confirmClose(): void
    {
        $this->authorize('close', AccountingPeriodClosure::class);
        abort_unless(AccountingPeriodClosure::canBeClosed($this->selectedPeriod), 404);

        $validated = $this->validate(['reason' => ['nullable', 'string', 'max:500', new NoFullCardNumber]], [], ['reason' => 'หมายเหตุ']);

        if (AccountingPeriodClosure::isClosed($this->selectedPeriod)) {
            Flux::modal('close-period')->close();
            Flux::toast(variant: 'warning', text: 'งวดนี้ปิดไปแล้ว');

            return;
        }

        AccountingPeriodClosure::close($this->selectedPeriod, auth()->user(), $validated['reason']);

        unset($this->periods, $this->log);
        Flux::modal('close-period')->close();
        Flux::toast(variant: 'success', text: 'ปิดงวดบัญชีแล้ว');
    }

    public function openReopen(string $period): void
    {
        $this->authorize('reopen', AccountingPeriodClosure::class);
        abort_unless(AccountingPeriodClosure::isValidPeriod($period) && AccountingPeriodClosure::isClosed($period), 404);

        $this->selectedPeriod = $period;
        $this->reason = '';
        $this->resetValidation();

        Flux::modal('reopen-period')->show();
    }

    public function confirmReopen(): void
    {
        $this->authorize('reopen', AccountingPeriodClosure::class);
        abort_unless(AccountingPeriodClosure::isValidPeriod($this->selectedPeriod), 404);

        $this->reason = trim($this->reason);
        $validated = $this->validate(['reason' => ['required', 'string', 'max:500', new NoFullCardNumber]], ['reason.required' => 'กรุณาระบุเหตุผลที่เปิดงวด'], ['reason' => 'เหตุผล']);

        if (! AccountingPeriodClosure::isClosed($this->selectedPeriod)) {
            Flux::modal('reopen-period')->close();
            Flux::toast(variant: 'warning', text: 'งวดนี้ไม่ได้ปิดอยู่');

            return;
        }

        AccountingPeriodClosure::reopen($this->selectedPeriod, auth()->user(), $validated['reason']);

        unset($this->periods, $this->log);
        Flux::modal('reopen-period')->close();
        Flux::toast(variant: 'success', text: 'เปิดงวดบัญชีแล้ว');
    }
}; ?>

@php
    $selectedLabel = AccountingPeriodClosure::isValidPeriod($selectedPeriod) ? ThaiFormat::monthYear(AccountingPeriodClosure::startOf($selectedPeriod)) : '';
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <header class="flex flex-col gap-1">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">งวดบัญชี</h1>
        <p class="text-sm text-slate-500">ปิดงวดเมื่อตรวจยอดของเดือนนั้นเสร็จแล้ว ระบบจะเก็บยอด ณ วันปิดงวดไว้ และไม่เปลี่ยนยอดของงวดที่ปิดแล้วเอง รายการที่บันทึกภายหลังจะถูกระบุว่าเป็นรายการย้อนหลัง</p>
    </header>

    <x-ui.card :padding="false" class="overflow-hidden">
        <ul class="divide-y divide-slate-100" data-test="period-list">
            @foreach ($this->periods as $row)
                <li wire:key="period-{{ $row['period'] }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-test="period-{{ $row['period'] }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium text-slate-900">{{ ThaiFormat::monthYear($row['month']) }}</p>
                            @if ($row['closure'])
                                <x-ui.status-badge tone="slate">ปิดงวดแล้ว</x-ui.status-badge>
                            @elseif ($row['closable'])
                                <x-ui.status-badge tone="emerald">เปิดอยู่</x-ui.status-badge>
                            @else
                                <x-ui.status-badge tone="indigo">เดือนปัจจุบัน</x-ui.status-badge>
                            @endif
                            @if ($row['late_count'] > 0)
                                <x-ui.status-badge tone="red" :dot="false">ย้อนหลัง {{ $row['late_count'] }} รายการ</x-ui.status-badge>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            @if ($row['closure'])
                                ยอด ณ วันปิดงวด {{ ThaiFormat::money((int) ($row['closure']->snapshot['thb_total'] ?? 0)) }}
                                ({{ number_format((int) ($row['closure']->snapshot['charge_count'] ?? 0)) }} รายการ)
                                · ปิดโดย {{ $row['closure']->actor?->name ?? '–' }} {{ ThaiFormat::date($row['closure']->created_at) }}
                                @if ($row['live']['thb_total'] !== (int) ($row['closure']->snapshot['thb_total'] ?? 0))
                                    <span class="block text-amber-700">ยอดปัจจุบัน {{ ThaiFormat::money($row['live']['thb_total']) }} (ต่างจากวันปิดงวด ต้องตรวจรายการย้อนหลัง)</span>
                                @endif
                            @else
                                ยอดปัจจุบัน {{ ThaiFormat::money($row['live']['thb_total']) }} ({{ number_format($row['live']['charge_count']) }} รายการ)
                            @endif
                            @if ($row['live']['missing_thb_count'] > 0)
                                <span class="block text-amber-700">USD ยังไม่มียอดบาท {{ $row['live']['missing_thb_count'] }} รายการ ({{ ThaiFormat::money($row['live']['missing_thb_usd'], 'USD') }})</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex gap-2">
                        @if ($row['closure'])
                            @can('reopen', AccountingPeriodClosure::class)
                                <flux:button size="sm" icon="lock-open" wire:click="openReopen('{{ $row['period'] }}')" data-test="reopen-{{ $row['period'] }}">เปิดงวด</flux:button>
                            @endcan
                        @elseif ($row['closable'])
                            @can('close', AccountingPeriodClosure::class)
                                <flux:button size="sm" icon="lock-closed" variant="primary" wire:click="openClose('{{ $row['period'] }}')" data-test="close-{{ $row['period'] }}">ปิดงวด</flux:button>
                            @endcan
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-header title="ประวัติการปิด/เปิดงวด" icon="clock" />
        @if ($this->log->isEmpty())
            <p class="text-sm text-slate-500">ยังไม่มีการปิดงวด</p>
        @else
            <ol class="space-y-3" data-test="period-log">
                @foreach ($this->log as $entry)
                    <li class="flex flex-col gap-0.5 text-sm sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <span class="font-medium text-slate-800">{{ $entry->isClosing() ? 'ปิดงวด' : 'เปิดงวด' }} {{ ThaiFormat::monthYear(AccountingPeriodClosure::startOf($entry->period)) }}</span>
                            @if ($entry->reason)
                                <span class="block text-slate-600">{{ $entry->isClosing() ? 'หมายเหตุ' : 'เหตุผล' }}: {{ $entry->reason }}</span>
                            @endif
                        </div>
                        <span class="text-slate-500">{{ $entry->actor?->name ?? '–' }} · {{ ThaiFormat::date($entry->created_at) }} {{ $entry->created_at?->format('H:i') }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>

    <flux:modal name="close-period" class="w-full max-w-md">
        <form wire:submit="confirmClose" class="space-y-5">
            <div>
                <flux:heading size="lg">ปิดงวด {{ $selectedLabel }}</flux:heading>
                <flux:text class="mt-1">ระบบจะเก็บยอด ณ ตอนนี้ไว้เป็นยอดของงวด รายการที่บันทึกหรือแก้ไขในเดือนนี้หลังจากนี้ จะต้องยืนยันและถูกระบุว่าเป็นรายการย้อนหลัง</flux:text>
            </div>
            <flux:input wire:model="reason" label="หมายเหตุ (ไม่บังคับ)" maxlength="500" autocomplete="off" />
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="confirm-close">ปิดงวด</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reopen-period" class="w-full max-w-md">
        <form wire:submit="confirmReopen" class="space-y-5">
            <div>
                <flux:heading size="lg">เปิดงวด {{ $selectedLabel }}</flux:heading>
                <flux:text class="mt-1">ประวัติการปิดงวดเดิมยังเก็บไว้ครบ เมื่อปิดงวดอีกครั้ง ระบบจะเก็บยอดใหม่</flux:text>
            </div>
            <flux:input wire:model="reason" label="เหตุผล" maxlength="500" required autocomplete="off" />
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto">เปิดงวด</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
