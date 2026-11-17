<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One way the company pays: a card, a bank account (transfer / PromptPay /
 * direct debit) or cash. Subscriptions and charges will point here in later
 * steps of Phase 3.1; general expenses and advances can reuse it later.
 *
 * A card method points to a CreditCard, a transfer / direct-debit method to a
 * BankAccount, cash to neither (see PaymentMethodType). Never deleted,
 * only deactivated.
 *
 * @property int $id
 * @property string $name
 * @property PaymentMethodType $type
 * @property SettlementMode $settlement
 * @property int|null $credit_card_id
 * @property int|null $bank_account_id
 * @property Carbon|null $deactivated_at
 * @property-read CreditCard|null $creditCard
 * @property-read BankAccount|null $bankAccount
 */
#[Fillable(['name', 'type', 'settlement', 'credit_card_id', 'bank_account_id'])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'settlement' => SettlementMode::class,
            'deactivated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Can be chosen for something new: the method itself is active and so
     * is the card or bank account behind it. Old data that already points at
     * an unusable method keeps showing; it is just not offered again.
     */
    public function isUsable(): bool
    {
        return $this->isActive()
            && ($this->creditCard === null || $this->creditCard->isActive())
            && ($this->bankAccount === null || $this->bankAccount->isActive());
    }

    public function isAuto(): bool
    {
        return $this->settlement === SettlementMode::Auto;
    }

    /**
     * True when the card / bank-account link matches what the type needs.
     */
    public function hasValidTarget(): bool
    {
        return ($this->type->requiresCard() ? $this->credit_card_id !== null : $this->credit_card_id === null)
            && ($this->type->requiresBankAccount() ? $this->bank_account_id !== null : $this->bank_account_id === null);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<SubscriptionCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }
}
