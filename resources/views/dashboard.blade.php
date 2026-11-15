@use('App\Support\ThaiFormat')

@php
    $user = auth()->user();
    $today = now();

    /*
     * Dashboard data. No sample data anywhere.
     * - Subscriptions (Phase 2.3) are real, limited to what this user may see
     *   (Staff: only their own). Amounts are ESTIMATES, totalled per currency.
     * - Actual expenses do not exist yet (Phase 2.4+): those values stay zero /
     *   empty until their phase ships.
     */
    $subscriptions = new \App\Support\SubscriptionOverview($user, $today);
    $monthlyEstimate = $subscriptions->monthlyEstimateByCurrency();

    $summary = [
        'month_total' => 0,      // minor units (satang)
        'recorded_count' => 0,
        'upcoming_7d_count' => $subscriptions->upcoming(7)->count(),
        'missing_receipt_count' => 0,
    ];
    $upcomingCharges = $subscriptions->upcoming(30);
    $actionItems = $subscriptions->overdue()
        ->map(fn ($s) => ['subscription' => $s, 'label' => $s->dueLabel($today), 'tone' => 'red'])
        ->concat($subscriptions->needingNewCard()->map(fn ($s) => ['subscription' => $s, 'label' => 'บัตรถูกปิดใช้งาน ต้องเปลี่ยนบัตร', 'tone' => 'red']))
        ->values();
    $recentExpenses = collect();
    $categoryBreakdown = [];
    $monthlyTotals = collect(ThaiFormat::recentMonths(6, $today))
        ->map(fn ($month) => [
            'label' => ThaiFormat::monthShort($month),
            'value' => 0,
            'current' => $month->isSameMonth($today),
        ])
        ->all();

    $needsTwoFactor = \Laravel\Fortify\Features::canManageTwoFactorAuthentication()
        && is_null($user->two_factor_confirmed_at);
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Page header --}}
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="text-sm font-medium text-indigo-600">{{ ThaiFormat::dateWithDay($today) }}</p>
                <h1 class="mt-1 truncate text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                    {{ __('สวัสดี') }}, {{ \Illuminate\Support\Str::before($user->name, ' ') ?: $user->name }} 👋
                </h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('ภาพรวมค่าใช้จ่ายและรอบตัดบัตรของบริษัท') }}</p>
            </div>
            <x-ui.status-badge tone="emerald" class="self-start sm:self-auto">
                {{ $user->role->label() }}
            </x-ui.status-badge>
        </header>

        {{-- 2FA reminder (real data: shown until the user enables 2FA) --}}
        @if ($needsTwoFactor)
            <div class="bg-brand-gradient relative overflow-hidden rounded-2xl p-5 text-white shadow-lg shadow-indigo-500/20 sm:p-6" data-test="two-factor-reminder">
                <div aria-hidden="true" class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-white/10 blur-2xl"></div>
                <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-4">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/30">
                            <flux:icon icon="shield-check" class="size-6" />
                        </span>
                        <div>
                            <p class="font-semibold">{{ __('เพิ่มความปลอดภัยให้บัญชีของคุณ') }}</p>
                            <p class="mt-0.5 text-sm text-indigo-100">{{ __('เปิดใช้การยืนยันตัวตนสองขั้นตอน (2FA) เพื่อป้องกันการเข้าถึงข้อมูลค่าใช้จ่ายของบริษัทโดยไม่ได้รับอนุญาต') }}</p>
                        </div>
                    </div>
                    <a href="{{ route('security.edit') }}" wire:navigate
                       class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-50">
                        {{ __('เปิดใช้ 2FA') }}
                        <flux:icon icon="arrow-right" class="size-4" />
                    </a>
                </div>
            </div>
        @endif

        {{-- Summary cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card
                :title="__('ค่าใช้จ่ายเดือนนี้')"
                :value="ThaiFormat::money($summary['month_total'])"
                :hint="ThaiFormat::monthYear($today)"
                icon="banknotes"
                tone="indigo"
            />
            <x-ui.stat-card
                :title="__('บันทึกแล้วเดือนนี้')"
                :value="number_format($summary['recorded_count']).' '.__('รายการ')"
                :hint="__('ค่าใช้จ่ายที่ยืนยันยอดจริงแล้ว')"
                icon="clipboard-document-check"
                tone="cyan"
            />
            <x-ui.stat-card
                :title="__('ใกล้ถึงรอบตัดบัตร')"
                :value="number_format($summary['upcoming_7d_count']).' '.__('รายการ')"
                :hint="__('ภายใน 7 วันข้างหน้า')"
                icon="calendar-days"
                tone="amber"
            />
            <x-ui.stat-card
                :title="__('ขาดใบเสร็จ')"
                :value="number_format($summary['missing_receipt_count']).' '.__('รายการ')"
                :hint="$summary['missing_receipt_count'] > 0 ? __('ต้องติดตามใบเสร็จ') : __('ไม่มีรายการค้าง')"
                icon="document-magnifying-glass"
                :tone="$summary['missing_receipt_count'] > 0 ? 'red' : 'emerald'"
            />
        </div>

        {{-- Upcoming charges + action needed --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <x-ui.section-header
                    :title="__('รอบตัดบัตรที่กำลังจะถึง')"
                    :description="__('ค่าใช้จ่ายประจำที่จะถูกตัดเงินภายใน 30 วัน')"
                    icon="credit-card"
                />

                <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600" data-test="subscription-overview">
                    <span>ใช้งานอยู่ <strong class="text-slate-900">{{ number_format($subscriptions->activeCount()) }}</strong> บริการ</span>
                    @if ($monthlyEstimate)
                        <span>
                            ประมาณการต่อเดือน
                            @foreach ($monthlyEstimate as $currency => $amount)
                                <strong class="text-slate-900">{{ ThaiFormat::money($amount, $currency) }}</strong>@if (! $loop->last) · @endif
                            @endforeach
                        </span>
                    @endif
                    <a href="{{ route('subscriptions.index') }}" wire:navigate class="ms-auto font-medium text-indigo-600 hover:text-indigo-700">ดูทั้งหมด →</a>
                </div>

                @if ($upcomingCharges->isEmpty())
                    <x-ui.empty-state
                        icon="calendar"
                        :title="__('ยังไม่มีรอบตัดบัตรที่กำลังจะถึง')"
                        :description="__('เมื่อลงทะเบียนค่าใช้จ่ายประจำ เช่น Adobe, Microsoft 365 หรือค่า Hosting รอบตัดเงินถัดไปจะแสดงที่นี่')"
                    />
                @else
                    <ul class="divide-y divide-slate-100" data-test="upcoming-charges">
                        @foreach ($upcomingCharges as $charge)
                            @php $days = $charge->daysUntilCharge($today); @endphp
                            <li>
                                <a href="{{ route('subscriptions.show', $charge) }}" wire:navigate class="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900">{{ $charge->name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $charge->creditCard->maskedNumber() }} · {{ $charge->responsible->name }}</p>
                                    </div>
                                    <div class="shrink-0 text-end">
                                        <p class="text-sm font-semibold text-slate-900">{{ ThaiFormat::money($charge->estimated_amount, $charge->currency->value) }}</p>
                                        <p @class(['text-xs', 'font-medium text-amber-600' => $days <= 7, 'text-slate-500' => $days > 7])>
                                            {{ ThaiFormat::date($charge->next_charge_date) }} · {{ $charge->dueLabel($today) }}
                                        </p>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-slate-500">ยอดเป็นประมาณการ ยอดที่ตัดจริงอาจต่างไป โดยเฉพาะรายการสกุลเงิน USD</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-header
                    :title="__('ต้องดำเนินการ')"
                    :description="__('รายการที่เลยกำหนดหรือยังไม่ยืนยันยอด')"
                    icon="exclamation-triangle"
                />

                @if ($actionItems->isEmpty())
                    <x-ui.empty-state
                        icon="check-circle"
                        tone="emerald"
                        :title="__('ไม่มีรายการค้าง')"
                        :description="__('ทุกอย่างเรียบร้อยดี')"
                        compact
                    />
                @else
                    <ul class="space-y-2" data-test="action-items">
                        @foreach ($actionItems as $item)
                            <li>
                                <a href="{{ route('subscriptions.show', $item['subscription']) }}" wire:navigate class="flex items-start gap-3 rounded-lg bg-red-50/60 p-3 ring-1 ring-red-100 hover:bg-red-50">
                                    <flux:icon icon="exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-red-500" />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-900">{{ $item['subscription']->name }}</span>
                                        <span class="block text-xs text-red-700">{{ $item['label'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        {{-- Monthly chart + category breakdown --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <x-ui.section-header
                    :title="__('ค่าใช้จ่ายรายเดือน')"
                    :description="__('ยอดที่ยืนยันแล้วย้อนหลัง 6 เดือน (บาท)')"
                    icon="chart-bar"
                />
                <x-ui.bar-chart
                    :items="$monthlyTotals"
                    :empty-description="__('กราฟจะแสดงเมื่อมีการบันทึกค่าใช้จ่ายจริง')"
                />
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-header
                    :title="__('แยกตามหมวดหมู่')"
                    :description="__('สัดส่วนค่าใช้จ่ายเดือนนี้')"
                    icon="chart-pie"
                />
                <x-ui.donut-chart
                    :segments="$categoryBreakdown"
                    :empty-description="__('จะแสดงเมื่อมีค่าใช้จ่ายในเดือนนี้')"
                />
            </x-ui.card>
        </div>

        {{-- Recent expenses --}}
        <x-ui.card>
            <x-ui.section-header
                :title="__('รายการล่าสุด')"
                :description="__('ค่าใช้จ่ายที่บันทึกล่าสุด')"
                icon="clock"
            />

            @if ($recentExpenses->isEmpty())
                <x-ui.empty-state
                    icon="receipt-percent"
                    tone="cyan"
                    :title="__('ยังไม่มีการบันทึกค่าใช้จ่าย')"
                    :description="__('เมื่อยืนยันยอดตัดบัตรหรือบันทึกค่าใช้จ่าย รายการจะแสดงที่นี่พร้อมสถานะใบเสร็จ')"
                />
            @endif
        </x-ui.card>
    </div>
</x-layouts::app>
