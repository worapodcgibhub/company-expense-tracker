<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Enums\ReceiptStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off expense: money really paid once, not part of a subscription.
 *
 * - Separate from SubscriptionCharge: nothing here is ever counted in a
 *   subscription's history or totals.
 * - `amount` is in minor units of `currency`. `amount_thb` is the baht
 *   really paid for a USD expense; it is NULL until known. No exchange rate
 *   is assumed: thbAmount() returns null rather than guessing.
 * - `paid_on` decides the accounting period (see period() /
 *   isInClosedPeriod()), using the existing AccountingPeriodClosure.
 * - `owner_user_id` is who the expense belongs to; Staff only see and edit
 *   their own. `recorded_by` / `updated_by` are who entered / changed it.
 * - Receipts (mini version): `receipt_received_at` set = the document has
 *   been received; NULL = waiting for it. File uploads come later.
 *
 * @property int $id
 * @property string $title
 * @property ExpenseCategory $category
 * @property CarbonImmutable $paid_on
 * @property Currency $currency
 * @property int $amount
 * @property int|null $amount_thb
 * @property int $payment_method_id
 * @property int $owner_user_id
 * @property int|null $recorded_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $receipt_received_at
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PaymentMethod $paymentMethod
 * @property-read User $owner
 * @property-read User|null $recorder
 * @property-read User|null $updater
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'paid_on' => 'date',
            'currency' => Currency::class,
            'amount' => 'integer',
            'amount_thb' => 'integer',
            'receipt_received_at' => 'datetime',
        ];
    }

    /**
     * A USD expense whose baht amount has not been filled in yet.
     */
    public function needsThbAmount(): bool
    {
        return $this->currency !== Currency::THB && $this->amount_thb === null;
    }

    /**
     * The baht really paid (satang), or null when not known yet.
     */
    public function thbAmount(): ?int
    {
        return $this->currency === Currency::THB ? $this->amount : $this->amount_thb;
    }

    /**
     * Accounting period ("YYYY-MM") of the day it was paid.
     */
    public function period(): string
    {
        return AccountingPeriodClosure::periodOf($this->paid_on);
    }

    public function isInClosedPeriod(): bool
    {
        return AccountingPeriodClosure::isClosed($this->period());
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }

    /**
     * Attached (document received) or Waiting.
     */
    public function receiptStatus(): ReceiptStatus
    {
        return $this->receipt_received_at !== null
            ? ReceiptStatus::Attached
            : ReceiptStatus::Waiting;
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Owner and Accountant see every expense, anyone else only their own.
     * Always use this for listings and totals.
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! ($user->isOwner() || $user->isAccountant())) {
            $query->where('owner_user_id', $user->id);
        }
    }

    /**
     * Paid in the calendar month of $month (half-open range, like charges).
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function paidInMonth(Builder $query, DateTimeInterface $month): void
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();

        $query->where('paid_on', '>=', $start->format('Y-m-d'))
            ->where('paid_on', '<', $start->addMonth()->format('Y-m-d'));
    }
}
