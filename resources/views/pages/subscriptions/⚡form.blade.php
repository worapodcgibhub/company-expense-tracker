<?php

use App\Enums\BillingCycle;
use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\User;
use App\Rules\NoFullCardNumber;
use App\Support\BillingSchedule;
use App\Support\MoneyInput;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Add / edit a subscription (Owner only, SubscriptionPolicy::create/update;
 * authorized again on save because Livewire actions can be called directly).
 *
 * - A deactivated card or user can never be chosen for a new subscription,
 *   or newly chosen for an existing one. An existing link to one that was
 *   deactivated later is kept (with a warning) so the record stays editable.
 * - The amount is parsed from text into minor units (MoneyInput), no floats.
 * - `billing_day` is the contract day; the next charge date must fall on it
 *   (or on the month's last day when the month is shorter).
 * - Status is not edited here: it changes only on the detail page, which
 *   records every change.
 */
new #[Title('Subscription')] class extends Component {
    #[Locked]
    public ?int $subscriptionId = null;

    public string $name = '';
    public string $vendor = '';
    public string $category = 'software';
    public string $responsible_user_id = '';
    public string $credit_card_id = '';
    public string $billing_cycle = 'monthly';
    public string $next_charge_date = '';
    public string $billing_day = '';
    public string $currency = 'THB';
    public string $amount = '';
    public string $remind_days_before = '3';
    public string $notes = '';

    /** @var list<array{name: string, detail: string}> */
    #[Locked]
    public array $duplicateWarnings = [];

    #[Locked]
    public ?string $warnedFingerprint = null;

    #[Locked]
    public ?string $acknowledgedFingerprint = null;

    public function mount(?Subscription $subscription = null): void
    {
        if ($subscription === null || ! $subscription->exists) {
            $this->authorize('create', Subscription::class);
            $this->responsible_user_id = (string) auth()->id();

            return;
        }

        $this->authorize('update', $subscription);

        $this->subscriptionId = $subscription->id;
        $this->name = $subscription->name;
        $this->vendor = $subscription->vendor;
        $this->category = $subscription->category->value;
        $this->responsible_user_id = (string) $subscription->responsible_user_id;
        $this->credit_card_id = (string) $subscription->credit_card_id;
        $this->billing_cycle = $subscription->billing_cycle->value;
        $this->next_charge_date = $subscription->next_charge_date->format('Y-m-d');
        $this->billing_day = (string) $subscription->billing_day;
        $this->currency = $subscription->currency->value;
        $this->amount = MoneyInput::fromMinor($subscription->estimated_amount);
        $this->remind_days_before = (string) $subscription->remind_days_before;
        $this->notes = $subscription->notes ?? '';
    }

    #[Computed]
    public function editing(): ?Subscription
    {
        return $this->subscriptionId !== null ? Subscription::with(['creditCard', 'responsible'])->findOrFail($this->subscriptionId) : null;
    }

    /**
     * Active cards, plus the current one if it has been deactivated since.
     *
     * @return Collection<int, CreditCard>
     */
    #[Computed]
    public function cards(): Collection
    {
        return CreditCard::query()
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->editing?->credit_card_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Active users, plus the current responsible person if deactivated since.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->editing?->responsible_user_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * The next three charge dates for what is in the form, or [] while the
     * date and billing day do not fit together yet.
     *
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function preview(): array
    {
        $date = $this->parsedDate();
        $cycle = BillingCycle::tryFrom($this->billing_cycle);
        $day = ctype_digit($this->billing_day) ? (int) $this->billing_day : 0;

        if ($date === null || $cycle === null || $day < 1 || $day > 31 || ! BillingSchedule::matchesBillingDay($date, $day)) {
            return [];
        }

        return BillingSchedule::upcoming($date, $cycle, $day, 3);
    }

    /**
     * Choosing a date fills in the billing day from it. A month-end date in a
     * short month keeps 31 if that was already chosen ("every month end").
     */
    public function updatedNextChargeDate(): void
    {
        $date = $this->parsedDate();

        if ($date === null) {
            return;
        }

        $keepMonthEnd = $this->billing_day === '31' && $date->day === $date->daysInMonth;

        if (! $keepMonthEnd) {
            $this->billing_day = (string) $date->day;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['name', 'vendor'], true)) {
            $this->duplicateWarnings = [];
        }
    }

    public function save(): void
    {
        $editing = $this->editing;

        $editing
            ? $this->authorize('update', $editing)
            : $this->authorize('create', Subscription::class);

        foreach (['name', 'vendor', 'amount', 'notes', 'next_charge_date'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $today = CarbonImmutable::today();
        $dateChanged = $editing === null || $this->next_charge_date !== $editing->next_charge_date->format('Y-m-d');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150', new NoFullCardNumber],
            'vendor' => ['required', 'string', 'max:150', new NoFullCardNumber],
            'category' => ['required', Rule::enum(SubscriptionCategory::class)],
            'responsible_user_id' => ['required', 'integer', Rule::exists('users', 'id'), function ($attribute, $value, $fail) use ($editing) {
                if ((int) $value !== $editing?->responsible_user_id && User::whereKey((int) $value)->whereNull('deactivated_at')->doesntExist()) {
                    $fail('ผู้รับผิดชอบต้องเป็นบัญชีที่ยังใช้งานอยู่');
                }
            }],
            'credit_card_id' => ['required', 'integer', Rule::exists('credit_cards', 'id'), function ($attribute, $value, $fail) use ($editing) {
                if ((int) $value !== $editing?->credit_card_id && CreditCard::whereKey((int) $value)->whereNull('deactivated_at')->doesntExist()) {
                    $fail('บัตรนี้ถูกปิดใช้งานแล้ว เลือกบัตรที่ยังใช้งานอยู่');
                }
            }],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'billing_day' => ['required', 'integer', 'between:1,31'],
            'next_charge_date' => ['bail', 'required', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($today, $dateChanged) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                if ($date === null) {
                    return;
                }

                if ($dateChanged && $date->lessThan($today)) {
                    $fail('วันตัดบัตรครั้งถัดไปต้องเป็นวันนี้หรือหลังจากนี้');
                } elseif ($date->greaterThan($today->addYears(2))) {
                    $fail('วันตัดบัตรครั้งถัดไปต้องไม่เกิน 2 ปีจากวันนี้');
                } elseif (ctype_digit($this->billing_day) && ! BillingSchedule::matchesBillingDay($date, (int) $this->billing_day)) {
                    $fail('วันที่นี้ไม่ตรงกับ "วันตัดตามรอบ" ที่เลือกไว้');
                }
            }],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['required', 'string', function ($attribute, $value, $fail) {
                $minor = MoneyInput::toMinor($value);

                if ($minor === null || $minor > MoneyInput::MAX_MINOR) {
                    $fail('กรอกยอดเป็นตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง เช่น 590 หรือ 1,234.56');
                } elseif ($minor === 0) {
                    $fail('ยอดประมาณการต้องมากกว่า 0');
                }
            }],
            'remind_days_before' => ['required', 'integer', 'between:0,30'],
            'notes' => ['nullable', 'string', 'max:2000', new NoFullCardNumber],
        ], [
            'billing_day.required' => 'กรุณาเลือกวันตัดตามรอบ',
            'remind_days_before.between' => 'แจ้งเตือนล่วงหน้าได้ 0–30 วัน',
        ], [
            'name' => 'ชื่อบริการ',
            'vendor' => 'ผู้ให้บริการ',
            'category' => 'หมวดหมู่',
            'responsible_user_id' => 'ผู้รับผิดชอบ',
            'credit_card_id' => 'บัตรที่ใช้ชำระ',
            'billing_cycle' => 'รอบบิล',
            'billing_day' => 'วันตัดตามรอบ',
            'next_charge_date' => 'วันตัดบัตรครั้งถัดไป',
            'currency' => 'สกุลเงิน',
            'amount' => 'ยอดประมาณการ',
            'remind_days_before' => 'แจ้งเตือนล่วงหน้า',
            'notes' => 'หมายเหตุ',
        ]);

        $duplicates = Subscription::possibleDuplicatesOf($validated['name'], $validated['vendor'], $editing?->id);
        $fingerprint = implode('|', [$editing->id ?? 'new', Subscription::normalize($validated['name']), Subscription::normalize($validated['vendor'])]);

        if ($duplicates->isNotEmpty() && $this->acknowledgedFingerprint !== $fingerprint) {
            $this->warnedFingerprint = $fingerprint;
            $this->duplicateWarnings = $duplicates->map(fn (Subscription $s) => [
                'name' => $s->name,
                'detail' => "{$s->vendor} · {$s->amountLabel()} · {$s->creditCard->maskedNumber()} · {$s->status->label()}",
            ])->all();

            return;
        }

        $subscription = $editing ?? new Subscription;

        DB::transaction(function () use ($subscription, $validated, $editing) {
            $subscription->fill([
                'name' => $validated['name'],
                'vendor' => $validated['vendor'],
                'category' => SubscriptionCategory::from($validated['category']),
                'responsible_user_id' => (int) $validated['responsible_user_id'],
                'credit_card_id' => (int) $validated['credit_card_id'],
                'billing_cycle' => BillingCycle::from($validated['billing_cycle']),
                'billing_day' => (int) $validated['billing_day'],
                'next_charge_date' => CarbonImmutable::createFromFormat('!Y-m-d', $validated['next_charge_date']),
                'currency' => Currency::from($validated['currency']),
                'estimated_amount' => MoneyInput::toMinor($validated['amount']),
                'remind_days_before' => (int) $validated['remind_days_before'],
                'notes' => $validated['notes'] === '' || $validated['notes'] === null ? null : $validated['notes'],
            ]);

            if ($editing === null) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'status_changed_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            $subscription->forceFill(['updated_by' => auth()->id()])->save();

            if ($editing === null) {
                $subscription->statusChanges()->create([
                    'from_status' => null,
                    'to_status' => SubscriptionStatus::Active,
                    'changed_by' => auth()->id(),
                ]);
            }
        });

        Flux::toast(variant: 'success', text: $editing ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่ม Subscription เรียบร้อยแล้ว');

        $this->redirectRoute('subscriptions.show', $subscription, navigate: true);
    }

    public function saveAnyway(): void
    {
        $this->acknowledgedFingerprint = $this->warnedFingerprint;

        $this->save();
    }

    /**
     * The date in the form, or null while it is not a real Y-m-d date.
     */
    #[Computed]
    public function chosenDate(): ?CarbonImmutable
    {
        return $this->parsedDate();
    }

    private function parsedDate(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->next_charge_date) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->next_charge_date);

        return $date !== null && $date->format('Y-m-d') === $this->next_charge_date ? $date : null;
    }
}; ?>

@php
    $editing = $this->editing;
    $date = $this->chosenDate;
    $isShortMonthEnd = $date !== null && $date->day === $date->daysInMonth && $date->day < 31;
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <header class="flex flex-col gap-2">
        <a href="{{ $editing ? route('subscriptions.show', $editing) : route('subscriptions.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> {{ $editing ? 'กลับไปหน้ารายละเอียด' : 'กลับไปรายการ Subscription' }}
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $editing ? 'แก้ไข Subscription' : 'เพิ่ม Subscription' }}</h1>
        <p class="text-sm text-slate-500">ยอดเงินในหน้านี้เป็น<strong>ยอดประมาณการ</strong> ยอดที่ตัดจริงจะบันทึกแยกในขั้นตอนยืนยันยอด</p>
    </header>

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- Service --}}
        <x-ui.card>
            <x-ui.section-header title="ข้อมูลบริการ" icon="squares-2x2" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.blur="name" label="ชื่อบริการ" placeholder="เช่น Adobe Creative Cloud" required maxlength="150" autocomplete="off" />
                <flux:input wire:model.blur="vendor" label="ผู้ให้บริการ" placeholder="เช่น Adobe Inc." required maxlength="150" autocomplete="off" />

                <flux:select wire:model="category" label="หมวดหมู่">
                    @foreach (SubscriptionCategory::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:select wire:model="responsible_user_id" label="ผู้รับผิดชอบ" description="คนที่จะได้รับแจ้งเตือนก่อนตัดบัตร (ระยะถัดไป)">
                        <flux:select.option value="">เลือกผู้รับผิดชอบ</flux:select.option>
                        @foreach ($this->users as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->name }} · {{ $option->role->label() }}{{ $option->isActive() ? '' : ' (ปิดใช้งาน)' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($editing && ! $editing->responsible->isActive())
                        <flux:text class="mt-1 text-xs !text-amber-700">ผู้รับผิดชอบเดิมถูกปิดบัญชีแล้ว ควรเปลี่ยนเป็นคนที่ยังใช้งานอยู่</flux:text>
                    @endif
                </div>
            </div>
        </x-ui.card>

        {{-- Billing --}}
        <x-ui.card>
            <x-ui.section-header title="รอบบิลและการชำระเงิน" icon="credit-card" />
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:select wire:model="credit_card_id" label="บัตรที่ใช้ชำระ">
                        <flux:select.option value="">เลือกบัตร</flux:select.option>
                        @foreach ($this->cards as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->name }} · {{ $option->issuer }} · {{ $option->maskedNumber() }}{{ $option->isActive() ? '' : ' (ปิดใช้งาน)' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($editing && ! $editing->creditCard->isActive())
                        <flux:text class="mt-1 text-xs !text-red-700" data-test="card-deactivated-warning">บัตรเดิมถูกปิดใช้งานแล้ว การตัดเงินอาจไม่ผ่าน ควรเปลี่ยนเป็นบัตรที่ยังใช้งานอยู่</flux:text>
                    @endif
                    @if ($this->cards->isEmpty())
                        <flux:text class="mt-1 text-xs">ยังไม่มีบัตรที่ใช้งานอยู่ <a href="{{ route('credit-cards.index') }}" class="underline" wire:navigate>เพิ่มบัตรก่อน</a></flux:text>
                    @endif
                </div>

                <flux:select wire:model.live="billing_cycle" label="รอบบิล">
                    @foreach (BillingCycle::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input type="date" wire:model.live="next_charge_date" label="วันตัดบัตรครั้งถัดไป" required />

                <div class="sm:col-span-2">
                    <flux:select wire:model.live="billing_day" label="วันตัดตามรอบ" description="ถ้าเดือนนั้นมีไม่ถึงวันที่เลือก ระบบจะใช้วันสุดท้ายของเดือน">
                        <flux:select.option value="">เลือกวันที่</flux:select.option>
                        @foreach (range(1, 31) as $day)
                            <flux:select.option value="{{ $day }}">{{ $day === 31 ? 'ทุกวันที่ 31 / สิ้นเดือน' : 'ทุกวันที่ '.$day }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($isShortMonthEnd && $billing_day !== '31')
                        <flux:text class="mt-1 text-xs" data-test="month-end-hint">
                            วันที่เลือกเป็นวันสิ้นเดือน ถ้าบริการตัดทุกสิ้นเดือน
                            <button type="button" class="font-medium text-indigo-600 underline" wire:click="$set('billing_day', '31')">เลือก "สิ้นเดือน"</button>
                        </flux:text>
                    @endif
                </div>

                <flux:select wire:model="currency" label="สกุลเงิน">
                    @foreach (Currency::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="amount" label="ยอดประมาณการต่อรอบ" placeholder="เช่น 590 หรือ 54.99" inputmode="decimal" required autocomplete="off" />

                <flux:input type="number" wire:model="remind_days_before" label="แจ้งเตือนล่วงหน้า (วัน)" min="0" max="30" description="ใช้ในระบบแจ้งเตือนระยะถัดไป" />
            </div>

            @if ($this->preview)
                <div class="mt-5 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200/70" data-test="schedule-preview">
                    <p class="text-sm font-medium text-slate-700">วันตัดบัตร 3 รอบถัดไป</p>
                    <ol class="mt-2 flex flex-wrap gap-2 text-sm">
                        @foreach ($this->preview as $previewDate)
                            <li class="rounded-lg bg-white px-3 py-1 text-slate-700 ring-1 ring-slate-200">{{ ThaiFormat::date($previewDate) }}</li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card>
            <flux:textarea wire:model="notes" label="หมายเหตุ" rows="3" maxlength="2000" placeholder="เช่น ใช้ทีมออกแบบ 3 ที่นั่ง ต่ออายุอัตโนมัติ" />
            <flux:text class="mt-1 text-xs">ห้ามใส่เลขบัตรเต็ม รหัส CVV หรือรหัสผ่านของบริการ</flux:text>
        </x-ui.card>

        @if ($duplicateWarnings)
            <flux:callout icon="exclamation-triangle" color="amber" data-test="duplicate-warning">
                <flux:callout.heading>อาจเป็นบริการที่มีอยู่แล้ว</flux:callout.heading>
                <flux:callout.text>
                    พบ Subscription ที่ยังไม่ยกเลิก ซึ่งมีชื่อบริการและผู้ให้บริการเดียวกัน:
                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($duplicateWarnings as $warning)
                            <li><strong>{{ $warning['name'] }}</strong> <span class="block text-xs">{{ $warning['detail'] }}</span></li>
                        @endforeach
                    </ul>
                    <span class="mt-2 block">ถ้าเป็นบริการเดียวกัน ให้แก้ไขรายการเดิมแทน เพื่อไม่ให้ยอดซ้ำ</span>
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" variant="primary" wire:click="saveAnyway" data-test="save-anyway-button">เป็นคนละรายการ บันทึกต่อ</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <flux:button variant="ghost" :href="$editing ? route('subscriptions.show', $editing) : route('subscriptions.index')" wire:navigate class="w-full sm:w-auto">ยกเลิก</flux:button>
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-subscription-button">บันทึก</flux:button>
        </div>
    </form>
</div>
