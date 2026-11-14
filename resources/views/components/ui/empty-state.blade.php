@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
    'tone' => 'indigo', // indigo | cyan | emerald | amber
    'compact' => false,
])

@php
    $tones = [
        'indigo' => 'bg-indigo-50 text-indigo-500 ring-indigo-100',
        'cyan' => 'bg-cyan-50 text-cyan-600 ring-cyan-100',
        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        'amber' => 'bg-amber-50 text-amber-600 ring-amber-100',
    ];
@endphp

<div {{ $attributes->class([
    'flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 text-center',
    'px-6 py-10' => ! $compact,
    'px-4 py-6' => $compact,
]) }} data-empty-state>
    <span class="relative mb-4 flex size-14 items-center justify-center rounded-2xl ring-1 {{ $tones[$tone] ?? $tones['indigo'] }}">
        <flux:icon :icon="$icon" class="size-7" />
    </span>
    <p class="text-sm font-semibold text-slate-800">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
