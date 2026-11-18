<?php

namespace App\Models;

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\Currency;
use App\Enums\DataSet;
use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Exceptions\ChargeConflictException;
use App\Exceptions\PaymentMethodMismatchException;
use App\Models\Concerns\ResolvesPaymentMethod;
use App\Support\ReceiptStorage;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionChargeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * What actually happened in one billing cycle of a subscription.
 *
 * - One row per cycle: (subscription_id, due_date) is unique. New rows are
 *   only created by Subscription::recordCharge().
 * - `amount` is in minor units of `currency` (the vendor's currency).
 *   For USD, `amount_thb` is the baht the bank actually took; it may be
 *   filled in later. THB and USD are never added together: baht totals use
 *   thbAmount(), and USD charges without `amount_thb` are reported apart.
 * - Never deleted. Every change goes through revise() / verify() /
 *   unverify(), which write a SubscriptionChargeRevision row (who, when,
 *   old → new values).
 * - `entered_after_close` is set (and stays set) when the charge was
 *   recorded or changed while its month was a closed accounting period.
 *   The closed period itself is never changed.
 * - Phase 3.1e-3: `payment_method_id` is how it was really paid (card,
 *   transfer / PromptPay, direct debit, cash); `credit_card_id` is only set
 *   for card methods. `recorded_by` is who recorded the Auto charge or
 *   confirmed the Manual payment. Changing a subscription's method later
 *   never changes the charges already recorded.
 * - Documents (Phase 2.5): a charge can be recorded without any receipt
 *   and documents attached later (attachReceipt). receiptStatus() tells
 *   "waiting" apart from "no receipt available" (receipt_unavailable_*,
 *   always with a reason). Removing or replacing a document never touches
 *   the charge's amounts.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int|null $credit_card_id
 * @property int|null $payment_method_id
 * @property CarbonImmutable $due_date
 * @property ChargeOutcome $outcome
 * @property CarbonImmutable|null $charged_on
 * @property Currency $currency
 * @property int $amount
 * @property int|null $amount_thb
 * @property bool $entered_after_close
 * @property string|null $notes
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property int|null $recorded_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $receipt_unavailable_at
 * @property int|null $receipt_unavailable_by
 * @property string|null $receipt_unavailable_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Subscription $subscription
 * @property-read CreditCard|null $creditCard
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read User|null $recorder
 * @property-read User|null $updater
 * @property-read User|null $verifier
 * @property-read User|null $receiptUnavailableMarker
 */
class SubscriptionCharge extends Model
{
    /** @use HasFactory<SubscriptionChargeFactory> */
    use HasFactory;

    use ResolvesPaymentMethod;

    /**
     * A cycle may be recorded from this many days before its due date
     * (vendors sometimes charge a little early).
     */
    public const RECORD_AHEAD_DAYS = 7;

    /**
     * The actual charge date may be at most this many days before the due
     * date of its cycle. Any later date up to today is fine (backdating).
     */
    public const MAX_DAYS_BEFORE_DUE = 31;

    /**
     * Fields a person can change after recording (due_date never changes:
     * it identifies the cycle).
     */
    public const EDITABLE = ['outcome', 'charged_on', 'amount', 'amount_thb', 'payment_method_id', 'credit_card_id', 'notes'];

    /**
     * Changing any of these takes back the accountant's verification.
     */
    private const VERIFIED_FIELDS = ['outcome', 'charged_on', 'amount', 'amount_thb', 'payment_method_id', 'credit_card_id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount' => 0,
        'entered_after_close' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'outcome' => ChargeOutcome::class,
            'charged_on' => 'date',
            'currency' => Currency::class,
            'amount' => 'integer',
            'amount_thb' => 'integer',
            'entered_after_close' => 'boolean',
            'verified_at' => 'datetime',
            'receipt_unavailable_at' => 'datetime',
        ];
    }

    public function isCharged(): bool
    {
        return $this->outcome === ChargeOutcome::Charged;
    }

    /**
     * Documents not removed. Uses `current_receipts_count` when the query
     * loaded it (withCurrentReceiptCount), so lists do not query per row.
     */
    public function currentReceiptCount(): int
    {
        if (array_key_exists('current_receipts_count', $this->attributes)) {
            return (int) $this->attributes['current_receipts_count'];
        }

        return $this->receipts()->current()->count();
    }

    public function receiptStatus(): ReceiptStatus
    {
        return match (true) {
            ! $this->isCharged() => ReceiptStatus::NotNeeded,
            $this->currentReceiptCount() > 0 => ReceiptStatus::Attached,
            $this->receipt_unavailable_at !== null => ReceiptStatus::Unavailable,
            default => ReceiptStatus::Waiting,
        };
    }

    /**
     * Stores the file on the private disk and attaches it. With $replaces,
     * the old document is removed (kept, with $reason) in the same step.
     * A "no receipt available" note is cleared, since there is one now.
     * The charge's amounts are never touched.
     *
     * @throws ChargeConflictException
     */
    public function attachReceipt(
        UploadedFile $file,
        ReceiptKind $kind,
        ?string $documentNumber,
        ?DateTimeInterface $documentDate,
        User $by,
        ?ChargeReceipt $replaces = null,
        ?string $reason = null,
    ): ChargeReceipt {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if (! $this->isCharged()) {
            throw new ChargeConflictException('รอบนี้ไม่ถูกตัดเงิน จึงไม่ต้องแนบเอกสาร');
        }

        if ($replaces !== null && ($replaces->subscription_charge_id !== $this->id || $replaces->isRemoved())) {
            throw new ChargeConflictException('เอกสารที่จะเปลี่ยนถูกนำออกไปแล้ว หรือไม่ใช่ของรายการนี้ กรุณาโหลดหน้าใหม่');
        }

        if ($replaces !== null && $reason === null) {
            throw new LogicException('A reason is required to replace a document.');
        }

        if ($replaces === null && $this->receipts()->current()->count() >= ReceiptStorage::MAX_PER_CHARGE) {
            throw new ChargeConflictException('แนบเอกสารได้สูงสุด '.ReceiptStorage::MAX_PER_CHARGE.' ไฟล์ต่อรายการ ถ้าต้องการเปลี่ยน ให้ใช้ "เปลี่ยนไฟล์"');
        }

        $stored = ReceiptStorage::store($file);

        try {
            return DB::transaction(function () use ($stored, $kind, $documentNumber, $documentDate, $by, $replaces, $reason) {
                $receipt = new ChargeReceipt;
                $receipt->forceFill([
                    ...$stored,
                    'subscription_charge_id' => $this->id,
                    'kind' => $kind,
                    'document_number' => $documentNumber === null || trim($documentNumber) === '' ? null : trim($documentNumber),
                    'document_date' => $documentDate !== null ? CarbonImmutable::instance($documentDate)->startOfDay() : null,
                    'replaces_id' => $replaces?->id,
                    'uploaded_by' => $by->id,
                ])->save();

                $diff = $receipt->summary();

                if ($replaces !== null) {
                    $replaces->markRemoved($by, (string) $reason);
                    $diff['replaced'] = $replaces->summary();
                }

                if ($this->receipt_unavailable_at !== null) {
                    $this->forceFill(['receipt_unavailable_at' => null, 'receipt_unavailable_by' => null, 'receipt_unavailable_reason' => null])->save();
                    $diff['cleared_unavailable'] = true;
                }

                $this->writeRevision($replaces !== null ? ChargeRevisionAction::ReceiptReplaced : ChargeRevisionAction::ReceiptAdded, $by, $diff, $reason, false);

                return $receipt;
            });
        } catch (Throwable $e) {
            // The row was not saved: do not leave the new file behind.
            ReceiptStorage::discardUnsaved($stored['path']);

            throw $e;
        }
    }

    /**
     * "No receipt available" (always with a reason). Only while the charge
     * has no current document.
     *
     * @throws ChargeConflictException
     */
    public function markReceiptUnavailable(User $by, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('A reason is required.');
        }

        if ($this->receiptStatus() !== ReceiptStatus::Waiting) {
            throw new ChargeConflictException('รายการนี้มีเอกสารแล้ว หรือระบุว่าไม่มีใบเสร็จไปแล้ว');
        }

        DB::transaction(function () use ($by, $reason) {
            $this->forceFill([
                'receipt_unavailable_at' => now(),
                'receipt_unavailable_by' => $by->id,
                'receipt_unavailable_reason' => $reason,
            ])->save();

            $this->writeRevision(ChargeRevisionAction::ReceiptUnavailable, $by, null, $reason, false);
        });
    }

    /**
     * Back to "waiting for receipt".
     */
    public function clearReceiptUnavailable(User $by, ?string $reason = null): void
    {
        if ($this->receipt_unavailable_at === null) {
            return;
        }

        DB::transaction(function () use ($by, $reason) {
            $previous = $this->receipt_unavailable_reason;

            $this->forceFill(['receipt_unavailable_at' => null, 'receipt_unavailable_by' => null, 'receipt_unavailable_reason' => null])->save();

            $this->writeRevision(ChargeRevisionAction::ReceiptUnavailableCleared, $by, ['previous_reason' => $previous], $reason, false);
        });
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * A USD charge whose baht amount has not been filled in yet.
     */
    public function needsThbAmount(): bool
    {
        return $this->isCharged() && $this->currency !== Currency::THB && $this->amount_thb === null;
    }

    /**
     * The baht actually paid (satang), or null when not known yet.
     * Skipped cycles cost nothing.
     */
    public function thbAmount(): ?int
    {
        if (! $this->isCharged()) {
            return 0;
        }

        return $this->currency === Currency::THB ? $this->amount : $this->amount_thb;
    }

    /**
     * Accounting period ("YYYY-MM") the charge belongs to; null when skipped.
     */
    public function period(): ?string
    {
        return $this->isCharged() && $this->charged_on !== null
            ? AccountingPeriodClosure::periodOf($this->charged_on)
            : null;
    }

    /**
     * "$52.00" or "รอบนี้ไม่ถูกตัดเงิน".
     */
    public function amountLabel(): string
    {
        return $this->isCharged()
            ? ThaiFormat::money($this->amount, $this->currency->value)
            : ChargeOutcome::Skipped->label();
    }

    /**
     * Whole days between the charge date and the day it was recorded.
     */
    public function daysRecordedLate(): int
    {
        if ($this->charged_on === null || $this->created_at === null) {
            return 0;
        }

        return max(0, (int) $this->charged_on->startOfDay()->diffInDays($this->created_at->startOfDay(), false));
    }

    /**
     * Applies a person's correction and records old → new values.
     * Returns false (and writes nothing) when nothing actually changed.
     *
     * @param  array{outcome: ChargeOutcome, charged_on: ?DateTimeInterface, amount: int, amount_thb: ?int, payment_method_id?: int|null, credit_card_id?: int|null, notes: ?string}  $values
     *
     * @throws ChargeConflictException when a closed period is involved and not confirmed
     */
    public function revise(array $values, User $by, string $reason, bool $confirmClosedPeriod = false): bool
    {
        $values = self::normalizeValues($values, $this->currency);
        $before = $this->editableSnapshot();
        $after = self::storable($values);

        $changes = [];
        foreach (self::EDITABLE as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = [$before[$field], $after[$field]];
            }
        }

        if ($changes === []) {
            return false;
        }

        $periods = array_filter([$this->period(), $values['charged_on'] !== null && $values['outcome'] === ChargeOutcome::Charged ? AccountingPeriodClosure::periodOf($values['charged_on']) : null]);
        $inClosed = AccountingPeriodClosure::closedAmong(array_values($periods)) !== [];

        if ($inClosed && ! $confirmClosedPeriod) {
            throw new ChargeConflictException('รายการนี้อยู่ในงวดบัญชีที่ปิดแล้ว ต้องยืนยันว่าเป็นการแก้ไขย้อนหลังก่อน');
        }

        DB::transaction(function () use ($values, $by, $reason, $changes, $inClosed) {
            $clearsVerification = $this->isVerified() && array_intersect(array_keys($changes), self::VERIFIED_FIELDS) !== [];

            if ($clearsVerification) {
                $changes['verified'] = [true, false];
            }

            $this->forceFill([
                ...$values,
                'updated_by' => $by->id,
                'entered_after_close' => $this->entered_after_close || $inClosed,
                ...($clearsVerification ? ['verified_at' => null, 'verified_by' => null] : []),
            ])->save();

            $this->writeRevision(ChargeRevisionAction::Updated, $by, $changes, $reason, $inClosed);
        });

        return true;
    }

    /**
     * Accountant's check. Writes a revision row.
     */
    public function verify(User $by): void
    {
        if ($this->isVerified()) {
            return;
        }

        DB::transaction(function () use ($by) {
            $this->forceFill(['verified_at' => now(), 'verified_by' => $by->id])->save();
            $this->writeRevision(ChargeRevisionAction::Verified, $by, null, null, false);
        });
    }

    public function unverify(User $by, ?string $reason = null): void
    {
        if (! $this->isVerified()) {
            return;
        }

        DB::transaction(function () use ($by, $reason) {
            $this->forceFill(['verified_at' => null, 'verified_by' => null])->save();
            $this->writeRevision(ChargeRevisionAction::Unverified, $by, null, $reason, false);
        });
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|array<string, mixed>|null  $changes
     */
    public function writeRevision(ChargeRevisionAction $action, User $by, ?array $changes, ?string $reason, bool $inClosedPeriod): SubscriptionChargeRevision
    {
        return $this->revisions()->create([
            'action' => $action,
            'diff' => $changes,
            'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
            'in_closed_period' => $inClosedPeriod,
            'changed_by' => $by->id,
        ]);
    }

    /**
     * Makes the values consistent: a skipped cycle has no date or amount,
     * and only non-THB charges carry a separate baht amount.
     *
     * The payment method is given as `payment_method_id` (its card, if any,
     * is taken from the method) or, as before Phase 3.1e-3, only as
     * `credit_card_id` (the card's EXISTING method is used; none is never
     * created). Both given and not matching -> PaymentMethodMismatchException.
     *
     * @param  array{outcome: ChargeOutcome, charged_on: ?DateTimeInterface, amount: int, amount_thb: ?int, payment_method_id?: int|null, credit_card_id?: int|null, notes: ?string}  $values
     * @return array{outcome: ChargeOutcome, charged_on: ?CarbonImmutable, amount: int, amount_thb: ?int, payment_method_id: int, credit_card_id: int|null, notes: ?string}
     */
    public static function normalizeValues(array $values, Currency $currency): array
    {
        $charged = $values['outcome'] === ChargeOutcome::Charged;
        $notes = $values['notes'] === null || trim($values['notes']) === '' ? null : trim($values['notes']);
        $method = self::resolveMethod($values['payment_method_id'] ?? null, $values['credit_card_id'] ?? null);

        return [
            'outcome' => $values['outcome'],
            'charged_on' => $charged && $values['charged_on'] !== null ? CarbonImmutable::instance($values['charged_on'])->startOfDay() : null,
            'amount' => $charged ? $values['amount'] : 0,
            'amount_thb' => $charged && $currency !== Currency::THB ? $values['amount_thb'] : null,
            'payment_method_id' => $method->id,
            'credit_card_id' => $method->credit_card_id,
            'notes' => $notes,
        ];
    }

    private static function resolveMethod(?int $methodId, ?int $cardId): PaymentMethod
    {
        if ($methodId !== null) {
            $method = PaymentMethod::query()->find($methodId);

            if ($method === null) {
                throw new PaymentMethodMismatchException("ไม่พบช่องทางชำระเงิน (รหัส {$methodId})");
            }

            if ($cardId !== null && $method->credit_card_id !== $cardId) {
                throw new PaymentMethodMismatchException("ช่องทางชำระเงิน (รหัส {$methodId}) ไม่ตรงกับบัตร (รหัส {$cardId})");
            }

            return $method;
        }

        if ($cardId === null) {
            throw new LogicException('A charge needs a payment method.');
        }

        return PaymentMethod::query()->where('credit_card_id', $cardId)->first()
            ?? throw new PaymentMethodMismatchException("ไม่พบช่องทางชำระเงินของบัตร (รหัส {$cardId}) กรุณาตั้งค่าช่องทางชำระเงินของบัตรนี้ก่อน");
    }

    /**
     * Plain values (as stored) for the revision history.
     *
     * @param  array{outcome: ChargeOutcome, charged_on: ?CarbonImmutable, amount: int, amount_thb: ?int, payment_method_id: int, credit_card_id: int|null, notes: ?string}  $values
     * @return array<string, string|int|null>
     */
    public static function storable(array $values): array
    {
        return [
            'outcome' => $values['outcome']->value,
            'charged_on' => $values['charged_on']?->format('Y-m-d'),
            'amount' => $values['amount'],
            'amount_thb' => $values['amount_thb'],
            'payment_method_id' => $values['payment_method_id'],
            'credit_card_id' => $values['credit_card_id'],
            'notes' => $values['notes'],
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    public function editableSnapshot(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'charged_on' => $this->charged_on?->format('Y-m-d'),
            'amount' => $this->amount,
            'amount_thb' => $this->amount_thb,
            'payment_method_id' => $this->payment_method_id,
            'credit_card_id' => $this->credit_card_id,
            'notes' => $this->notes,
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
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
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return HasMany<SubscriptionChargeRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SubscriptionChargeRevision::class);
    }

    /**
     * Every document ever attached, removed ones included.
     *
     * @return HasMany<ChargeReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(ChargeReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiptUnavailableMarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_unavailable_by');
    }

    /**
     * Loads `current_receipts_count` (documents not removed) for lists.
     *
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function withCurrentReceiptCount(Builder $query): void
    {
        $query->withCount(['receipts as current_receipts_count' => fn (Builder $q) => $q->whereNull('removed_at')]);
    }

    /**
     * Charges whose document status is $status (same rules as receiptStatus()).
     *
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function receiptStatusIs(Builder $query, ReceiptStatus $status): void
    {
        $hasCurrent = fn (Builder $q) => $q->whereNull('removed_at');

        match ($status) {
            ReceiptStatus::NotNeeded => $query->where('outcome', '!=', ChargeOutcome::Charged->value),
            ReceiptStatus::Attached => $query->where('outcome', ChargeOutcome::Charged->value)->whereHas('receipts', $hasCurrent),
            ReceiptStatus::Unavailable => $query->where('outcome', ChargeOutcome::Charged->value)->whereDoesntHave('receipts', $hasCurrent)->whereNotNull('receipt_unavailable_at'),
            ReceiptStatus::Waiting => $query->where('outcome', ChargeOutcome::Charged->value)->whereDoesntHave('receipts', $hasCurrent)->whereNull('receipt_unavailable_at'),
        };
    }

    /**
     * Same rule as Subscription::visibleTo: Owner and Accountant see every
     * charge, anyone else only charges of subscriptions they are
     * responsible for. Always use this for listings and totals.
     *
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! ($user->isOwner() || $user->isAccountant())) {
            $query->whereIn('subscription_id', Subscription::query()->select('id')->where('responsible_user_id', $user->id));
        }
    }

    /**
     * Charges of real subscriptions, of sample ones, or both (see DataSet).
     * A charge, its documents and its history are sample data exactly when
     * their subscription is.
     *
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function inDataSet(Builder $query, DataSet $dataSet): void
    {
        if ($dataSet !== DataSet::All) {
            // Same rule as Subscription::inDataSet (also when the column does not exist yet).
            $query->whereIn('subscription_id', Subscription::query()->select('id')->inDataSet($dataSet));
        }
    }

    /**
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function charged(Builder $query): void
    {
        $query->where('outcome', ChargeOutcome::Charged->value);
    }

    /**
     * Charged in the calendar month of $month.
     *
     * Half-open range (first day inclusive, first day of the next month
     * exclusive): stored dates may carry a time ("2026-10-31 00:00:00"),
     * which a BETWEEN up to "2026-10-31" would leave out.
     *
     * @param  Builder<SubscriptionCharge>  $query
     */
    #[Scope]
    protected function chargedInMonth(Builder $query, DateTimeInterface $month): void
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();

        $query->where('outcome', ChargeOutcome::Charged->value)
            ->where('charged_on', '>=', $start->format('Y-m-d'))
            ->where('charged_on', '<', $start->addMonth()->format('Y-m-d'));
    }
}
