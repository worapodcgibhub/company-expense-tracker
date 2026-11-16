<?php

namespace App\Models;

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an actual charge (history; never edited or deleted).
 * `diff` holds field => [old, new] for updates, or the first values for
 * the "recorded" row. Values are stored as plain data (minor units,
 * Y-m-d dates, enum values, ids) so they read the same years later.
 *
 * @property int $id
 * @property int $subscription_charge_id
 * @property ChargeRevisionAction $action
 * @property array<string, mixed>|null $diff
 * @property string|null $reason
 * @property bool $in_closed_period
 * @property int|null $changed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubscriptionCharge $charge
 * @property-read User|null $changer
 */
#[Fillable(['action', 'diff', 'reason', 'in_closed_period', 'changed_by'])]
class SubscriptionChargeRevision extends Model
{
    private const FIELD_LABELS = [
        'outcome' => 'ผลการตัดเงิน',
        'charged_on' => 'วันที่ตัดเงินจริง',
        'amount' => 'ยอดเงิน',
        'amount_thb' => 'ยอดเงินบาทที่ธนาคารตัด',
        'credit_card_id' => 'บัตร',
        'notes' => 'หมายเหตุ',
        'verified' => 'การตรวจสอบของฝ่ายบัญชี',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ChargeRevisionAction::class,
            'diff' => 'array',
            'in_closed_period' => 'boolean',
        ];
    }

    /**
     * Human readable lines for an "updated" revision, e.g.
     * "ยอดเงิน: $49.00 → $52.00".
     *
     * @param  array<int, CreditCard>  $cards  cards by id, for card changes
     * @return list<string>
     */
    public function describeChanges(string $currency, array $cards = []): array
    {
        if ($this->action !== ChargeRevisionAction::Updated || ! is_array($this->diff)) {
            return [];
        }

        $lines = [];

        foreach ($this->diff as $field => $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                continue;
            }

            [$old, $new] = array_values($pair);
            $label = self::FIELD_LABELS[$field] ?? $field;

            $lines[] = $label.': '.self::formatValue($field, $old, $currency, $cards).' → '.self::formatValue($field, $new, $currency, $cards);
        }

        return $lines;
    }

    /**
     * @param  array<int, CreditCard>  $cards
     */
    private static function formatValue(string $field, mixed $value, string $currency, array $cards): string
    {
        if ($value === null || $value === '') {
            return '–';
        }

        return match ($field) {
            'outcome' => ChargeOutcome::tryFrom((string) $value)?->label() ?? (string) $value,
            'charged_on' => ($date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)) ? ThaiFormat::date($date) : (string) $value,
            'amount' => ThaiFormat::money((int) $value, $currency),
            'amount_thb' => ThaiFormat::money((int) $value, 'THB'),
            'credit_card_id' => isset($cards[(int) $value]) ? $cards[(int) $value]->name.' '.$cards[(int) $value]->maskedNumber() : '#'.$value,
            'verified' => $value ? 'ตรวจสอบแล้ว' : 'ยกเลิก (ต้องตรวจใหม่)',
            default => is_scalar($value) ? (string) $value : '',
        };
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
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
