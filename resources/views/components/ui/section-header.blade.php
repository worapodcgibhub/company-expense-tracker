@props([
    'title',
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->class('mb-5 flex flex-wrap items-start justify-between gap-3') }}>
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100">
                <flux:icon :icon="$icon" class="size-5" />
            </span>
        @endif
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
            @if ($description)
                <p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
