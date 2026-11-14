@props([
    'title',
    'value',
    'hint' => null,
    'icon' => 'banknotes',
    'tone' => 'indigo', // indigo | cyan | emerald | amber | red
])

@php
    $tones = [
        'indigo' => ['chip' => 'from-indigo-500 to-violet-600 shadow-indigo-500/30', 'glow' => 'bg-indigo-500/10'],
        'cyan' => ['chip' => 'from-cyan-400 to-sky-600 shadow-cyan-500/30', 'glow' => 'bg-cyan-500/10'],
        'emerald' => ['chip' => 'from-emerald-400 to-teal-600 shadow-emerald-500/30', 'glow' => 'bg-emerald-500/10'],
        'amber' => ['chip' => 'from-amber-400 to-orange-500 shadow-amber-500/30', 'glow' => 'bg-amber-500/10'],
        'red' => ['chip' => 'from-rose-500 to-red-600 shadow-red-500/30', 'glow' => 'bg-red-500/10'],
    ];
    $style = $tones[$tone] ?? $tones['indigo'];
@endphp

<div {{ $attributes->class('relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-md') }}>
    <div aria-hidden="true" class="pointer-events-none absolute -top-10 -right-10 size-32 rounded-full blur-2xl {{ $style['glow'] }}"></div>

    <div class="relative flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-medium text-slate-500">{{ $title }}</p>
            <p class="mt-2 truncate text-2xl font-bold tracking-tight text-slate-900 tabular-nums sm:text-[1.7rem]">{{ $value }}</p>
        </div>
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-linear-to-br text-white shadow-lg {{ $style['chip'] }}">
            <flux:icon :icon="$icon" class="size-6" />
        </span>
    </div>

    @if ($hint)
        <p class="relative mt-3 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
