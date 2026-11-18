<?php

use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Models\SubscriptionCharge;
use App\Enums\DataSet;
use App\Support\MonthlyReport;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Monthly report: what was really paid in a month, from subscription charges
 * and one-off expenses together (see App\Support\MonthlyReport). Read-only.
 * Everything is limited to what the person may see (Staff: their own).
 * Baht and US dollars are shown apart; no exchange rate is assumed.
 */
new #[Title('รายงานรายเดือน')] class extends Component {
    public const LIMIT = 200;

    /** Gregorian year, or "" for this year */
    #[Url(except: '')]
    public string $year = '';

    /** 1-12, or "" for this month */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: false)]
    public bool $onlyMissing = false;

    /** real (default) | sample | all */
    #[Url(except: 'real')]
    public string $data = 'real';

    public function mount(): void
    {
        $this->authorize('viewAny', SubscriptionCharge::class);

        // Show the chosen month in the selects (an empty value would show January).
        $selected = $this->selected;
        $this->year = (string) $selected->year;
        $this->month = (string) $selected->month;
    }

    #[Computed]
    public function selected(): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $year = ctype_digit($this->year) && (int) $this->year >= 2000 && (int) $this->year <= 2100 ? (int) $this->year : $now->year;
        $month = ctype_digit($this->month) && (int) $this->month >= 1 && (int) $this->month <= 12 ? (int) $this->month : $now->month;

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    #[Computed]
    public function report(): MonthlyReport
    {
        return new MonthlyReport(auth()->user(), $this->selected, $this->dataSet);
    }

    #[Computed]
    public function dataSet(): DataSet
    {
        return DataSet::fromRequest($this->data);
    }

    /**
     * Years to choose from: the earliest year with anything visible, up to now.
     *
     * @return list<int>
     */
    #[Computed]
    public function years(): array
    {
        $user = auth()->user();
        $first = collect([
            SubscriptionCharge::query()->visibleTo($user)->charged()->min('charged_on'),
            Expense::query()->visibleTo($user)->min('paid_on'),
        ])->filter()->map(fn ($date) => (int) substr((string) $date, 0, 4))->min();

        $current = CarbonImmutable::now()->year;
        $from = min($first ?? $current, $current, $this->selected->year);
        $to = max($current, $this->selected->year);

        return range($to, $from);
    }

    #[Computed]
    public function closure(): ?AccountingPeriodClosure
    {
        $period = AccountingPeriodClosure::periodOf($this->selected);

        return AccountingPeriodClosure::closedAmong([$period])[$period] ?? null;
    }

    public function resetToThisMonth(): void
    {
        $now = CarbonImmutable::now();
        $this->year = (string) $now->year;
        $this->month = (string) $now->month;
        $this->onlyMissing = false;
    }
}; ?>

@php
    $user = auth()->user();
    $seesAll = $user->isOwner() || $user->isAccountant();
    $selected = $this->selected;
    $summary = $this->report->summary();
    $closure = $this->closure;
    $rows = $this->report->rows();
    $rows = $onlyMissing ? $rows->filter(fn ($row) => $row['receipt']->value === 'waiting')->values() : $rows;
    $shown = $rows->take($this::LIMIT);
    $liveSubscriptionThb = $summary['thb']['subscription'];
    $closedThb = $closure ? (int) ($closure->snapshot['thb_total'] ?? 0) : null;
    $monthName = fn (int $m) => Str::beforeLast(ThaiFormat::monthYear(CarbonImmutable::create(2000, $m, 1)), ' ');
    $isThisMonth = $selected->isSameMonth(now());
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">รายงานรายเดือน</h1>
            <p class="text-sm text-slate-500">
                ยอดจ่ายจริงของ <strong class="text-slate-700" data-test="report-month">{{ ThaiFormat::monthYear($selected) }}</strong>
                จาก Subscription และค่าใช้จ่ายทั่วไปรวมกัน {{ $seesAll ? '' : '(เฉพาะที่เกี่ยวข้องกับคุณ)' }}
            </p>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center">
            <flux:select wire:model.live="month" aria-label="เดือน" data-test="report-month-select">
                @foreach (range(1, 12) as $m)
                    <flux:select.option value="{{ $m }}">{{ $monthName($m) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="year" aria-label="ปี" data-test="report-year-select">
                @foreach ($this->years as $y)
                    <flux:select.option value="{{ $y }}">พ.ศ. {{ $y + 543 }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </header>

    <x-ui.data-set-switch :current="$this->dataSet" :query="['year' => $selected->year, 'month' => $selected->month]" />

    {{-- The closing snapshot holds real data only: compare it only with the real view. --}}
    @if ($closure && ! $this->dataSet->isReal())
        {{-- no closing notice for sample / all views --}}
    @elseif ($closure && $closedThb !== $liveSubscriptionThb)
        <flux:callout icon="lock-closed" color="amber" data-test="report-closed-diff">
            <flux:callout.text>
                งวดนี้ปิดแล้ว ยอด Subscription ณ วันปิดงวดคือ {{ ThaiFormat::money($closedThb) }}
                แต่ยอดจริงตอนนี้คือ {{ ThaiFormat::money($liveSubscriptionThb) }} (มีรายการที่บันทึกหรือแก้ไขหลังปิดงวด) รายงานนี้แสดงยอดล่าสุด
            </flux:callout.text>
        </flux:callout>
    @elseif ($closure)
        <flux:callout icon="lock-closed" color="sky" data-test="report-closed">
            <flux:callout.text>งวดนี้ปิดบัญชีแล้ว และยอด Subscription ตรงกับยอด ณ วันปิดงวด</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="report-summary">
        <x-ui.stat-card
            :title="__('ยอดจ่ายจริงรวม (บาท)')"
            :value="ThaiFormat::money($summary['thb']['total'])"
            :hint="'Subscription '.ThaiFormat::money($summary['thb']['subscription']).' · ทั่วไป '.ThaiFormat::money($summary['thb']['one_off'])"
            icon="banknotes"
            tone="indigo"
            data-test="report-thb-total"
        />
        <x-ui.stat-card
            :title="__('จำนวนรายการจ่ายจริง')"
            :value="number_format($summary['counts']['total']).' '.__('รายการ')"
            :hint="'Subscription '.number_format($summary['counts']['subscription']).' · ทั่วไป '.number_format($summary['counts']['one_off'])"
            icon="clipboard-document-check"
            tone="cyan"
            data-test="report-count"
        />
        <x-ui.stat-card
            :title="__('ยอดสกุลดอลลาร์ (USD)')"
            :value="ThaiFormat::money($summary['foreign']['usd'], 'USD')"
            :hint="$summary['foreign']['count'] === 0
                ? 'ไม่มีรายการสกุลดอลลาร์'
                : number_format($summary['foreign']['count']).' รายการ · ไม่แปลงด้วยอัตราสมมติ ยอดบาทที่ธนาคารตัดจริงอยู่ในยอดบาทด้านซ้ายแล้ว'"
            icon="currency-dollar"
            tone="amber"
            data-test="report-usd"
        />
        <x-ui.stat-card
            :title="__('ขาดใบเสร็จ')"
            :value="number_format($summary['receipts']['waiting']).' '.__('รายการ')"
            :hint="$summary['receipts']['waiting'] > 0
                ? 'Subscription '.$summary['receipts']['subscription']['waiting'].' · ทั่วไป '.$summary['receipts']['one_off']['waiting'].' (ไม่นับรายการที่ระบุว่าไม่มีใบเสร็จ)'
                : 'ไม่มีรายการค้างในเดือนนี้'"
            icon="document-magnifying-glass"
            :tone="$summary['receipts']['waiting'] > 0 ? 'red' : 'emerald'"
            data-test="report-missing-receipts"
        />
    </div>

    @if ($summary['missing_thb']['count'] > 0)
        <flux:callout icon="exclamation-triangle" color="amber" data-test="report-missing-thb">
            <flux:callout.text>
                มี {{ number_format($summary['missing_thb']['count']) }} รายการสกุลดอลลาร์ ({{ ThaiFormat::money($summary['missing_thb']['usd'], 'USD') }})
                ที่ยังไม่มียอดเงินบาท จึงยังไม่นับรวมในยอดบาท กรอกยอดบาทจากใบแจ้งยอดบัตรเมื่อได้รับ
            </flux:callout.text>
        </flux:callout>
    @endif

    <p class="text-xs text-slate-500">
        ยอดในรายงานนี้เป็นยอดที่จ่ายจริงและบันทึกแล้วเท่านั้น ไม่รวมยอดประมาณการหรือรอบที่ยังไม่ได้บันทึก
        Subscription และค่าใช้จ่ายทั่วไปเป็นคนละรายการ จึงไม่ถูกนับซ้ำ
    </p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900">รายการจ่ายจริงในเดือนนี้</h2>
        <div class="flex items-center gap-3">
            <flux:checkbox wire:model.live="onlyMissing" label="เฉพาะที่ขาดใบเสร็จ" data-test="report-only-missing" />
            @if (! $isThisMonth || $onlyMissing)
                <flux:button size="sm" wire:click="resetToThisMonth">เดือนนี้</flux:button>
            @endif
        </div>
    </div>

    @if ($shown->isEmpty())
        <x-ui.empty-state
            icon="receipt-percent"
            tone="cyan"
            :title="$onlyMissing ? 'ไม่มีรายการที่ขาดใบเสร็จในเดือนนี้' : 'ยังไม่มีรายการจ่ายจริงในเดือนนี้'"
            :description="__('รายการจะแสดงเมื่อบันทึกยอดตัดจริงของ Subscription หรือเพิ่มค่าใช้จ่ายทั่วไป')"
            compact
        />
    @else
        <x-ui.card :padding="false" class="overflow-hidden">
            <ul class="divide-y divide-slate-100" data-test="report-rows">
                @foreach ($shown as $row)
                    @php
                        $isSubscription = $row['kind'] === 'subscription';
                        $url = $isSubscription
                            ? route('charges.show', $row['model'])
                            : ($user->can('update', $row['model']) ? route('expenses.edit', $row['model']) : null);
                    @endphp
                    <li wire:key="report-{{ $row['kind'] }}-{{ $row['id'] }}" data-test="report-row-{{ $row['kind'] }}">
                        <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" wire:navigate @endif class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 {{ $url ? 'transition hover:bg-slate-50' : '' }}">
                            <div class="min-w-0">
                                <p class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-medium text-slate-900">{{ $row['name'] }}</span>
                                    <x-ui.status-badge :tone="$isSubscription ? 'indigo' : 'cyan'" :dot="false">{{ $isSubscription ? 'Subscription' : 'ทั่วไป' }}</x-ui.status-badge>
                                    @if ($isSubscription && $row['model']->subscription->is_sample)
                                        <x-ui.status-badge tone="amber" :dot="false" data-test="sample-badge">ข้อมูลตัวอย่าง</x-ui.status-badge>
                                    @endif
                                </p>
                                <p class="text-sm text-slate-500">
                                    {{ ThaiFormat::date($row['date']) }}
                                    @if ($row['method'])
                                        · {{ $row['method'] }}
                                    @endif
                                    @if ($seesAll && $row['owner'])
                                        · {{ $row['owner'] }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-col gap-1 sm:items-end">
                                <p class="font-semibold text-slate-900">
                                    {{ ThaiFormat::money($row['amount'], $row['currency']) }}
                                    @if ($row['currency'] !== 'THB')
                                        <span class="text-sm font-normal text-slate-500">({{ $row['thb'] !== null ? ThaiFormat::money($row['thb']) : 'รอยอดบาท' }})</span>
                                    @endif
                                </p>
                                <x-ui.status-badge :tone="$row['receipt']->tone()">{{ $row['receipt']->label() }}</x-ui.status-badge>
                            </div>
                        </{{ $url ? 'a' : 'div' }}>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        @if ($rows->count() > $this::LIMIT)
            <p class="text-xs text-slate-500">แสดง {{ $this::LIMIT }} จาก {{ number_format($rows->count()) }} รายการ ยอดรวมด้านบนนับครบทุกรายการ</p>
        @endif
    @endif
</div>
