@props([
    // list of ['label' => string, 'value' => int (minor units), 'color' => '#hex']
    'segments' => [],
    'currency' => 'THB',
    'emptyTitle' => 'ยังไม่มีข้อมูลหมวดหมู่',
    'emptyDescription' => null,
])

@php
    $segments = collect($segments)->filter(fn ($s) => (int) $s['value'] > 0)->values();
    $total = (int) $segments->sum('value');
    $radius = 15.9155; // circumference = 100, so dash lengths are percentages
    $offset = 25; // start at 12 o'clock
@endphp

@if ($total === 0)
    <x-ui.empty-state icon="chart-pie" :title="$emptyTitle" :description="$emptyDescription" tone="cyan" compact {{ $attributes }} />
@else
    <div {{ $attributes->class('flex flex-col items-center gap-6 sm:flex-row lg:flex-col xl:flex-row') }}>
        <div class="relative size-40 shrink-0">
            <svg viewBox="0 0 36 36" class="size-full -rotate-0" role="img" aria-label="{{ __('สัดส่วนค่าใช้จ่ายตามหมวดหมู่') }}">
                <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke="#f1f5f9" stroke-width="4" />
                @foreach ($segments as $segment)
                    @php $percent = $segment['value'] / $total * 100; @endphp
                    <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke="{{ $segment['color'] }}" stroke-width="4"
                        stroke-dasharray="{{ $percent }} {{ 100 - $percent }}" stroke-dashoffset="{{ $offset }}" />
                    @php $offset -= $percent; @endphp
                @endforeach
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-xs text-slate-500">{{ __('รวม') }}</span>
                <span class="text-sm font-bold text-slate-900 tabular-nums">{{ \App\Support\ThaiFormat::money($total, $currency) }}</span>
            </div>
        </div>

        <ul class="w-full space-y-2.5">
            @foreach ($segments as $segment)
                <li class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $segment['color'] }}"></span>
                        <span class="truncate text-slate-700">{{ $segment['label'] }}</span>
                    </span>
                    <span class="shrink-0 font-medium text-slate-900 tabular-nums">{{ round($segment['value'] / $total * 100) }}%</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
