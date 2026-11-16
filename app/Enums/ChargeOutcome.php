<?php

namespace App\Enums;

/**
 * What happened in one billing cycle of a subscription.
 */
enum ChargeOutcome: string
{
    case Charged = 'charged';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Charged => 'ตัดเงินแล้ว',
            self::Skipped => 'รอบนี้ไม่ถูกตัดเงิน',
        };
    }
}
