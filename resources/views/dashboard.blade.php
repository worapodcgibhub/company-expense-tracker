@use('App\Support\ThaiFormat')

@php
    $user = auth()->user();
    $today = now();

    /*
     * Dashboard data. No sample data anywhere. Everything is limited to what
     * this user may see (Staff: only subscriptions they are responsible for).
     * - Subscriptions: amounts are ESTIMATES, totalled per currency.
     * - Actual charges (Phase 2.4): baht totals use the baht actually paid
     *   (THB amount, or the USD charge's amount_thb). USD is never added to
     *   baht; USD charges still waiting for their baht amount are listed
     *   apart. Closed months on the chart show their closing figure.
     * - Receipts (Phase 2.5): "missing receipt" counts charged cycles still
     *   WAITING for a document; "no receipt available" ones are not counted.
     */
    $subscriptions = new \App\Support\SubscriptionOverview($user, $today);
    $charges = new \App\Support\ChargeOverview($user, $today);
    $monthlyEstimate = $subscriptions->monthlyEstimateByCurrency();
    $thisMonth = $charges->monthSummary();

    $summary = [
        'month_total' => $thisMonth['thb_total'],      // minor units (satang)
        'month_missing_thb_count' => $thisMonth['missing_thb_count'],
        'month_missing_thb_usd' => $thisMonth['missing_thb_usd'],
        'recorded_count' => $thisMonth['charge_count'],
        'upcoming_7d_count' => $subscriptions->upcoming(7)->count(),
        'upcoming_7d_split' => $subscriptions->upcomingBySettlement(7),
        'missing_receipt_count' => $charges->waitingForReceiptCount(),
    ];
    $upcomingCharges = $subscriptions->upcoming(30);

    /*
     * Action needed (Phase 3.1e-4). Nothing here is counted as paid: a
     * payment only counts once its actual charge is recorded / confirmed
     * (SubscriptionCharge). Red = past due and not confirmed (or a broken
     * card / method / late entry); amber = due now or soon, not late yet.
     * Each subscription falls in at most one of the payment groups.
     */
    $payUrl = fn ($s) => $user->can('create', [\App\Models\SubscriptionCharge::class, $s]) && $s->canRecordChargeOn($today)
        ? route('charges.create', $s)
        : route('subscriptions.show', $s);
    $makeItem = fn ($s, string $label, string $tone, string $kind) => ['url' => $payUrl($s), 'name' => $s->name, 'label' => $label, 'tone' => $tone, 'kind' => $kind, 'channel' => $s->paymentMethod?->name];
    $autoAwaiting = $subscriptions->autoAwaitingConfirmation();
    $actionItems = $subscriptions->manualOverdue()
        ->map(fn ($s) => $makeItem($s, $s->dueLabel($today).' · ต้องชำระเอง ยังไม่ได้ยืนยันการชำระ', 'red', 'manual-overdue'))
        ->concat($autoAwaiting->filter(fn ($s) => $s->isOverdue($today))->map(fn ($s) => $makeItem($s, $s->dueLabel($today).' · รอยืนยันยอดตัดจริง (Auto)', 'red', 'auto-overdue')))
        ->concat($subscriptions->needingNewCard()->map(fn ($s) => ['url' => route('subscriptions.show', $s), 'name' => $s->name, 'label' => 'บัตรถูกปิดใช้งาน ต้องเปลี่ยนบัตร', 'tone' => 'red', 'kind' => 'card', 'channel' => null]))
        ->concat($subscriptions->needingNewPaymentMethod()->map(fn ($s) => ['url' => route('subscriptions.show', $s), 'name' => $s->name, 'label' => 'ช่องทางชำระเงินถูกปิดใช้งาน ต้องเปลี่ยนช่องทาง', 'tone' => 'red', 'kind' => 'method', 'channel' => null]))
        ->concat($charges->afterCloseUnverified()->map(fn ($c) => ['url' => route('charges.show', $c), 'name' => $c->subscription->name, 'label' => 'รายการย้อนหลังในงวดที่ปิดแล้ว รอฝ่ายบัญชีตรวจ', 'tone' => 'red', 'kind' => 'after-close', 'channel' => null]))
        ->concat($autoAwaiting->reject(fn ($s) => $s->isOverdue($today))->map(fn ($s) => $makeItem($s, 'ถึงกำหนดวันนี้ · รอยืนยันยอดตัดจริง (Auto)', 'amber', 'auto-due')))
        ->concat($subscriptions->manualDueSoon(7)->map(fn ($s) => $makeItem($s, ThaiFormat::date($s->next_charge_date).' · '.$s->dueLabel($today).' · ต้องชำระเอง (Manual)', 'amber', 'manual-due')))
        ->values();
    $missingThb = $charges->missingThb();
    $recentExpenses = $charges->recent(5);
    $categoryBreakdown = $charges->categoryBreakdown();
    $monthlyTotals = $charges->monthlySeries(6);
    $hasClosedMonths = collect($monthlyTotals)->contains('closed', true);

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
                <p class="mt-1 text-sm text-slate-500">{{ __('ภาพรวมค่าใช้จ่ายและกำหนดชำระของบริษัท') }}</p>
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
                :hint="$summary['month_missing_thb_count'] > 0
                    ? ThaiFormat::monthYear($today).' · ยังไม่รวม USD '.$summary['month_missing_thb_count'].' รายการ ('.ThaiFormat::money($summary['month_missing_thb_usd'], 'USD').') ที่รอยอดบาท'
                    : ThaiFormat::monthYear($today)"
                icon="banknotes"
                tone="indigo"
            />
            <x-ui.stat-card
                :title="__('บันทึกจ่ายจริงแล้ว')"
                :value="number_format($summary['recorded_count']).' '.__('รายการ')"
                :hint="__('เดือนนี้ · บันทึกยอดตัดจริงหรือยืนยันการชำระแล้ว')"
                icon="clipboard-document-check"
                tone="cyan"
            />
            <x-ui.stat-card
                :title="__('ใกล้ถึงกำหนดชำระ')"
                :value="number_format($summary['upcoming_7d_count']).' '.__('รายการ')"
                :hint="'ภายใน 7 วัน · ต้องชำระเอง '.$summary['upcoming_7d_split']['manual'].' · ตัดอัตโนมัติ '.$summary['upcoming_7d_split']['auto']"
                icon="calendar-days"
                tone="amber"
            />
            <a href="{{ route('charges.index', ['month' => 'all', 'receipt' => 'waiting']) }}" wire:navigate class="block" data-test="missing-receipt-card">
                <x-ui.stat-card
                    :title="__('ขาดใบเสร็จ')"
                    :value="number_format($summary['missing_receipt_count']).' '.__('รายการ')"
                    :hint="$summary['missing_receipt_count'] > 0 ? __('รอใบเสร็จ ต้องติดตาม (ไม่นับรายการที่ระบุว่าไม่มีใบเสร็จ)') : __('ไม่มีรายการค้าง')"
                    icon="document-magnifying-glass"
                    :tone="$summary['missing_receipt_count'] > 0 ? 'red' : 'emerald'"
                    class="h-full"
                />
            </a>
        </div>

        {{-- Upcoming charges + action needed --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <x-ui.section-header
                    :title="__('กำหนดชำระที่กำลังจะถึง')"
                    :description="__('ค่าใช้จ่ายประจำทุกช่องทางที่ถึงกำหนดภายใน 30 วัน')"
                    icon="calendar-days"
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
                        :title="__('ยังไม่มีกำหนดชำระที่กำลังจะถึง')"
                        :description="__('เมื่อลงทะเบียนค่าใช้จ่ายประจำ เช่น Adobe, ค่า Hosting หรือค่าเช่า Colocation กำหนดชำระถัดไปจะแสดงที่นี่')"
                    />
                @else
                    <ul class="divide-y divide-slate-100" data-test="upcoming-charges">
                        @foreach ($upcomingCharges as $charge)
                            @php $days = $charge->daysUntilCharge($today); @endphp
                            <li>
                                <a href="{{ route('subscriptions.show', $charge) }}" wire:navigate class="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900">{{ $charge->name }}</p>
                                        @php
                                            $channel = $charge->paymentMethod;
                                            $target = $channel?->creditCard?->maskedNumber() ?? $channel?->bankAccount?->maskedNumber();
                                        @endphp
                                        <p class="flex min-w-0 items-center gap-1.5 text-xs text-slate-500" data-test="upcoming-channel">
                                            <span class="truncate">{{ $channel?->name ?? $charge->creditCard?->maskedNumber() ?? '–' }}@if ($target) <span class="font-mono tracking-wider">{{ $target }}</span>@endif · {{ $charge->responsible->name }}</span>
                                            <span @class([
                                                'shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium leading-none',
                                                'bg-indigo-50 text-indigo-700' => ! $charge->isManual(),
                                                'bg-amber-50 text-amber-800' => $charge->isManual(),
                                            ]) data-test="upcoming-settlement">{{ $charge->isManual() ? 'Manual' : 'Auto' }}</span>
                                        </p>
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
                    :description="__('รายการที่ต้องชำระเอง เลยกำหนด หรือรอยืนยันยอดจริง')"
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
                            @php $red = $item['tone'] === 'red'; @endphp
                            <li data-test="action-item-{{ $item['kind'] }}">
                                <a href="{{ $item['url'] }}" wire:navigate @class([
                                    'flex items-start gap-3 rounded-lg p-3 ring-1',
                                    'bg-red-50/60 ring-red-100 hover:bg-red-50' => $red,
                                    'bg-amber-50/60 ring-amber-100 hover:bg-amber-50' => ! $red,
                                ])>
                                    <flux:icon :icon="$red ? 'exclamation-triangle' : 'clock'" @class(['mt-0.5 size-4 shrink-0', 'text-red-500' => $red, 'text-amber-500' => ! $red]) />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-900">{{ $item['name'] }}</span>
                                        <span @class(['block text-xs', 'text-red-700' => $red, 'text-amber-800' => ! $red])>{{ $item['label'] }}</span>
                                        @if ($item['channel'])
                                            <span class="block truncate text-xs text-slate-500">{{ $item['channel'] }}</span>
                                        @endif
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
                    :description="__('ยอดเงินบาทที่ตัดจริงย้อนหลัง 6 เดือน')"
                    icon="chart-bar"
                />
                <x-ui.bar-chart
                    :items="$monthlyTotals"
                    :empty-description="__('กราฟจะแสดงเมื่อมีการบันทึกค่าใช้จ่ายจริง')"
                />
                <p class="mt-3 text-xs text-slate-500">
                    ไม่รวมรายการ USD ที่ยังไม่มียอดเงินบาท
                    @if ($hasClosedMonths) · เดือนที่ปิดงวดแล้วแสดงยอด ณ วันปิดงวด @endif
                </p>
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
                    :description="__('เมื่อบันทึกยอดตัดจริงหรือยืนยันการชำระ รายการจะแสดงที่นี่')"
                />
            @else
                <ul class="divide-y divide-slate-100" data-test="recent-expenses">
                    @foreach ($recentExpenses as $expense)
                        <li>
                            <a href="{{ route('charges.show', $expense) }}" wire:navigate class="flex flex-col gap-1 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900">{{ $expense->subscription->name }}</p>
                                    <p class="truncate text-xs text-slate-500">
                                        {{ $expense->charged_on ? ThaiFormat::date($expense->charged_on) : 'รอบ '.ThaiFormat::date($expense->due_date) }}
                                        · {{ $expense->creditCard?->maskedNumber() ?? $expense->paymentMethod?->name ?? '–' }}
                                        @if ($expense->paymentMethod)
                                            · {{ $expense->paymentMethod->isAuto() ? 'Auto' : 'Manual' }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1 sm:items-end">
                                    <p class="text-sm font-semibold text-slate-900">
                                        {{ $expense->amountLabel() }}
                                        @if ($expense->isCharged() && $expense->amount_thb !== null)
                                            <span class="font-normal text-slate-500">({{ ThaiFormat::money($expense->amount_thb) }})</span>
                                        @endif
                                    </p>
                                    <div class="flex flex-wrap gap-1 sm:justify-end"><x-charge-badges :charge="$expense" /></div>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('charges.index') }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700">ดูรายการตัดเงินทั้งหมด →</a>
            @endif
        </x-ui.card>

        {{-- USD charges still waiting for the baht amount (not in any baht total) --}}
        @if ($missingThb->isNotEmpty())
            <x-ui.card data-test="missing-thb">
                <x-ui.section-header
                    :title="__('รายการ USD ที่รอยอดเงินบาท')"
                    :description="__('ยังไม่นับรวมในยอดเงินบาท กรอกยอดบาทจากใบแจ้งยอดบัตรเมื่อได้รับ')"
                    icon="currency-dollar"
                />
                <ul class="divide-y divide-slate-100">
                    @foreach ($missingThb as $pending)
                        <li>
                            <a href="{{ route('charges.show', $pending) }}" wire:navigate class="flex items-center justify-between gap-3 py-3 hover:bg-slate-50">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900">{{ $pending->subscription->name }}</span>
                                    <span class="block text-xs text-slate-500">ตัดเงิน {{ ThaiFormat::date($pending->charged_on ?? $pending->due_date) }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold text-amber-700">{{ $pending->amountLabel() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</x-layouts::app>
