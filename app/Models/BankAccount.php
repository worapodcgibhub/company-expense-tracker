<?php

namespace App\Models;

use App\Enums\BankAccountKind;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company bank account used to pay (transfer, PromptPay, direct debit).
 *
 * Only the last four digits are ever stored — never the full account number,
 * internet-banking password, PIN or full PromptPay ID. Accounts are
 * deactivated, never deleted. `deactivated_at`, `created_by` and `updated_by`
 * are not mass-assignable; server-side code sets them with forceFill().
 *
 * @property int $id
 * @property string $name
 * @property string $bank
 * @property string $account_name
 * @property string $last_four
 * @property BankAccountKind $kind
 * @property string|null $notes
 * @property Carbon|null $deactivated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable(['name', 'bank', 'account_name', 'last_four', 'kind', 'notes'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BankAccountKind::class,
            'deactivated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function maskedNumber(): string
    {
        return '••••'.$this->last_four;
    }

    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
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
     * @param  Builder<BankAccount>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }
}
