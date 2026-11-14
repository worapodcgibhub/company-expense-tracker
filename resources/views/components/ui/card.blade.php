@props([
    'padding' => true,
])

<section {{ $attributes->class([
    'rounded-2xl bg-white shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70',
    'p-5 sm:p-6' => $padding,
]) }}>
    {{ $slot }}
</section>
