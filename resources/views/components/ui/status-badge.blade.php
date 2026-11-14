@props([
    'tone' => 'indigo', // indigo | cyan | emerald | amber | red | slate
    'dot' => true,
])

@php
    $tones = [
        'indigo' => ['badge' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/15', 'dot' => 'bg-indigo-500'],
        'cyan' => ['badge' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/15', 'dot' => 'bg-cyan-500'],
        'emerald' => ['badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15', 'dot' => 'bg-emerald-500'],
        'amber' => ['badge' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'dot' => 'bg-amber-500'],
        'red' => ['badge' => 'bg-red-50 text-red-700 ring-red-600/15', 'dot' => 'bg-red-500'],
        'slate' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-500/15', 'dot' => 'bg-slate-400'],
    ];
    $style = $tones[$tone] ?? $tones['indigo'];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset whitespace-nowrap '.$style['badge']) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $style['dot'] }}"></span>
    @endif
    {{ $slot }}
</span>
