<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-svh bg-slate-50 font-sans text-slate-800 antialiased">
        <div class="grid min-h-svh lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">

            {{-- Brand panel (desktop / large tablet) --}}
            <aside class="bg-brand-gradient relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between xl:p-16">
                <div aria-hidden="true" class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-white/10 blur-3xl"></div>
                <div aria-hidden="true" class="pointer-events-none absolute -bottom-32 -left-20 size-[28rem] rounded-full bg-cyan-300/20 blur-3xl"></div>
                <div aria-hidden="true" class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px]"></div>

                <a href="{{ route('home') }}" class="relative flex items-center gap-3" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/30 backdrop-blur">
                        <x-app-logo-icon class="size-6 text-white" />
                    </span>
                    <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
                </a>

                <div class="relative max-w-lg">
                    <p class="mb-4 inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium ring-1 ring-white/25">
                        <span class="size-1.5 rounded-full bg-emerald-300"></span>
                        {{ __('ระบบภายในบริษัท') }}
                    </p>
                    <h2 class="text-4xl leading-tight font-bold tracking-tight xl:text-5xl">
                        {{ __('ติดตามทุกค่าใช้จ่าย') }}<br>
                        <span class="text-cyan-200">{{ __('ไม่พลาดทุกรอบตัดบัตร') }}</span>
                    </h2>
                    <p class="mt-5 text-base leading-relaxed text-indigo-100">
                        {{ __('รู้ล่วงหน้าว่าบริการไหนจะถูกตัดเงินเมื่อไร ยืนยันยอดจริงได้ทันที และไม่มีใบเสร็จตกหล่นก่อนสรุปยอดประจำเดือน') }}
                    </p>

                    <ul class="mt-10 space-y-4">
                        @foreach ([
                            ['icon' => 'calendar-days', 'title' => __('แจ้งเตือนก่อนถึงรอบตัดบัตร'), 'text' => __('ล่วงหน้า 7, 3 และ 1 วัน')],
                            ['icon' => 'check-badge', 'title' => __('ยืนยันยอดที่ถูกตัดจริง'), 'text' => __('รองรับทั้งเงินบาทและดอลลาร์')],
                            ['icon' => 'document-check', 'title' => __('ติดตามใบเสร็จที่ยังขาด'), 'text' => __('ฝ่ายบัญชีไม่ต้องแก้งานย้อนหลัง')],
                        ] as $feature)
                            <li class="flex items-start gap-4">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25">
                                    <flux:icon :icon="$feature['icon']" class="size-5 text-white" />
                                </span>
                                <span>
                                    <span class="block font-semibold">{{ $feature['title'] }}</span>
                                    <span class="block text-sm text-indigo-100">{{ $feature['text'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative text-sm text-indigo-100/80">
                    © {{ now()->year + 543 }} {{ config('app.name') }}
                </p>
            </aside>

            {{-- Form side --}}
            <main class="relative flex flex-col items-center justify-center px-4 py-10 sm:px-8">
                {{-- Mobile / tablet gradient header --}}
                <div aria-hidden="true" class="bg-brand-gradient absolute inset-x-0 top-0 h-64 rounded-b-[2.5rem] lg:hidden"></div>

                <div class="relative z-10 w-full max-w-md">
                    <a href="{{ route('home') }}" class="mb-8 flex flex-col items-center gap-3 text-white lg:hidden" wire:navigate>
                        <span class="flex size-14 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/30 backdrop-blur">
                            <x-app-logo-icon class="size-8 text-white" />
                        </span>
                        <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
                    </a>

                    <div class="rounded-2xl bg-white p-6 shadow-xl shadow-indigo-900/10 ring-1 ring-slate-200/70 sm:p-8">
                        {{ $slot }}
                    </div>

                    <p class="mt-6 text-center text-xs text-slate-500">
                        {{ __('สำหรับพนักงานที่ได้รับสิทธิ์เท่านั้น หากต้องการบัญชีผู้ใช้ กรุณาติดต่อผู้ดูแลระบบ') }}
                    </p>
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
