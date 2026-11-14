@props([
    // list of ['label' => string, 'value' => int (minor units), 'current' => bool]
    'items' => [],
    'currency' => 'THB',
    'emptyTitle' => 'ยังไม่มีข้อมูลค่าใช้จ่าย',
    'emptyDescription' => null,
])

@php
    $items = collect($items);
    $max = max(1, (int) $items->max('value'));
    $hasData = $items->sum('value') > 0;
@endphp

<div {{ $attributes->class('relative') }}>
    {{-- Accessible data table for screen readers --}}
    <table class="sr-only">
        <caption>{{ __('ค่าใช้จ่ายรายเดือน') }}</caption>
        <tbody>
            @foreach ($items as $item)
                <tr><th scope="row">{{ $item['label'] }}</th><td>{{ \App\Support\ThaiFormat::money((int) $item['value'], $currency) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div aria-hidden="true" class="relative h-52 sm:h-60">
        {{-- Grid lines --}}
        <div class="absolute inset-0 flex flex-col justify-between pb-7">
            @foreach (range(1, 4) as $line)
                <div class="border-t border-dashed border-slate-200"></div>
            @endforeach
        </div>

        {{-- Bars --}}
        <div class="absolute inset-0 flex items-end gap-2 pb-7 sm:gap-4">
            @foreach ($items as $item)
                @php $height = $hasData ? max(2, round(((int) $item['value'] / $max) * 100)) : 0; @endphp
                <div class="group relative flex h-full flex-1 flex-col items-center justify-end">
                    @if ($hasData)
                        <div class="pointer-events-none absolute -top-1 z-10 -translate-y-full rounded-lg bg-slate-900 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-lg transition group-hover:opacity-100">
                            {{ \App\Support\ThaiFormat::money((int) $item['value'], $currency) }}
                        </div>
                    @endif
                    <div
                        class="w-full max-w-12 rounded-t-lg bg-linear-to-t transition-all duration-500 {{ ! empty($item['current']) ? 'from-indigo-600 to-cyan-400 shadow-lg shadow-indigo-500/25' : 'from-indigo-200 to-indigo-300 group-hover:from-indigo-400 group-hover:to-violet-400' }}"
                        style="height: {{ $height }}%"
                    ></div>
                </div>
            @endforeach
        </div>

        {{-- Month labels --}}
        <div class="absolute inset-x-0 bottom-0 flex gap-2 sm:gap-4">
            @foreach ($items as $item)
                <span class="flex-1 truncate text-center text-xs {{ ! empty($item['current']) ? 'font-semibold text-indigo-600' : 'text-slate-500' }}">{{ $item['label'] }}</span>
            @endforeach
        </div>
    </div>

    @unless ($hasData)
        <div class="absolute inset-0 bottom-7 flex items-center justify-center">
            <div class="rounded-xl bg-white/90 px-5 py-4 text-center shadow-sm ring-1 ring-slate-200 backdrop-blur" data-empty-state>
                <p class="text-sm font-semibold text-slate-800">{{ $emptyTitle }}</p>
                @if ($emptyDescription)
                    <p class="mt-1 max-w-xs text-xs text-slate-500">{{ $emptyDescription }}</p>
                @endif
            </div>
        </div>
    @endunless
</div>
