<?php

namespace App\Models;

use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A card the company pays services and subscriptions with.
 *
 * Only the last four digits are ever stored — never the full number, CVV,
 * expiry date or PIN. Cards are deactivated, never deleted, because
 * subscriptions (Phase 2.3) will keep referencing them for history.
 * `deactivated_at`, `created_by` and `updated_by` are not mass-assignable;
 * they are set explicitly by server-side code with forceFill().
 *
 * Subscriptions reference cards with a restricting foreign key, so a card
 * that is in use can never be deleted from the database either.
 *
 * @property int $id
 * @property string $name
 * @property string $issuer
 * @property CardNetwork $network
 * @property CardKind $kind
 * @property string $last_four
 * @property string $holder_name
 * @property string|null $notes
 * @property Carbon|null $deactivated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'issuer', 'network', 'kind', 'last_four', 'holder_name', 'notes'])]
class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'network' => CardNetwork::class,
            'kind' => CardKind::class,
            'deactivated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * "•••• 1234"
     */
    public function maskedNumber(): string
    {
        return '•••• '.$this->last_four;
    }

    /**
     * Other cards that might be the same physical card: same last four digits
     * and the same network, plus either the same issuer or the same kind.
     * Last four digits alone are not enough (different banks can share them),
     * so this only warns — it never blocks saving.
     *
     * @return Collection<int, CreditCard>
     */
    public static function possibleDuplicatesOf(
        string $lastFour,
        CardNetwork $network,
        CardKind $kind,
        string $issuer,
        ?int $ignoreId = null,
    ): Collection {
        $issuerKey = self::normalizeIssuer($issuer);

        return self::query()
            ->where('last_four', $lastFour)
            ->where('network', $network->value)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->orderBy('name')
            ->get()
            ->filter(fn (CreditCard $card) => $card->kind === $kind
                || self::normalizeIssuer($card->issuer) === $issuerKey)
            ->values();
    }

    /**
     * "  ธนาคาร กสิกรไทย " and "ธนาคารกสิกรไทย" compare as equal.
     */
    public static function normalizeIssuer(string $issuer): string
    {
        return Str::lower((string) preg_replace('/\s+/u', '', $issuer));
    }

    /**
     * @return HasOne<PaymentMethod, $this>
     */
    public function paymentMethod(): HasOne
    {
        return $this->hasOne(PaymentMethod::class);
    }

    /**
     * Save a NEW card together with its card payment method, in one database
     * transaction: either both exist afterwards or neither does. A card never
     * gets a second method (also enforced by a unique index); if one already
     * exists it is kept. Existing cards are never changed by this.
     */
    public function saveWithPaymentMethod(): void
    {
        $wasNew = ! $this->exists;

        try {
            DB::transaction(function () {
                $this->save();

                if (! $this->paymentMethod()->exists()) {
                    $method = $this->paymentMethod()->make([
                        'name' => $this->name,
                        'type' => PaymentMethodType::Card,
                        'settlement' => SettlementMode::Auto,
                    ]);

                    $method->forceFill([
                        'created_by' => $this->created_by,
                        'updated_by' => $this->updated_by,
                    ])->save();
                }
            });
        } catch (Throwable $e) {
            if ($wasNew) {
                // The rollback removed the row; make the object match.
                $this->exists = false;
                $this->wasRecentlyCreated = false;
                unset($this->attributes[$this->getKeyName()]);
            }

            throw $e;
        }
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Actual charges made on this card.
     *
     * @return HasMany<SubscriptionCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class);
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
     * @param  Builder<CreditCard>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }
}
