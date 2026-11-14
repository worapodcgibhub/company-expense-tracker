@use('App\Support\ThaiFormat')

@php
    $user = auth()->user();
    $today = now();

    /*
     * Dashboard data.
     * The expense tables do not exist yet (Phase 2–5), so these are the system's
     * real current values: nothing recorded → zero / empty. No sample data.
     * Each value will be replaced by a database query as its phase ships.
     */
    $summary = [
        'month_total' => 0,      // minor units (satang)
        'recorded_count' => 0,
        'upcoming_7d_count' => 0,
        'missing_receipt_count' => 0,
    ];
    $upcomingCharges = collect();
    $actionItems = collect();
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

                @if ($upcomingCharges->isEmpty())
                    <x-ui.empty-state
                        icon="calendar"
                        :title="__('ยังไม่มีรอบตัดบัตรที่กำลังจะถึง')"
                        :description="__('เมื่อลงทะเบียนค่าใช้จ่ายประจำ เช่น Adobe, Microsoft 365 หรือค่า Hosting รอบตัดเงินถัดไปจะแสดงที่นี่')"
                    />
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
