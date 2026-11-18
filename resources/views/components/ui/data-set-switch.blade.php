@props([
    'current', // App\Enums\DataSet
    'query' => [], // other query values to keep when switching
])

@php
    $base = url()->current();
@endphp

<div class="flex flex-col gap-2" data-test="data-set-switch">
    <div class="inline-flex self-start rounded-xl bg-slate-100 p-1 text-sm" role="group" aria-label="ชุดข้อมูล">
        @foreach (\App\Enums\DataSet::cases() as $set)
            <a href="{{ $base.'?'.http_build_query(array_merge($query, $set->isReal() ? [] : ['data' => $set->value])) }}"
               wire:navigate
               @if ($set === $current) aria-current="true" @endif
               data-test="data-set-{{ $set->value }}"
               class="min-h-9 rounded-lg px-3 py-1.5 font-medium transition {{ $set === $current ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                {{ $set->label() }}
            </a>
        @endforeach
    </div>

    @unless ($current->isReal())
        <flux:callout icon="beaker" color="amber" data-test="data-set-notice">
            <flux:callout.text>
                @if ($current === \App\Enums\DataSet::Sample)
                    กำลังแสดง<strong>ข้อมูลตัวอย่าง</strong>เพื่อทดลองระบบ ไม่ใช่ยอดเงินจริงของบริษัท
                @else
                    กำลังแสดง<strong>ข้อมูลจริงรวมกับข้อมูลตัวอย่าง</strong> ยอดเงินจึงไม่ใช่ยอดจริงของบริษัท เลือก "ข้อมูลจริง" เพื่อดูยอดจริง
                @endif
            </flux:callout.text>
        </flux:callout>
    @endunless
</div>
