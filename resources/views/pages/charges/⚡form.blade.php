<?php

use App\Enums\ChargeOutcome;
use App\Enums\Currency;
use App\Exceptions\ChargeConflictException;
use App\Models\AccountingPeriodClosure;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Rules\NoFullCardNumber;
use App\Support\MoneyInput;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Record the actual charge of a subscription's current cycle, or correct a
 * recorded charge. Owner only (SubscriptionChargePolicy::create/update),
 * authorized again on save because Livewire actions can be called directly.
 *
 * - Recording goes through Subscription::recordCharge(), which also moves
 *   the next charge date and refuses a cycle that was already recorded.
 * - Corrections go through SubscriptionCharge::revise(), which keeps the
 *   old → new values, who and when. A reason is required.
 * - The charge date may be backdated (up to today). If it falls in a
 *   closed accounting month, the person must confirm it is a late entry;
 *   the closed month itself is never changed.
 * - USD charges keep the USD amount; the baht the bank took is optional
 *   and can be filled in later.
 * - Phase 3.1e-3: the payment method actually used is chosen here (the
 *   subscription's method by default). Auto methods (card, direct debit):
 *   "record the actual charge". Manual methods (transfer / PromptPay, cash):
 *   "confirm the payment", which also needs an explicit tick that the money
 *   was really paid. The person saving is stored as recorded_by.
 */
new #[Title('บันทึกยอดตัดจริง')] class extends Component {
    #[Locked]
    public int $subscriptionId;

    #[Locked]
    public ?int $chargeId = null;

    #[Locked]
    public string $dueDate = '';

    public string $outcome = 'charged';
    public string $charged_on = '';
    public string $amount = '';
    public string $amount_thb = '';
    public string $payment_method_id = '';
    public string $notes = '';
    public string $reason = '';
    public bool $confirm_closed_period = false;
    public bool $confirm_paid = false;

    public function mount(?Subscription $subscription = null, ?SubscriptionCharge $charge = null): void
    {
        if ($charge !== null && $charge->exists) {
            $this->authorize('update', $charge);

            $this->chargeId = $charge->id;
            $this->subscriptionId = $charge->subscription_id;
            $this->dueDate = $charge->due_date->format('Y-m-d');
            $this->outcome = $charge->outcome->value;
            $this->charged_on = $charge->charged_on?->format('Y-m-d') ?? '';
            $this->amount = $charge->isCharged() ? MoneyInput::fromMinor($charge->amount) : '';
            $this->amount_thb = $charge->amount_thb !== null ? MoneyInput::fromMinor($charge->amount_thb) : '';
            $this->payment_method_id = (string) $charge->payment_method_id;
            $this->notes = $charge->notes ?? '';

            return;
        }

        abort_if($subscription === null || ! $subscription->exists, 404);
        $this->authorize('create', [SubscriptionCharge::class, $subscription]);

        $today = CarbonImmutable::today();
        $due = $subscription->next_charge_date->startOfDay();

        $this->subscriptionId = $subscription->id;
        $this->dueDate = $due->format('Y-m-d');
        $this->charged_on = ($due->greaterThan($today) ? $today : $due)->format('Y-m-d');
        $this->amount = MoneyInput::fromMinor($subscription->estimated_amount);
        $this->payment_method_id = (string) $subscription->payment_method_id;
    }

    #[Computed]
    public function subscription(): Subscription
    {
        return Subscription::with(['creditCard', 'paymentMethod'])->findOrFail($this->subscriptionId);
    }

    #[Computed]
    public function charge(): ?SubscriptionCharge
    {
        return $this->chargeId !== null ? SubscriptionCharge::findOrFail($this->chargeId) : null;
    }

    /**
     * Every payment method, active ones first: a backdated payment may have
     * been made with a method that has been deactivated since.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        return PaymentMethod::query()->with(['creditCard', 'bankAccount'])->orderByRaw('deactivated_at IS NOT NULL')->orderBy('name')->get();
    }

    /**
     * The method picked in the form (falls back to the subscription's).
     */
    #[Computed]
    public function chosenMethod(): ?PaymentMethod
    {
        $picked = ctype_digit($this->payment_method_id) ? $this->methods->firstWhere('id', (int) $this->payment_method_id) : null;

        return $picked ?? $this->subscription->paymentMethod;
    }

    /**
     * Manual (transfer / PromptPay / cash): someone pays and confirms it.
     */
    #[Computed]
    public function isManual(): bool
    {
        return $this->chosenMethod !== null && ! $this->chosenMethod->isAuto();
    }

    public function targetLabel(PaymentMethod $method): string
    {
        return $method->creditCard?->maskedNumber() ?? $method->bankAccount?->maskedNumber() ?? '';
    }

    /**
     * Thai message when recording now is not possible (too early, not active).
     */
    #[Computed]
    public function blockedReason(): ?string
    {
        if ($this->chargeId !== null) {
            return null;
        }

        $s = $this->subscription;

        if (! $s->isActive()) {
            return 'Subscription นี้ไม่ได้อยู่ในสถานะใช้งาน จึงบันทึกยอดไม่ได้';
        }

        if ($s->next_charge_date->format('Y-m-d') !== $this->dueDate) {
            return 'รอบนี้ถูกบันทึกไปแล้ว หรือวันตัดบัตรถูกเปลี่ยน กรุณากลับไปหน้ารายละเอียด';
        }

        if (! $s->canRecordChargeOn()) {
            return 'บันทึกยอดของรอบนี้ได้ตั้งแต่ '.ThaiFormat::date($s->next_charge_date->subDays(SubscriptionCharge::RECORD_AHEAD_DAYS)).' (ก่อนกำหนดชำระ '.SubscriptionCharge::RECORD_AHEAD_DAYS.' วัน)';
        }

        return null;
    }

    /**
     * Closed accounting months this save would touch (new date, and on edit
     * also the charge's current month).
     *
     * @return list<string>
     */
    #[Computed]
    public function closedPeriods(): array
    {
        $periods = [];

        if ($this->outcome === ChargeOutcome::Charged->value && ($date = $this->parsedDate()) !== null) {
            $periods[] = AccountingPeriodClosure::periodOf($date);
        }

        if (($period = $this->charge?->period()) !== null) {
            $periods[] = $period;
        }

        return $periods === [] ? [] : array_keys(AccountingPeriodClosure::closedAmong($periods));
    }

    /**
     * Shown (not blocking) when the actual amount is far from the estimate.
     */
    #[Computed]
    public function varianceNotice(): ?string
    {
        $minor = MoneyInput::toMinor($this->amount);
        $estimate = $this->subscription->estimated_amount;

        if ($this->outcome !== ChargeOutcome::Charged->value || $minor === null || $minor === 0 || $estimate === 0) {
            return null;
        }

        $percent = (int) round(($minor - $estimate) / $estimate * 100);

        return abs($percent) >= 20
            ? 'ยอดนี้'.($percent > 0 ? 'สูง' : 'ต่ำ').'กว่ายอดประมาณการ '.abs($percent).'% ('.ThaiFormat::money($estimate, $this->subscription->currency->value).') กรุณาตรวจอีกครั้ง'
            : null;
    }

    public function updatedOutcome(): void
    {
        $this->confirm_closed_period = false;
    }

    public function updatedChargedOn(): void
    {
        $this->confirm_closed_period = false;
    }

    public function save(): void
    {
        $subscription = $this->subscription;
        $charge = $this->charge;

        $charge
            ? $this->authorize('update', $charge)
            : $this->authorize('create', [SubscriptionCharge::class, $subscription]);

        foreach (['charged_on', 'amount', 'amount_thb', 'notes', 'reason'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $today = CarbonImmutable::today();
        $due = CarbonImmutable::createFromFormat('!Y-m-d', $this->dueDate) ?: $today;
        $charged = $this->outcome === ChargeOutcome::Charged->value;
        $foreign = $subscription->currency !== Currency::THB;

        $validated = $this->validate([
            'outcome' => ['required', Rule::enum(ChargeOutcome::class)],
            'charged_on' => [Rule::requiredIf($charged), 'nullable', 'bail', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($today, $due, $charged) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value);

                if (! $charged || $date === null) {
                    return;
                }

                if ($date->greaterThan($today)) {
                    $fail('วันที่ตัดเงินจริงต้องไม่เกินวันนี้');
                } elseif ($date->lessThan($due->subDays(SubscriptionCharge::MAX_DAYS_BEFORE_DUE))) {
                    $fail('วันที่ตัดเงินจริงเร็วกว่าวันครบกำหนดของรอบนี้ ('.ThaiFormat::date($due).') เกิน '.SubscriptionCharge::MAX_DAYS_BEFORE_DUE.' วัน ตรวจว่าเลือกรอบถูกต้อง');
                }
            }],
            'amount' => [Rule::requiredIf($charged), 'nullable', 'string', function ($attribute, $value, $fail) use ($charged) {
                if (! $charged) {
                    return;
                }

                $minor = MoneyInput::toMinor((string) $value);

                if ($minor === null || $minor > MoneyInput::MAX_MINOR) {
                    $fail('กรอกยอดเป็นตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง เช่น 590 หรือ 1,234.56');
                } elseif ($minor === 0) {
                    $fail('ยอดที่ตัดจริงต้องมากกว่า 0 ถ้ารอบนี้ไม่ถูกตัดเงิน ให้เลือก "รอบนี้ไม่ถูกตัดเงิน"');
                }
            }],
            'amount_thb' => ['nullable', 'string', function ($attribute, $value, $fail) use ($charged, $foreign) {
                if (! $charged || ! $foreign || $value === null || $value === '') {
                    return;
                }

                $minor = MoneyInput::toMinor((string) $value);

                if ($minor === null || $minor > MoneyInput::MAX_MINOR) {
                    $fail('กรอกยอดบาทเป็นตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง เช่น 1,850.25');
                } elseif ($minor === 0) {
                    $fail('ยอดบาทต้องมากกว่า 0 ถ้ายังไม่ทราบ ให้เว้นว่างไว้ก่อน');
                }
            }],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')],
            'notes' => ['nullable', 'string', 'max:2000', new NoFullCardNumber],
            'reason' => [Rule::requiredIf($charge !== null), 'nullable', 'string', 'max:500', new NoFullCardNumber],
        ], [
            'charged_on.required' => 'กรุณาระบุวันที่ตัดเงินจริง',
            'amount.required' => 'กรุณากรอกยอดที่ตัดจริง',
            'reason.required' => 'กรุณาระบุเหตุผลของการแก้ไข',
        ], [
            'outcome' => 'ผลการตัดเงิน',
            'charged_on' => 'วันที่ตัดเงินจริง',
            'amount' => 'ยอดที่ตัดจริง',
            'amount_thb' => 'ยอดเงินบาทที่ธนาคารตัด',
            'payment_method_id' => 'ช่องทางที่ใช้ชำระจริง',
            'notes' => 'หมายเหตุ',
            'reason' => 'เหตุผลของการแก้ไข',
        ]);

        unset($this->closedPeriods, $this->chosenMethod, $this->isManual);

        // A new Manual payment must be confirmed explicitly: reaching the due
        // date never counts as paid.
        if ($charge === null && $charged && $this->isManual && ! $this->confirm_paid) {
            $this->addError('confirm_paid', 'กรุณาติ๊กยืนยันว่าได้ชำระเงินรอบนี้แล้วจริง');

            return;
        }

        if ($this->closedPeriods !== [] && ! $this->confirm_closed_period) {
            $this->addError('confirm_closed_period', 'วันที่นี้อยู่ในงวดบัญชีที่ปิดแล้ว กรุณาติ๊กยืนยันว่าเป็นรายการย้อนหลัง');

            return;
        }

        $values = [
            'outcome' => ChargeOutcome::from($validated['outcome']),
            'charged_on' => $charged ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['charged_on']) ?: null : null,
            'amount' => $charged ? (int) MoneyInput::toMinor((string) $validated['amount']) : 0,
            'amount_thb' => $charged && $foreign && ($validated['amount_thb'] ?? '') !== '' ? MoneyInput::toMinor((string) $validated['amount_thb']) : null,
            'payment_method_id' => (int) $validated['payment_method_id'],
            'notes' => $validated['notes'] ?? null,
        ];

        try {
            if ($charge !== null) {
                $changed = $charge->revise($values, auth()->user(), (string) $validated['reason'], $this->confirm_closed_period);

                Flux::toast(variant: $changed ? 'success' : 'warning', text: $changed ? 'บันทึกการแก้ไขแล้ว' : 'ไม่มีข้อมูลที่เปลี่ยน');
                $this->redirectRoute('charges.show', $charge, navigate: true);

                return;
            }

            $recorded = $subscription->recordCharge($due, $values, auth()->user(), $this->confirm_closed_period);
        } catch (ChargeConflictException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: match (true) {
            ! $recorded->isCharged() => 'บันทึกว่ารอบนี้ไม่ถูกตัดเงินแล้ว',
            $this->isManual => 'ยืนยันการชำระเงินแล้ว',
            default => 'บันทึกยอดตัดจริงแล้ว',
        });
        $this->redirectRoute('subscriptions.show', $subscription, navigate: true);
    }

    private function parsedDate(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->charged_on) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->charged_on);

        return $date !== null && $date->format('Y-m-d') === $this->charged_on ? $date : null;
    }
}; ?>

@php
    $s = $this->subscription;
    $editing = $this->charge;
    $foreign = $s->currency !== Currency::THB;
    $isCharged = $outcome === ChargeOutcome::Charged->value;
    $blocked = $this->blockedReason;
    $closed = $this->closedPeriods;
    $due = CarbonImmutable::createFromFormat('!Y-m-d', $dueDate);
    $backUrl = $editing ? route('charges.show', $editing) : route('subscriptions.show', $s);
    $manual = $this->isManual;
    $heading = match (true) {
        $editing !== null => $manual ? 'แก้ไขการชำระเงิน' : 'แก้ไขยอดตัดจริง',
        $manual => 'ยืนยันการชำระเงิน',
        default => 'บันทึกยอดตัดจริง',
    };
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <header class="flex flex-col gap-2">
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> {{ $editing ? 'กลับไปหน้ารายการตัดเงิน' : 'กลับไปหน้ารายละเอียด' }}
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl" data-test="charge-heading">{{ $heading }}</h1>
        <p class="text-sm text-slate-500">
            {{ $s->name }} · รอบครบกำหนด <strong class="text-slate-700" data-test="due-date">{{ $due ? ThaiFormat::date($due) : '–' }}</strong>
            · ยอดประมาณการ {{ $s->amountLabel() }}
        </p>
    </header>

    @if ($blocked)
        <flux:callout icon="information-circle" color="amber" data-test="blocked-notice">
            <flux:callout.text>{{ $blocked }}</flux:callout.text>
        </flux:callout>
        <div>
            <flux:button :href="$backUrl" wire:navigate>กลับ</flux:button>
        </div>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            @error('form')
                <flux:callout icon="exclamation-triangle" color="red" data-test="form-error">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror

            <x-ui.card>
                <x-ui.section-header title="ผลการตัดเงินของรอบนี้" icon="receipt-percent" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:select wire:model.live="outcome" label="ผลการตัดเงิน">
                            @foreach (ChargeOutcome::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @unless ($isCharged)
                            <flux:text class="mt-1 text-xs">เช่น ได้ใช้ฟรีหนึ่งเดือน หรือผู้ให้บริการเลื่อนรอบ ระบบจะเลื่อนไปรอบถัดไปโดยไม่มียอดเงิน</flux:text>
                        @endunless
                    </div>

                    @if ($isCharged)
                        <div>
                            <flux:input type="date" wire:model.live="charged_on" :label="$manual ? 'วันที่ชำระจริง' : 'วันที่ตัดเงินจริง'" :max="now()->format('Y-m-d')" required />
                            <flux:text class="mt-1 text-xs">{{ $manual ? 'ตามวันที่ในสลิปโอนหรือหลักฐานการชำระ บันทึกย้อนหลังได้' : 'ตามวันที่ในใบแจ้งยอดบัตรหรือรายการเดินบัญชี บันทึกย้อนหลังได้' }}</flux:text>
                        </div>

                        <div>
                            <flux:input wire:model.blur="amount" :label="($manual ? 'ยอดที่ชำระจริง' : 'ยอดที่ตัดจริง').' ('.$s->currency->value.')'" placeholder="เช่น 590 หรือ 54.99" inputmode="decimal" required autocomplete="off" />
                            @if ($this->varianceNotice)
                                <flux:text class="mt-1 text-xs !text-amber-700" data-test="variance-notice">{{ $this->varianceNotice }}</flux:text>
                            @endif
                        </div>

                        @if ($foreign)
                            <div class="sm:col-span-2">
                                <flux:input wire:model="amount_thb" label="ยอดเงินบาทที่ธนาคารตัดจริง (ไม่บังคับ)" placeholder="เช่น 1,850.25" inputmode="decimal" autocomplete="off" />
                                <flux:text class="mt-1 text-xs">ถ้ายังไม่ได้ใบแจ้งยอดบัตร เว้นว่างไว้ก่อนแล้วกลับมาเพิ่มภายหลังได้ ยอดรวมเป็นบาทจะนับรายการนี้เมื่อกรอกแล้วเท่านั้น</flux:text>
                            </div>
                        @endif
                    @endif

                    <div class="sm:col-span-2">
                        <flux:select wire:model.live="payment_method_id" label="ช่องทางที่ใช้ชำระจริง">
                            @foreach ($this->methods as $option)
                                <flux:select.option value="{{ $option->id }}">{{ $option->name }}{{ $this->targetLabel($option) !== '' ? ' · '.$this->targetLabel($option) : '' }} · {{ $option->isAuto() ? 'Auto' : 'Manual' }}{{ $option->isActive() ? '' : ' (ปิดใช้งาน)' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:text class="mt-1 text-xs" data-test="method-hint">ค่าเริ่มต้นคือช่องทางของ Subscription ถ้ารอบนี้จ่ายด้วยช่องทางอื่น ให้เลือกช่องทางที่ใช้จริง</flux:text>
                    </div>

                    @if ($manual && $isCharged && ! $editing)
                        <div class="sm:col-span-2 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200" data-test="confirm-paid-box">
                            <flux:checkbox wire:model="confirm_paid" label="ยืนยันว่าได้ชำระเงินรอบนี้แล้วจริง (โอน / PromptPay / จ่ายเงินสด)" data-test="confirm-paid" />
                            <flux:text class="mt-1 text-xs">รายการ Manual จะนับว่าจ่ายแล้วเมื่อยืนยันเท่านั้น แนบสลิปเป็นหลักฐานได้ในหน้ารายการหลังบันทึก</flux:text>
                            @error('confirm_paid')
                                <flux:text class="mt-1 text-xs !text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="notes" label="หมายเหตุ" rows="2" maxlength="2000" />
                        <flux:text class="mt-1 text-xs">ห้ามใส่เลขบัตรเต็ม รหัส CVV หรือรหัสผ่าน</flux:text>
                    </div>
                </div>
            </x-ui.card>

            @if ($closed)
                <flux:callout icon="lock-closed" color="amber" data-test="closed-period-warning">
                    <flux:callout.heading>งวดบัญชีปิดแล้ว: {{ collect($closed)->map(fn ($p) => ThaiFormat::monthYear(AccountingPeriodClosure::startOf($p)))->join(', ') }}</flux:callout.heading>
                    <flux:callout.text>
                        รายการนี้จะถูกระบุว่าเป็น <strong>รายการย้อนหลัง (หลังปิดงวด)</strong> ให้ฝ่ายบัญชีตรวจ
                        ยอดของงวดที่ปิดแล้วจะไม่ถูกเปลี่ยนอัตโนมัติ
                    </flux:callout.text>
                    <div class="mt-3">
                        <flux:checkbox wire:model="confirm_closed_period" label="ยืนยันว่าเป็นรายการย้อนหลังในงวดที่ปิดแล้ว" data-test="confirm-closed-period" />
                        @error('confirm_closed_period')
                            <flux:text class="mt-1 text-xs !text-red-600">{{ $message }}</flux:text>
                        @enderror
                    </div>
                </flux:callout>
            @endif

            @if ($editing)
                <x-ui.card>
                    <flux:input wire:model="reason" label="เหตุผลของการแก้ไข" placeholder="เช่น ใบแจ้งยอดบัตรมาแล้ว ยอดจริงต่างจากที่บันทึก" maxlength="500" required autocomplete="off" />
                    <flux:text class="mt-1 text-xs">ค่าเดิม ค่าใหม่ ผู้แก้ไข และเวลา จะถูกเก็บในประวัติการแก้ไข @if ($editing->isVerified()) การแก้ยอด วันที่ หรือช่องทางชำระเงิน จะยกเลิกสถานะ "ตรวจสอบแล้ว" ให้ฝ่ายบัญชีตรวจใหม่ @endif</flux:text>
                </x-ui.card>
            @else
                <flux:text class="text-sm">เมื่อบันทึกแล้ว กำหนดชำระครั้งถัดไปจะเลื่อนไปรอบถัดไปให้อัตโนมัติ</flux:text>
            @endif

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button variant="ghost" :href="$backUrl" wire:navigate class="w-full sm:w-auto">ยกเลิก</flux:button>
                <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-charge-button">{{ $manual && ! $editing ? 'ยืนยันการชำระเงิน' : 'บันทึก' }}</flux:button>
            </div>
        </form>
    @endif
</div>
