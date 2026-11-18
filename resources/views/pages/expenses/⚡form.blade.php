<?php

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Models\PaymentMethod;
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
 * Add or change a one-off expense (ExpensePolicy::create / update), authorized
 * again on save because Livewire actions can be called directly.
 *
 * - THB only (mini version). The person adding it is the owner of the row.
 * - The payment date may not be in the future, and may not fall in a closed
 *   accounting month (AccountingPeriodClosure): a closed month is never
 *   changed. To add something there the Owner has to reopen the month first.
 * - Receipt: only "document received" or "waiting" for now (no file upload).
 */
new #[Title('ค่าใช้จ่ายทั่วไป')] class extends Component {
    #[Locked]
    public ?int $expenseId = null;

    public string $title = '';
    public string $paid_on = '';
    public string $amount = '';
    public string $category = 'equipment';
    public string $payment_method_id = '';
    public string $notes = '';
    public bool $receipt_received = false;

    public function mount(?Expense $expense = null): void
    {
        if ($expense !== null && $expense->exists) {
            $this->authorize('update', $expense);

            $this->expenseId = $expense->id;
            $this->title = $expense->title;
            $this->paid_on = $expense->paid_on->format('Y-m-d');
            $this->amount = MoneyInput::fromMinor($expense->amount);
            $this->category = $expense->category->value;
            $this->payment_method_id = (string) $expense->payment_method_id;
            $this->notes = $expense->notes ?? '';
            $this->receipt_received = $expense->receipt_received_at !== null;

            return;
        }

        $this->authorize('create', Expense::class);

        $this->paid_on = CarbonImmutable::today()->format('Y-m-d');
    }

    #[Computed]
    public function expense(): ?Expense
    {
        return $this->expenseId !== null ? Expense::findOrFail($this->expenseId) : null;
    }

    /**
     * Methods that can be chosen now (active), plus the one the expense
     * already uses when it has since been switched off.
     *
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): Collection
    {
        $currentId = $this->expense?->payment_method_id;

        return PaymentMethod::query()
            ->with(['creditCard', 'bankAccount'])
            ->orderBy('name')
            ->get()
            ->filter(fn (PaymentMethod $method) => $method->isUsable() || $method->id === $currentId)
            ->values();
    }

    /**
     * Only Owner and Accountant see which account or card is behind a method.
     */
    public function methodLabel(PaymentMethod $method): string
    {
        $user = auth()->user();
        $target = ($user->isOwner() || $user->isAccountant())
            ? ($method->creditCard?->maskedNumber() ?? $method->bankAccount?->maskedNumber() ?? '')
            : '';

        return $method->name.($target !== '' ? ' · '.$target : '').($method->isActive() ? '' : ' (ปิดใช้งาน)');
    }

    /**
     * The month of the chosen date, when that accounting month is closed.
     */
    #[Computed]
    public function closedPeriod(): ?string
    {
        $date = $this->parsedDate();

        if ($date === null) {
            return null;
        }

        $period = AccountingPeriodClosure::periodOf($date);

        return AccountingPeriodClosure::isClosed($period) ? $period : null;
    }

    public function save(): void
    {
        $expense = $this->expense;

        $expense
            ? $this->authorize('update', $expense)
            : $this->authorize('create', Expense::class);

        foreach (['title', 'paid_on', 'amount', 'notes'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $today = CarbonImmutable::today();
        $methodIds = $this->methods->pluck('id')->all();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:150', new NoFullCardNumber],
            'paid_on' => ['required', 'date_format:Y-m-d', function ($attribute, $value, $fail) use ($today) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value);

                if ($date === false || $date->format('Y-m-d') !== $value) {
                    $fail('วันที่ไม่ถูกต้อง');
                } elseif ($date->greaterThan($today)) {
                    $fail('วันที่จ่ายต้องไม่เกินวันนี้');
                }
            }],
            'amount' => ['required', 'string', function ($attribute, $value, $fail) {
                $minor = MoneyInput::toMinor((string) $value);

                if ($minor === null || $minor > MoneyInput::MAX_MINOR) {
                    $fail('กรอกยอดเป็นตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง เช่น 590 หรือ 1,234.56');
                } elseif ($minor === 0) {
                    $fail('ยอดต้องมากกว่า 0');
                }
            }],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'payment_method_id' => ['required', 'integer', Rule::in($methodIds)],
            'notes' => ['nullable', 'string', 'max:2000', new NoFullCardNumber],
        ], [
            'title.required' => 'กรุณาระบุชื่อรายการ',
            'paid_on.required' => 'กรุณาระบุวันที่จ่าย',
            'amount.required' => 'กรุณากรอกจำนวนเงิน',
            'payment_method_id.in' => 'กรุณาเลือกช่องทางชำระเงินที่ใช้งานอยู่',
        ], [
            'title' => 'ชื่อรายการ',
            'paid_on' => 'วันที่จ่าย',
            'amount' => 'จำนวนเงิน',
            'category' => 'หมวดหมู่',
            'payment_method_id' => 'ช่องทางชำระเงิน',
            'notes' => 'หมายเหตุ',
        ]);

        unset($this->closedPeriod);

        if ($this->closedPeriod !== null) {
            $this->addError('paid_on', 'วันที่นี้อยู่ในงวดบัญชีที่ปิดแล้ว บันทึกหรือแก้ไขไม่ได้ ให้ Owner เปิดงวดนั้นใหม่ก่อน หรือเลือกวันที่ในงวดที่ยังไม่ปิด');

            return;
        }

        $user = auth()->user();
        $received = $this->receipt_received
            ? ($expense?->receipt_received_at ?? now())
            : null;

        $values = [
            'title' => $validated['title'],
            'category' => ExpenseCategory::from($validated['category']),
            'paid_on' => CarbonImmutable::createFromFormat('!Y-m-d', $validated['paid_on']),
            'currency' => Currency::THB,
            'amount' => (int) MoneyInput::toMinor($validated['amount']),
            'amount_thb' => null,
            'payment_method_id' => (int) $validated['payment_method_id'],
            'notes' => ($validated['notes'] ?? '') !== '' ? $validated['notes'] : null,
            'receipt_received_at' => $received,
            'updated_by' => $user->id,
        ];

        if ($expense !== null) {
            $expense->forceFill($values)->save();
        } else {
            (new Expense)->forceFill([...$values, 'owner_user_id' => $user->id, 'recorded_by' => $user->id])->save();
        }

        Flux::toast(variant: 'success', text: $expense ? 'บันทึกการแก้ไขแล้ว' : 'บันทึกค่าใช้จ่ายแล้ว');
        $this->redirectRoute('expenses.index', navigate: true);
    }

    private function parsedDate(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->paid_on) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->paid_on);

        return $date !== false && $date->format('Y-m-d') === $this->paid_on ? $date : null;
    }
}; ?>

@php
    $editing = $this->expense;
    $closed = $this->closedPeriod;
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <header class="flex flex-col gap-2">
        <a href="{{ route('expenses.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <flux:icon.arrow-left class="size-4" /> กลับไปหน้ารายการ
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl" data-test="expense-heading">{{ $editing ? 'แก้ไขค่าใช้จ่ายทั่วไป' : 'เพิ่มค่าใช้จ่ายทั่วไป' }}</h1>
        <p class="text-sm text-slate-500">สำหรับค่าใช้จ่ายที่จ่ายครั้งเดียว เช่น ซื้ออุปกรณ์ ค่าซ่อม ค่าเดินทาง ไม่ใช่ค่าบริการรายเดือนหรือรายปี</p>
    </header>

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card>
            <x-ui.section-header title="รายละเอียดค่าใช้จ่าย" icon="banknotes" />
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:input wire:model="title" label="ชื่อรายการ" placeholder="เช่น ซื้อเมาส์และคีย์บอร์ด" maxlength="150" required autocomplete="off" />
                </div>

                <div>
                    <flux:input type="date" wire:model.live="paid_on" label="วันที่จ่าย" :max="now()->format('Y-m-d')" required />
                </div>

                <div>
                    <flux:input wire:model="amount" label="จำนวนเงิน (บาท)" placeholder="เช่น 590 หรือ 1,234.56" inputmode="decimal" required autocomplete="off" />
                </div>

                <div>
                    <flux:select wire:model="category" label="หมวดหมู่">
                        @foreach (ExpenseCategory::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <flux:select wire:model="payment_method_id" label="ช่องทางชำระเงิน" placeholder="เลือกช่องทาง">
                        @foreach ($this->methods as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $this->methodLabel($option) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="notes" label="หมายเหตุ" rows="2" maxlength="2000" />
                    <flux:text class="mt-1 text-xs">ห้ามใส่เลขบัตรเต็ม รหัส CVV หรือรหัสผ่าน</flux:text>
                </div>
            </div>
        </x-ui.card>

        @if ($closed)
            <flux:callout icon="lock-closed" color="amber" data-test="closed-period-warning">
                <flux:callout.heading>งวดบัญชีปิดแล้ว: {{ ThaiFormat::monthYear(AccountingPeriodClosure::startOf($closed)) }}</flux:callout.heading>
                <flux:callout.text>บันทึกหรือแก้ไขรายการในงวดที่ปิดแล้วไม่ได้ เลือกวันที่ในงวดที่ยังไม่ปิด หรือให้ Owner เปิดงวดนั้นใหม่ก่อน</flux:callout.text>
            </flux:callout>
        @endif

        <x-ui.card>
            <x-ui.section-header title="ใบเสร็จ / เอกสาร" icon="document-text" />
            <flux:checkbox wire:model="receipt_received" label="ได้รับใบเสร็จหรือเอกสารแล้ว" data-test="receipt-received" />
            <flux:text class="mt-1 text-xs">ถ้ายังไม่ได้รับ ไม่ต้องติ๊ก รายการจะแสดงเป็น "รอใบเสร็จ" จนกว่าจะกลับมาติ๊ก</flux:text>
        </x-ui.card>

        <flux:callout icon="information-circle" color="sky">
            <flux:callout.text>ถ้าเป็นค่าใช้จ่ายที่ตัดซ้ำทุกเดือนหรือทุกปี ให้ลงเป็น Subscription แทน เพื่อไม่ให้ยอดซ้ำกัน</flux:callout.text>
        </flux:callout>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <flux:button variant="ghost" :href="route('expenses.index')" wire:navigate class="w-full sm:w-auto">ยกเลิก</flux:button>
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" data-test="save-expense-button">บันทึก</flux:button>
        </div>
    </form>
</div>
