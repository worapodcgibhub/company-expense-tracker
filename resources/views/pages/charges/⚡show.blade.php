<?php

use App\Enums\ChargeRevisionAction;
use App\Models\CreditCard;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionChargeRevision;
use App\Rules\NoFullCardNumber;
use App\Support\ThaiFormat;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One actual charge with its full change history.
 * Viewing follows SubscriptionChargePolicy::view (Staff only charges of
 * their own subscriptions). The accountant verifies here; the Owner opens
 * the correction form. Nothing can be deleted.
 */
new #[Title('รายการตัดเงิน')] class extends Component {
    #[Locked]
    public int $chargeId;

    public string $unverify_reason = '';

    public function mount(SubscriptionCharge $charge): void
    {
        $this->authorize('view', $charge);

        $this->chargeId = $charge->id;
    }

    #[Computed]
    public function charge(): SubscriptionCharge
    {
        return SubscriptionCharge::with(['subscription', 'creditCard', 'recorder', 'updater', 'verifier'])->findOrFail($this->chargeId);
    }

    /**
     * @return Collection<int, SubscriptionChargeRevision>
     */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->charge->revisions()->with('changer')->latest('id')->get();
    }

    /**
     * Cards mentioned in the history, by id (for "บัตร: A → B" lines).
     *
     * @return array<int, CreditCard>
     */
    #[Computed]
    public function historyCards(): array
    {
        $ids = [$this->charge->credit_card_id];

        foreach ($this->revisions as $revision) {
            foreach ((array) ($revision->diff['credit_card_id'] ?? []) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return CreditCard::query()->whereIn('id', array_unique($ids))->get()->keyBy('id')->all();
    }

    public function verify(): void
    {
        $charge = $this->charge;
        $this->authorize('verify', $charge);

        $charge->verify(auth()->user());

        unset($this->charge, $this->revisions);
        Flux::toast(variant: 'success', text: 'ทำเครื่องหมายตรวจสอบแล้ว');
    }

    public function openUnverify(): void
    {
        $this->authorize('unverify', $this->charge);

        $this->unverify_reason = '';
        $this->resetValidation();

        Flux::modal('unverify-form')->show();
    }

    public function confirmUnverify(): void
    {
        $charge = $this->charge;
        $this->authorize('unverify', $charge);

        $validated = $this->validate(
            ['unverify_reason' => ['nullable', 'string', 'max:500', new NoFullCardNumber]],
            [],
            ['unverify_reason' => 'เหตุผล'],
        );

        $charge->unverify(auth()->user(), $validated['unverify_reason']);

        unset($this->charge, $this->revisions);
        Flux::modal('unverify-form')->close();
        Flux::toast(variant: 'success', text: 'ยกเลิกการตรวจสอบแล้ว');
    }
}; ?>

@php
    $c = $this->charge;
    $s = $c->subscription;
    $late = $c->daysRecordedLate();
@endphp

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <header class="flex flex-col gap-4">
        <a href="{{ route('subscriptions.show', $s) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> กลับไปหน้า {{ $s->name }}
        </a>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-sm text-slate-500">รายการตัดเงิน · รอบครบกำหนด {{ ThaiFormat::date($c->due_date) }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $s->name }}</h1>
                <div class="mt-2 flex flex-wrap gap-2" data-test="charge-badges">
                    <x-charge-badges :charge="$c" />
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('update', $c)
                    <flux:button icon="pencil-square" :href="route('charges.edit', $c)" wire:navigate data-test="edit-charge-button">แก้ไข</flux:button>
                @endcan
                @can('verify', $c)
                    <flux:button icon="check-badge" variant="primary" wire:click="verify" data-test="verify-button">ตรวจสอบแล้ว</flux:button>
                @endcan
                @can('unverify', $c)
                    <flux:button icon="arrow-uturn-left" wire:click="openUnverify" data-test="unverify-button">ยกเลิกการตรวจสอบ</flux:button>
                @endcan
            </div>
        </div>
    </header>

    @if ($c->entered_after_close)
        <flux:callout icon="lock-closed" color="amber" data-test="after-close-notice">
            <flux:callout.text>รายการนี้ถูกบันทึกหรือแก้ไขหลังจากงวดบัญชีของเดือนนั้นปิดแล้ว ยอดของงวดที่ปิดไว้ไม่ได้ถูกเปลี่ยน ฝ่ายบัญชีควรตรวจและปรับปรุงตามขั้นตอนของบริษัท</flux:callout.text>
        </flux:callout>
    @endif

    <x-ui.card>
        <x-ui.section-header title="รายละเอียด" icon="receipt-percent" />
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-slate-500">ผลการตัดเงิน</dt>
                <dd class="mt-0.5 text-slate-900">{{ $c->outcome->label() }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">ยอดที่ตัดจริง</dt>
                <dd class="mt-0.5 text-lg font-semibold text-slate-900" data-test="charge-amount">{{ $c->amountLabel() }}</dd>
                <dd class="text-xs text-slate-500">ยอดประมาณการ {{ ThaiFormat::money($s->estimated_amount, $s->currency->value) }}</dd>
            </div>
            @if ($c->isCharged() && $c->currency->value !== 'THB')
                <div>
                    <dt class="text-sm text-slate-500">ยอดเงินบาทที่ธนาคารตัด</dt>
                    <dd class="mt-0.5 text-slate-900" data-test="charge-amount-thb">
                        {{ $c->amount_thb !== null ? ThaiFormat::money($c->amount_thb) : 'ยังไม่ได้กรอก' }}
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-sm text-slate-500">วันที่ตัดเงินจริง</dt>
                <dd class="mt-0.5 text-slate-900">{{ $c->charged_on ? ThaiFormat::date($c->charged_on) : '–' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">บัตรที่ถูกตัด</dt>
                <dd class="mt-0.5 text-slate-900">{{ $c->creditCard->name }} <span class="font-mono tracking-wider">{{ $c->creditCard->maskedNumber() }}</span></dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">บันทึกโดย</dt>
                <dd class="mt-0.5 text-slate-900" data-test="recorded-by">
                    {{ $c->recorder?->name ?? '–' }} · {{ ThaiFormat::date($c->created_at) }} {{ $c->created_at?->format('H:i') }}
                </dd>
                @if ($late > 0)
                    <dd class="text-xs text-amber-700">บันทึกหลังวันที่ตัดเงิน {{ $late }} วัน</dd>
                @endif
            </div>
            <div>
                <dt class="text-sm text-slate-500">การตรวจสอบของฝ่ายบัญชี</dt>
                <dd class="mt-0.5 text-slate-900">
                    {{ $c->isVerified() ? 'ตรวจสอบแล้วโดย '.($c->verifier?->name ?? '–').' · '.ThaiFormat::date($c->verified_at) : 'ยังไม่ได้ตรวจสอบ' }}
                </dd>
            </div>
        </dl>

        <div class="mt-6 border-t border-slate-100 pt-4">
            <p class="text-sm font-medium text-slate-700">หมายเหตุ</p>
            <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $c->notes ?: '–' }}</p>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-header title="ประวัติการเปลี่ยนแปลง" description="ทุกการบันทึก แก้ไข และตรวจสอบ พร้อมผู้ทำและเวลา" icon="clock" />
        <ol class="space-y-4" data-test="charge-history">
            @foreach ($this->revisions as $revision)
                <li class="flex flex-col gap-1 text-sm sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <span class="font-medium text-slate-800">{{ $revision->action->label() }}</span>
                        @if ($revision->in_closed_period)
                            <x-ui.status-badge tone="amber" :dot="false" class="ms-1">หลังปิดงวด</x-ui.status-badge>
                        @endif
                        @if ($revision->action === ChargeRevisionAction::Updated)
                            <ul class="mt-1 space-y-0.5 text-slate-600">
                                @foreach ($revision->describeChanges($c->currency->value, $this->historyCards) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($revision->reason)
                            <span class="mt-1 block text-slate-600">เหตุผล: {{ $revision->reason }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 text-slate-500">{{ $revision->changer?->name ?? '–' }} · {{ ThaiFormat::date($revision->created_at) }} {{ $revision->created_at?->format('H:i') }}</span>
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    @can('unverify', $c)
        <flux:modal name="unverify-form" class="w-full max-w-md">
            <form wire:submit="confirmUnverify" class="space-y-5">
                <flux:heading size="lg">ยกเลิกการตรวจสอบ</flux:heading>
                <flux:input wire:model="unverify_reason" label="เหตุผล (ไม่บังคับ)" maxlength="500" autocomplete="off" />
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <flux:modal.close><flux:button variant="ghost" class="w-full sm:w-auto">ไม่ใช่ตอนนี้</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="w-full sm:w-auto">ยืนยัน</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
