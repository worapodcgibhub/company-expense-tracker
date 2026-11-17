<?php

namespace App\Models;

use App\Enums\ChargeOutcome;
use App\Enums\ChargeRevisionAction;
use App\Enums\ReceiptKind;
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
     * Human readable lines for a document revision, e.g.
     * "ไฟล์: receipt.pdf (ใบกำกับภาษี · เลขที่ INV-01 · ลงวันที่ 3 พ.ย. 2569)".
     *
     * @return list<string>
     */
    public function describeReceipt(): array
    {
        if (! $this->action->isReceiptAction() || ! is_array($this->diff)) {
            return [];
        }

        $diff = $this->diff;
        $lines = [];

        if (isset($diff['file']) && is_string($diff['file'])) {
            $lines[] = ($this->action === ChargeRevisionAction::ReceiptReplaced ? 'ไฟล์ใหม่: ' : 'ไฟล์: ').self::documentLine($diff);
        }

        if (isset($diff['replaced']) && is_array($diff['replaced'])) {
            $lines[] = 'ไฟล์เดิม (นำออก): '.self::documentLine($diff['replaced']);
        }

        if (isset($diff['changes']) && is_array($diff['changes'])) {
            foreach ($diff['changes'] as $field => $pair) {
                if (! is_array($pair) || count($pair) !== 2) {
                    continue;
                }

                [$old, $new] = array_values($pair);
                $label = self::RECEIPT_FIELD_LABELS[$field] ?? (string) $field;
                $lines[] = $label.': '.self::receiptValue((string) $field, $old).' → '.self::receiptValue((string) $field, $new);
            }
        }

        if (! empty($diff['cleared_unavailable'])) {
            $lines[] = 'สถานะ "ไม่มีใบเสร็จ" ถูกยกเลิก เพราะมีเอกสารแล้ว';
        }

        if (isset($diff['previous_reason']) && is_string($diff['previous_reason'])) {
            $lines[] = 'เหตุผลเดิม: '.$diff['previous_reason'];
        }

        return $lines;
    }

    private const RECEIPT_FIELD_LABELS = [
        'kind' => 'ชนิดเอกสาร',
        'document_number' => 'เลขที่เอกสาร',
        'document_date' => 'วันที่ในเอกสาร',
    ];

    /**
     * @param  array<mixed>  $document
     */
    private static function documentLine(array $document): string
    {
        $parts = [self::receiptValue('kind', $document['kind'] ?? null)];

        if (! empty($document['document_number'])) {
            $parts[] = 'เลขที่ '.self::receiptValue('document_number', $document['document_number']);
        }

        if (! empty($document['document_date'])) {
            $parts[] = 'ลงวันที่ '.self::receiptValue('document_date', $document['document_date']);
        }

        $file = isset($document['file']) && is_scalar($document['file']) ? (string) $document['file'] : '–';

        return $file.' ('.implode(' · ', $parts).')';
    }

    private static function receiptValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return '–';
        }

        return match ($field) {
            'kind' => ReceiptKind::tryFrom((string) $value)?->label() ?? (string) $value,
            'document_date' => ($date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)) ? ThaiFormat::date($date) : (string) $value,
            default => (string) $value,
        };
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
