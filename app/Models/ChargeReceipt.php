<?php

namespace App\Models;

use App\Enums\ChargeRevisionAction;
use App\Enums\ReceiptKind;
use App\Support\ReceiptStorage;
use Carbon\CarbonImmutable;
use Database\Factories\ChargeReceiptFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One receipt / tax invoice file attached to an actual charge.
 *
 * - The file is on the private disk (ReceiptStorage) and is only sent by
 *   ChargeReceiptController after ChargeReceiptPolicy::view.
 * - Never deleted. remove() only marks it removed (who, when, why) and the
 *   file stays on disk. Replacing a file = a new row whose `replaces_id`
 *   points to the old one, which is removed with the reason.
 * - `document_date` is the date printed on the document, which may differ
 *   from the charge date.
 * - Every change writes a SubscriptionChargeRevision on the charge.
 *
 * @property int $id
 * @property int $subscription_charge_id
 * @property ReceiptKind $kind
 * @property string|null $document_number
 * @property CarbonImmutable|null $document_date
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int|null $replaces_id
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $removed_at
 * @property int|null $removed_by
 * @property string|null $remove_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubscriptionCharge $charge
 * @property-read User|null $uploader
 * @property-read User|null $remover
 * @property-read ChargeReceipt|null $replaces
 */
class ChargeReceipt extends Model
{
    /** @use HasFactory<ChargeReceiptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReceiptKind::class,
            'document_date' => 'date',
            'size' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function sizeLabel(): string
    {
        return ReceiptStorage::sizeLabel($this->size);
    }

    /**
     * Changes the kind / number / date and records old → new values.
     * Returns false (writes nothing) when nothing changed.
     */
    public function updateDetails(ReceiptKind $kind, ?string $number, ?DateTimeInterface $date, User $by): bool
    {
        if ($this->isRemoved()) {
            throw new LogicException('A removed document cannot be changed.');
        }

        $number = self::clean($number);
        $dateValue = $date?->format('Y-m-d');

        $before = ['kind' => $this->kind->value, 'document_number' => $this->document_number, 'document_date' => $this->document_date?->format('Y-m-d')];
        $after = ['kind' => $kind->value, 'document_number' => $number, 'document_date' => $dateValue];

        $changes = [];
        foreach ($before as $field => $old) {
            if ($old !== $after[$field]) {
                $changes[$field] = [$old, $after[$field]];
            }
        }

        if ($changes === []) {
            return false;
        }

        DB::transaction(function () use ($kind, $number, $date, $by, $changes) {
            $this->forceFill([
                'kind' => $kind,
                'document_number' => $number,
                'document_date' => $date !== null ? CarbonImmutable::instance($date)->startOfDay() : null,
            ])->save();

            $this->charge->writeRevision(ChargeRevisionAction::ReceiptUpdated, $by, [...$this->summary(), 'changes' => $changes], null, false);
        });

        return true;
    }

    /**
     * Takes the document off the charge. The row and the file are kept.
     */
    public function remove(User $by, string $reason): void
    {
        $reason = self::clean($reason) ?? throw new LogicException('A reason is required to remove a document.');

        if ($this->isRemoved()) {
            return;
        }

        DB::transaction(function () use ($by, $reason) {
            $this->markRemoved($by, $reason);
            $this->charge->writeRevision(ChargeRevisionAction::ReceiptRemoved, $by, $this->summary(), $reason, false);
        });
    }

    /**
     * Sets the removed fields only (no revision). Used by remove() and when
     * a file is replaced (the replacement writes one revision for both).
     */
    public function markRemoved(User $by, string $reason): void
    {
        $this->forceFill([
            'removed_at' => now(),
            'removed_by' => $by->id,
            'remove_reason' => $reason,
        ])->save();
    }

    /**
     * What the history keeps about this document.
     *
     * @return array{receipt_id: int, file: string, kind: string, document_number: ?string, document_date: ?string}
     */
    public function summary(): array
    {
        return [
            'receipt_id' => $this->id,
            'file' => $this->original_name,
            'kind' => $this->kind->value,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->format('Y-m-d'),
        ];
    }

    /**
     * @return BelongsTo<SubscriptionCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCharge::class, 'subscription_charge_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * @return BelongsTo<ChargeReceipt, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /**
     * Not removed.
     *
     * @param  Builder<ChargeReceipt>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    private static function clean(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
