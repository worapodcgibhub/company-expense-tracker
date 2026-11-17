<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ChargeConflictException;
use App\Models\Concerns\ResolvesPaymentMethod;
use App\Support\BillingSchedule;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * A recurring service the company pays by card, bank transfer / PromptPay or
 * direct debit (see PaymentMethod). `credit_card_id` is only set for card methods.
 *
 * - `estimated_amount` is the expected amount (minor units of `currency`).
 *   Actual charged amounts are SubscriptionCharge rows, created only by
 *   recordCharge(), which also moves `next_charge_date` to the next cycle.
 * - `status` is not mass-assignable. It only changes through
 *   changeStatus(), which also writes a SubscriptionStatusChange row, so
 *   pausing or cancelling never loses history. Nothing is ever deleted.
 * - `billing_day` is the contract day the schedule is computed from
 *   (App\Support\BillingSchedule).
 *
 * @property int $id
 * @property string $name
 * @property string $vendor
 * @property SubscriptionCategory $category
 * @property int $responsible_user_id
 * @property int|null $credit_card_id
 * @property int|null $payment_method_id
 * @property BillingCycle $billing_cycle
 * @property int $billing_day
 * @property CarbonImmutable $next_charge_date
 * @property Currency $currency
 * @property int $estimated_amount
 * @property int $remind_days_before
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $responsible
 * @property-read CreditCard|null $creditCard
 * @property-read PaymentMethod|null $paymentMethod
 */
#[Fillable([
    'name', 'vendor', 'category', 'responsible_user_id', 'credit_card_id', 'payment_method_id', 'billing_cycle',
    'billing_day', 'next_charge_date', 'currency', 'estimated_amount', 'remind_days_before', 'notes',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use ResolvesPaymentMethod;

    /**
     * Allowed status changes: from => [to, ...].
     */
    private const TRANSITIONS = [
        'active' => ['paused', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        'cancelled' => ['active'],
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'remind_days_before' => 3,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SubscriptionCategory::class,
            'billing_cycle' => BillingCycle::class,
            'billing_day' => 'integer',
            'next_charge_date' => 'date',
            'currency' => Currency::class,
            'estimated_amount' => 'integer',
            'remind_days_before' => 'integer',
            'status' => SubscriptionStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    public function canChangeTo(SubscriptionStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->status->value], true);
    }

    /**
     * Changes the status and records who did it, when and why.
     * Coming back to Active needs a (future) next charge date.
     */
    public function changeStatus(SubscriptionStatus $to, User $by, ?string $reason = null, ?DateTimeInterface $nextChargeDate = null): void
    {
        if (! $this->canChangeTo($to)) {
            throw new LogicException("Cannot change a subscription from {$this->status->value} to {$to->value}.");
        }

        if ($to === SubscriptionStatus::Active && $nextChargeDate === null) {
            throw new LogicException('A next charge date is required to make a subscription active again.');
        }

        DB::transaction(function () use ($to, $by, $reason, $nextChargeDate) {
            $from = $this->status;

            if ($nextChargeDate !== null) {
                $this->next_charge_date = CarbonImmutable::instance($nextChargeDate)->startOfDay();
            }

            $this->forceFill([
                'status' => $to,
                'status_changed_at' => now(),
                'updated_by' => $by->id,
            ])->save();

            $this->statusChanges()->create([
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                'changed_by' => $by->id,
            ]);
        });
    }

    /**
     * Whole days from $today to the next charge (negative = overdue).
     */
    public function daysUntilCharge(?DateTimeInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();

        return (int) $today->diffInDays($this->next_charge_date->startOfDay(), false);
    }

    /**
     * Only meaningful for active subscriptions: paused/cancelled ones are not
     * expected to be charged.
     */
    public function isOverdue(?DateTimeInterface $today = null): bool
    {
        return $this->isActive() && $this->daysUntilCharge($today) < 0;
    }

    /**
     * Paid by someone by hand (transfer / PromptPay / cash) and confirmed in
     * the system, as opposed to taken automatically (card, direct debit).
     * The setting comes from the payment method.
     */
    public function isManual(): bool
    {
        return $this->paymentMethod !== null && ! $this->paymentMethod->isAuto();
    }

    /**
     * Not cancelled, but its card has been deactivated: the next charge may
     * fail and the service may stop.
     */
    public function needsNewCard(): bool
    {
        return ! $this->isCancelled() && $this->creditCard !== null && ! $this->creditCard->isActive();
    }

    /**
     * Not cancelled, its card (if any) is fine, but its payment method has
     * been deactivated, or the bank account behind it has: the next payment
     * may not go through. Nothing is changed for the subscription; the person
     * responsible has to choose another method.
     */
    public function needsNewPaymentMethod(): bool
    {
        return ! $this->isCancelled()
            && ! $this->needsNewCard()
            && $this->paymentMethod !== null
            && ! $this->paymentMethod->isUsable();
    }

    /**
     * New subscriptions (or a changed card) cannot use a method that is
     * switched off. See ResolvesPaymentMethod.
     */
    protected function rejectsUnusablePaymentMethod(): bool
    {
        return true;
    }

    /**
     * "อีก 3 วัน", "วันนี้", "พรุ่งนี้", "เลยกำหนด 2 วัน".
     */
    public function dueLabel(?DateTimeInterface $today = null): string
    {
        $days = $this->daysUntilCharge($today);

        return match (true) {
            $days < 0 => 'เลยกำหนด '.abs($days).' วัน',
            $days === 0 => 'วันนี้',
            $days === 1 => 'พรุ่งนี้',
            default => "อีก {$days} วัน",
        };
    }

    /**
     * "$54.99 / เดือน"
     */
    public function amountLabel(): string
    {
        return ThaiFormat::money($this->estimated_amount, $this->currency->value).' '.$this->billing_cycle->perLabel();
    }

    /**
     * Estimated cost per month in minor units (yearly ÷ 12, rounded half up).
     */
    public function monthlyEstimate(): int
    {
        return $this->billing_cycle === BillingCycle::Yearly
            ? intdiv($this->estimated_amount * 2 + 12, 24)
            : $this->estimated_amount;
    }

    /**
     * Possible duplicates: not cancelled, same service name and vendor
     * (ignoring case and spaces). Only used to warn, never to block.
     *
     * @return Collection<int, Subscription>
     */
    public static function possibleDuplicatesOf(string $name, string $vendor, ?int $ignoreId = null): Collection
    {
        $nameKey = self::normalize($name);
        $vendorKey = self::normalize($vendor);

        return self::query()
            ->with('paymentMethod')
            ->where('status', '!=', SubscriptionStatus::Cancelled->value)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Subscription $subscription) => self::normalize($subscription->name) === $nameKey
                && self::normalize($subscription->vendor) === $vendorKey)
            ->values();
    }

    public static function normalize(string $text): string
    {
        return Str::lower((string) preg_replace('/\s+/u', '', $text));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<SubscriptionStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(SubscriptionStatusChange::class);
    }

    /**
     * @return HasMany<SubscriptionCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
    }

    /**
     * Whether the current cycle (next_charge_date) can be recorded today:
     * active, and due within SubscriptionCharge::RECORD_AHEAD_DAYS days or
     * already past. Overdue cycles are always recordable, one at a time.
     */
    public function canRecordChargeOn(?DateTimeInterface $today = null): bool
    {
        return $this->isActive()
            && $this->daysUntilCharge($today) <= SubscriptionCharge::RECORD_AHEAD_DAYS;
    }

    /**
     * Records what happened in the cycle due on $dueDate and moves
     * next_charge_date to the following cycle, in one transaction.
     *
     * Guards against double recording: the subscription row is re-read
     * inside the transaction (locked where the database supports it), the
     * cycle must still be the current one, and the unique index on
     * (subscription_id, due_date) is the final safety net.
     *
     * The payment method actually used goes in `payment_method_id` (or, as
     * before, only `credit_card_id`); see SubscriptionCharge::normalizeValues().
     * `recorded_by` ($by) is who recorded the Auto charge or confirmed the
     * Manual payment.
     *
     * @param  array{outcome: ChargeOutcome, charged_on: ?DateTimeInterface, amount: int, amount_thb: ?int, payment_method_id?: int|null, credit_card_id?: int|null, notes: ?string}  $values
     *
     * @throws ChargeConflictException
     */
    public function recordCharge(DateTimeInterface $dueDate, array $values, User $by, bool $confirmClosedPeriod = false): SubscriptionCharge
    {
        $dueDate = CarbonImmutable::instance($dueDate)->startOfDay();
        $values = SubscriptionCharge::normalizeValues($values, $this->currency);

        if ($values['outcome'] === ChargeOutcome::Charged && $values['charged_on'] === null) {
            throw new LogicException('A charged cycle needs the date it was charged.');
        }

        $period = $values['charged_on'] !== null ? AccountingPeriodClosure::periodOf($values['charged_on']) : null;
        $inClosed = $period !== null && AccountingPeriodClosure::isClosed($period);

        if ($inClosed && ! $confirmClosedPeriod) {
            throw new ChargeConflictException('วันที่ตัดเงินอยู่ในงวดบัญชีที่ปิดแล้ว ต้องยืนยันว่าเป็นรายการย้อนหลังก่อน');
        }

        $charge = DB::transaction(function () use ($dueDate, $values, $by, $inClosed) {
            /** @var Subscription $current */
            $current = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! $current->isActive()) {
                throw new ChargeConflictException('Subscription นี้ไม่ได้อยู่ในสถานะใช้งาน จึงบันทึกยอดไม่ได้');
            }

            if (! $current->next_charge_date->isSameDay($dueDate)) {
                throw new ChargeConflictException('รอบนี้ถูกบันทึกไปแล้ว หรือวันตัดบัตรถูกเปลี่ยน กรุณาโหลดหน้าใหม่แล้วตรวจอีกครั้ง');
            }

            if (! $current->canRecordChargeOn()) {
                throw new ChargeConflictException('ยังไม่ถึงช่วงที่บันทึกยอดของรอบนี้ได้');
            }

            $charge = new SubscriptionCharge;
            $charge->forceFill([
                ...$values,
                'subscription_id' => $current->id,
                'due_date' => $dueDate,
                'currency' => $current->currency,
                'entered_after_close' => $inClosed,
                'recorded_by' => $by->id,
                'updated_by' => $by->id,
            ]);

            try {
                $charge->save();
            } catch (UniqueConstraintViolationException) {
                throw new ChargeConflictException('รอบนี้ถูกบันทึกไปแล้ว กรุณาโหลดหน้าใหม่แล้วตรวจอีกครั้ง');
            }

            $charge->writeRevision(ChargeRevisionAction::Recorded, $by, SubscriptionCharge::storable($values), null, $inClosed);

            $current->forceFill([
                'next_charge_date' => BillingSchedule::next($dueDate, $current->billing_cycle, $current->billing_day),
                'updated_by' => $by->id,
            ])->save();

            return $charge;
        });

        $this->refresh();

        return $charge;
    }

    /**
     * Owner and Accountant see every subscription; anyone else only the
     * ones they are responsible for. Always use this for listings, so the
     * restriction is enforced in the query and not only in the UI.
     *
     * @param  Builder<Subscription>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! ($user->isOwner() || $user->isAccountant())) {
            $query->where('responsible_user_id', $user->id);
        }
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Active->value);
    }
}
