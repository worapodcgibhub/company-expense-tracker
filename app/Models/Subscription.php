<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * A recurring service the company pays by card.
 *
 * - `estimated_amount` is the expected amount (minor units of `currency`).
 *   Actual charged amounts are recorded separately from Phase 2.4.
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
 * @property int $credit_card_id
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
 * @property-read CreditCard $creditCard
 */
#[Fillable([
    'name', 'vendor', 'category', 'responsible_user_id', 'credit_card_id', 'billing_cycle',
    'billing_day', 'next_charge_date', 'currency', 'estimated_amount', 'remind_days_before', 'notes',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

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
     * Not cancelled, but its card has been deactivated: the next charge may
     * fail and the service may stop.
     */
    public function needsNewCard(): bool
    {
        return ! $this->isCancelled() && ! $this->creditCard->isActive();
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
            ->with('creditCard')
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
