@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1.5 text-center">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $title }}</h1>
    <p class="text-sm text-slate-500">{{ $description }}</p>
</div>
