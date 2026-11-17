<?php

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Rules\NoFullCardNumber;
use App\Support\BillingSchedule;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Subscription detail. Viewing follows SubscriptionPolicy::view (Staff only
 * their own). Status changes are Owner only and always go through
 * Subscription::changeStatus(), which records who/when/why, so pausing or
 * cancelling never loses history. Staff responsible for the subscription
 * may edit its notes — and only its notes — through saveNotes().
 */
new #[Title('Subscription')] class extends Component {
    #[Locked]
    public int $subscriptionId;

    public string $notes = '';

    #[Locked]
    public string $statusAction = '';

    public string $reason = '';

    public string $resume_date = '';

    public function mount(Subscription $subscription): void
    {
        $this->authorize('view', $subscription);

        $this->subscriptionId = $subscription->id;
    }

    #[Computed]
    public function subscription(): Subscription
    {
        return Subscription::with(['creditCard', 'paymentMethod.creditCard', 'paymentMethod.bankAccount', 'responsible', 'creator', 'updater'])->findOrFail($this->subscriptionId);
    }

    /**
     * @return Collection<int, \App\Models\SubscriptionStatusChange>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->subscription->statusChanges()->with('changer')->latest('id')->get();
    }

    /**
     * Actual charges, newest cycle first.
     *
     * @return Collection<int, \App\Models\SubscriptionCharge>
     */
    #[Computed]
    public function charges(): Collection
    {
        return $this->subscription->charges()->with('recorder')->withCurrentReceiptCount()->orderByDesc('due_date')->get();
    }

    /**
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function schedule(): array
    {
        $s = $this->subscription;

        return BillingSchedule::upcoming($s->next_charge_date, $s->billing_cycle, $s->billing_day, 6);
    }

    public function openNotes(): void
    {
        $this->authorize('updateNotes', $this->subscription);

        $this->notes = $this->subscription->notes ?? '';
        $this->resetValidation();

        Flux::modal('notes-form')->show();
    }

    public function saveNotes(): void
    {
        $subscription = $this->subscription;
        $this->authorize('updateNotes', $subscription);

        $this->notes = trim($this->notes);

        $validated = $this->validate(
            ['notes' => ['nullable', 'string', 'max:2000', new NoFullCardNumber]],
            [],
            ['notes' => 'หมายเหตุ'],
        );

        // Only `notes` (and who changed it) is written — nothing else.
        $subscription->forceFill([
            'notes' => $validated['notes'] === '' || $validated['notes'] === null ? null : $validated['notes'],
            'updated_by' => auth()->id(),
        ])->save();

        unset($this->subscription);

        Flux::modal('notes-form')->close();
        Flux::toast(variant: 'success', text: 'บันทึกหมายเหตุแล้ว');
    }

    /**
     * Pause or cancel: asks for an optional reason first.
     */
    public function openStatusChange(string $action): void
    {
        // Permission first (Owner only), then whether the action makes sense.
        $this->authorize('update', $this->subscription);
        abort_unless(in_array($action, ['pause', 'cancel'], true), 404);
        $this->authorize($action, $this->subscription);

        $this->statusAction = $action;
        $this->reason = '';
        $this->resetValidation();

        Flux::modal('status-form')->show();
    }

    public function confirmStatusChange(): void
    {
        $subscription = $this->subscription;
        $action = $this->statusAction;

        $this->authorize('update', $subscription);
        abort_unless(in_array($action, ['pause', 'cancel'], true), 404);
        $this->authorize($action, $subscription);

        $validated = $this->validate(['reason' => ['nullable', 'string', 'max:500', new NoFullCardNumber]], [], ['reason' => 'เหตุผล']);

        $subscription->changeStatus(
            $action === 'pause' ? SubscriptionStatus::Paused : SubscriptionStatus::Cancelled,
            auth()->user(),
            $validated['reason'],
        );

        $this->statusAction = '';
        unset($this->subscription, $this->history);

        Flux::modal('status-form')->close();
        Flux::toast(variant: 'success', text: $action === 'pause' ? 'พัก Subscription แล้ว' : 'ยกเลิก Subscription แล้ว');
    }

    /**
     * Resume (from paused) or reactivate (from cancelled): suggests the first
     * charge date on the schedule that is not in the past.
     */
    public function openResume(): void
    {
        $subscription = $this->subscription;
        $this->authorize($this->resumeAbility($subscription), $subscription);

        $this->resume_date = BillingSchedule::firstOnOrAfter(
            $subscription->next_charge_date, $subscription->billing_cycle, $subscription->billing_day, CarbonImmutable::today(),
        )->format('Y-m-d');
        $this->resetValidation();

        Flux::modal('resume-form')->show();
    }

    public function confirmResume(): void
    {
        $subscription = $this->subscription;
        $this->authorize($this->resumeAbility($subscription), $subscription);

        $today = CarbonImmutable::today();

        $this->validate(['resume_date' => ['bail', 'required', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($subscription, $today) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            if ($date === null) {
                return;
            }

            if ($date->lessThan($today)) {
                $fail('วันตัดบัตรครั้งถัดไปต้องเป็นวันนี้หรือหลังจากนี้');
            } elseif ($date->greaterThan($today->addYears(2))) {
                $fail('วันตัดบัตรครั้งถัดไปต้องไม่เกิน 2 ปีจากวันนี้');
            } elseif (! BillingSchedule::matchesBillingDay($date, $subscription->billing_day)) {
                $fail('วันที่นี้ไม่ตรงกับวันตัดตามรอบ (ทุกวันที่ '.$subscription->billing_day.') ถ้าวันตัดเปลี่ยน ให้แก้ไขข้อมูล Subscription');
            }
        }]], [], ['resume_date' => 'วันตัดบัตรครั้งถัดไป']);

        if (! $subscription->creditCard->isActive()) {
            $this->addError('resume_date', 'บัตรที่ผูกไว้ถูกปิดใช้งานแล้ว กรุณาแก้ไขให้ใช้บัตรที่ยังใช้งานอยู่ก่อน');

            return;
        }

        if ($subscription->needsNewPaymentMethod()) {
            $this->addError('resume_date', 'ช่องทางชำระเงินที่ผูกไว้ถูกปิดใช้งานแล้ว กรุณาแก้ไขให้ใช้ช่องทางที่ยังใช้งานอยู่ก่อน');

            return;
        }

        $subscription->changeStatus(
            SubscriptionStatus::Active,
            auth()->user(),
            null,
            CarbonImmutable::createFromFormat('!Y-m-d', $this->resume_date),
        );

        unset($this->subscription, $this->history, $this->schedule);

        Flux::modal('resume-form')->close();
        Flux::toast(variant: 'success', text: 'กลับมาใช้งาน Subscription แล้ว');
    }

    private function resumeAbility(Subscription $subscription): string
    {
        return $subscription->isCancelled() ? 'reactivate' : 'resume';
    }
}; ?>

@php
    $s = $this->subscription;
    $user = auth()->user();
    $overdue = $s->isOverdue();
    $canManage = $user->can('update', $s);
@endphp

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    {{-- Header --}}
    <header class="flex flex-col gap-4">
        <a href="{{ route('subscriptions.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> กลับไปรายการ Subscription
        </a>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $s->name }}</h1>
                    <x-ui.status-badge :tone="$s->status->tone()" data-test="status-badge">{{ $s->status->label() }}</x-ui.status-badge>
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ $s->vendor }} · {{ $s->category->label() }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('update', $s)
                    <flux:button icon="pencil-square" :href="route('subscriptions.edit', $s)" wire:navigate data-test="edit-button">แก้ไข</flux:button>
                @endcan
                @if (! $canManage)
                    @can('updateNotes', $s)
                        <flux:button icon="pencil-square" wire:click="openNotes" data-test="edit-notes-button">แก้ไขหมายเหตุ</flux:button>
                    @endcan
                @endif
                @can('pause', $s)
                    <flux:button icon="pause" wire:click="openStatusChange('pause')" data-test="pause-button">พักชั่วคราว</flux:button>
                @endcan
                @can('resume', $s)
                    <flux:button icon="play" variant="primary" wire:click="openResume" data-test="resume-button">กลับมาใช้งาน</flux:button>
                @endcan
                @can('reactivate', $s)
                    <flux:button icon="arrow-path" wire:click="openResume" data-test="reactivate-button">เปิดใช้อีกครั้ง</flux:button>
                @endcan
                @can('cancel', $s)
                    <flux:button icon="x-circle" variant="danger" wire:click="openStatusChange('cancel')" data-test="cancel-button">ยกเลิก</flux:button>
                @endcan
            </div>
        </div>
    </header>

    @if ($s->needsNewCard())
        <flux:callout icon="exclamation-triangle" color="red" data-test="card-deactivated-warning">
            <flux:callout.heading>บัตรที่ใช้ชำระถูกปิดใช้งานแล้ว</flux:callout.heading>
            <flux:callout.text>การตัดเงินครั้งถัดไปอาจไม่ผ่านและบริการอาจหยุด {{ $canManage ? 'กรุณาแก้ไขให้ใช้บัตรที่ยังใช้งานอยู่' : 'กรุณาแจ้งเจ้าของบริษัทให้เปลี่ยนบัตร' }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($s->needsNewPaymentMethod())
        <flux:callout icon="exclamation-triangle" color="red" data-test="method-deactivated-warning">
            <flux:callout.heading>ช่องทางชำระเงินถูกปิดใช้งานแล้ว</flux:callout.heading>
            <flux:callout.text>ช่องทาง "{{ $s->paymentMethod->name }}" (หรือบัตร/บัญชีธนาคารของช่องทางนี้) ถูกปิดใช้งาน การชำระครั้งถัดไปอาจไม่ผ่านและบริการอาจหยุด {{ $canManage ? 'กรุณาแก้ไขให้ใช้บัตรหรือช่องทางที่ยังใช้งานอยู่' : 'กรุณาแจ้งเจ้าของบริษัทให้เปลี่ยนช่องทางชำระเงิน' }} ระบบไม่เปลี่ยนช่องทางให้เอง และประวัติเดิมยังอยู่ครบ</flux:callout.text>
        </flux:callout>
    @endif

    @if (! $s->isActive())
        <flux:callout icon="information-circle" color="amber">
            <flux:callout.text>
                สถานะ "{{ $s->status->label() }}" มีผลในระบบนี้เท่านั้น <strong>ไม่ได้หยุดการตัดเงินที่ผู้ให้บริการ</strong> ต้องยกเลิกหรือพักที่ผู้ให้บริการด้วย
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Billing --}}
        <x-ui.card class="lg:col-span-2">
            <x-ui.section-header title="การชำระเงิน" icon="credit-card" />
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-slate-500">ยอดประมาณการ</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $s->amountLabel() }}</dd>
                    <dd class="text-xs text-slate-500">ยอดตัดจริงดูได้ที่ "ประวัติการตัดเงินจริง" ด้านล่าง</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">วันตัดบัตรครั้งถัดไป</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ ThaiFormat::date($s->next_charge_date) }}</dd>
                    @if ($s->isActive())
                        <dd @class(['text-sm font-medium', 'text-red-600' => $overdue, 'text-slate-500' => ! $overdue])>{{ $s->dueLabel() }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-sm text-slate-500">รอบบิล</dt>
                    <dd class="mt-0.5 text-slate-900">
                        {{ $s->billing_cycle->label() }} · {{ $s->billing_day === 31 ? 'ทุกวันที่ 31 / สิ้นเดือน' : 'ทุกวันที่ '.$s->billing_day }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">บัตรที่ใช้ชำระ</dt>
                    <dd class="mt-0.5 text-slate-900">
                        {{ $s->creditCard->name }} <span class="font-mono tracking-wider">{{ $s->creditCard->maskedNumber() }}</span>
                        @unless ($s->creditCard->isActive())
                            <x-ui.status-badge tone="red" :dot="false">ปิดใช้งาน</x-ui.status-badge>
                        @endunless
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">ผู้รับผิดชอบ</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $s->responsible->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">แจ้งเตือนล่วงหน้า</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $s->remind_days_before }} วัน <span class="text-xs text-slate-500">(ใช้ในระยะถัดไป)</span></dd>
                </div>
            </dl>

            <div class="mt-6 border-t border-slate-100 pt-4">
                <p class="text-sm font-medium text-slate-700">หมายเหตุ</p>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600" data-test="notes">{{ $s->notes ?: '–' }}</p>
            </div>
        </x-ui.card>

        {{-- Schedule --}}
        <x-ui.card>
            <x-ui.section-header title="รอบตัดบัตรล่วงหน้า" description="คำนวณจากวันตัดตามรอบ" icon="calendar-days" />
            @if ($s->isCancelled())
                <p class="text-sm text-slate-500">ยกเลิกแล้ว ไม่มีรอบตัดบัตรถัดไป</p>
            @else
                <ol class="space-y-2" data-test="schedule">
                    @foreach ($this->schedule as $i => $date)
                        <li @class(['flex items-center justify-between rounded-lg px-3 py-2 text-sm', 'bg-indigo-50 font-medium text-indigo-700' => $i === 0, 'text-slate-600' => $i > 0])>
                            <span>{{ ThaiFormat::date($date) }}</span>
                            @if ($i === 0)<span class="text-xs">ครั้งถัดไป</span>@endif
                        </li>
                    @endforeach
                </ol>
                @if ($s->status === SubscriptionStatus::Paused)
                    <p class="mt-3 text-xs text-slate-500">พักอยู่: วันจะถูกตั้งใหม่ตอนกลับมาใช้งาน</p>
                @endif
            @endif
        </x-ui.card>
    </div>

    {{-- Actual charges (SubscriptionCharge) --}}
    <x-ui.card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-ui.section-header title="ประวัติการตัดเงินจริง" description="ยอดที่บัตรถูกตัดจริงในแต่ละรอบ" icon="receipt-percent" class="!mb-0" />
            @can('create', [\App\Models\SubscriptionCharge::class, $s])
                @if ($s->canRecordChargeOn())
                    <flux:button icon="plus" variant="primary" size="sm" :href="route('charges.create', $s)" wire:navigate data-test="record-charge-button">
                        บันทึกยอดตัดจริง (รอบ {{ ThaiFormat::date($s->next_charge_date) }})
                    </flux:button>
                @else
                    <flux:text class="text-xs" data-test="record-charge-later">บันทึกรอบ {{ ThaiFormat::date($s->next_charge_date) }} ได้ตั้งแต่ {{ ThaiFormat::date($s->next_charge_date->subDays(\App\Models\SubscriptionCharge::RECORD_AHEAD_DAYS)) }}</flux:text>
                @endif
            @endcan
        </div>

        @if ($this->charges->isEmpty())
            <x-ui.empty-state icon="receipt-percent" tone="cyan" title="ยังไม่มีการบันทึกยอดตัดจริง" description="เมื่อบัตรถูกตัดเงินแล้ว ให้บันทึกยอดจริงของรอบนั้น ระบบจะเลื่อนวันตัดบัตรครั้งถัดไปให้อัตโนมัติ" compact />
        @else
            <ul class="divide-y divide-slate-100" data-test="charge-list">
                @foreach ($this->charges as $charge)
                    <li wire:key="charge-{{ $charge->id }}">
                        <a href="{{ route('charges.show', $charge) }}" wire:navigate class="flex flex-col gap-2 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 text-sm">
                                <p class="font-medium text-slate-900">รอบ {{ ThaiFormat::date($charge->due_date) }}</p>
                                <p class="text-slate-500">
                                    {{ $charge->charged_on ? 'ตัดเงิน '.ThaiFormat::date($charge->charged_on) : $charge->outcome->label() }}
                                    · บันทึกโดย {{ $charge->recorder?->name ?? '–' }} {{ ThaiFormat::date($charge->created_at) }}
                                </p>
                            </div>
                            <div class="flex flex-col gap-1 sm:items-end">
                                <p class="font-semibold text-slate-900">
                                    {{ $charge->amountLabel() }}
                                    @if ($charge->isCharged() && $charge->amount_thb !== null)
                                        <span class="text-sm font-normal text-slate-500">({{ ThaiFormat::money($charge->amount_thb) }})</span>
                                    @endif
                                </p>
                                <div class="flex flex-wrap gap-2 sm:justify-end"><x-charge-badges :charge="$charge" /></div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- Status history --}}
    <x-ui.card>
        <x-ui.section-header title="ประวัติสถานะ" icon="clock" />
        <ol class="space-y-3" data-test="status-history">
            @foreach ($this->history as $change)
                <li class="flex flex-col gap-0.5 text-sm sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <span class="font-medium text-slate-800">
                            {{ $change->from_status ? $change->from_status->label().' → '.$change->to_status->label() : 'เพิ่มเข้าระบบ ('.$change->to_status->label().')' }}
                        </span>
                        @if ($change->reason)
                            <span class="block text-slate-600">เหตุผล: {{ $change->reason }}</span>
                        @endif
                    </div>
                    <span class="text-slate-500">{{ $change->changer?->name ?? '–' }} · {{ ThaiFormat::date($change->created_at) }} {{ $change->created_at->format('H:i') }}</span>
                </li>
            @endforeach
        </ol>
        <p class="mt-4 text-xs text-slate-500">
            เพิ่มโดย {{ $s->creator?->name ?? '–' }} · แก้ไขล่าสุดโดย {{ $s->updater?->name ?? '–' }} ({{ ThaiFormat::date($s->updated_at) }})
        </p>
    </x-ui.card>

    {{-- Notes (Staff responsible) --}}
    @can('updateNotes', $s)
        <flux:modal name="notes-form" class="w-full max-w-md">
            <form wire:submit="saveNotes" class="space-y-5">
                <flux:heading size="lg">แก้ไขหมายเหตุ</flux:heading>
                <flux:textarea wire:model="notes" label="หมายเหตุ" rows="4" maxlength="2000" />
                <flux:text class="text-xs">แก้ได้เฉพาะหมายเหตุ ยอดเงิน บัตร รอบบิล วันที่ และสถานะ แก้ได้โดยเจ้าของบริษัท</flux:text>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">บันทึก</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @if ($canManage)
        {{-- Pause / cancel --}}
        <flux:modal name="status-form" class="w-full max-w-md">
            <form wire:submit="confirmStatusChange" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $statusAction === 'cancel' ? 'ยกเลิก Subscription' : 'พัก Subscription ชั่วคราว' }}</flux:heading>
                    <flux:text class="mt-1">ข้อมูลและประวัติทั้งหมดยังอยู่ครบ</flux:text>
                </div>
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.text>การเปลี่ยนสถานะในระบบนี้ <strong>ไม่ได้หยุดการตัดเงินที่ผู้ให้บริการ</strong> ต้อง{{ $statusAction === 'cancel' ? 'ยกเลิก' : 'พัก' }}ที่ผู้ให้บริการด้วย</flux:callout.text>
                </flux:callout>
                <flux:input wire:model="reason" label="เหตุผล (ไม่บังคับ)" maxlength="500" autocomplete="off" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ไม่ใช่ตอนนี้</flux:button></flux:modal.close>
                    <flux:button type="submit" :variant="$statusAction === 'cancel' ? 'danger' : 'primary'" class="w-full sm:w-auto">ยืนยัน</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Resume / reactivate --}}
        <flux:modal name="resume-form" class="w-full max-w-md">
            <form wire:submit="confirmResume" class="space-y-5">
                <div>
                    <flux:heading size="lg">กลับมาใช้งาน</flux:heading>
                    <flux:text class="mt-1">ตรวจวันตัดบัตรครั้งถัดไป ระบบเลือกรอบถัดไปที่ยังไม่ผ่านมาให้แล้ว</flux:text>
                </div>
                <flux:input type="date" wire:model="resume_date" label="วันตัดบัตรครั้งถัดไป" required />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ยกเลิก</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">ยืนยัน</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
