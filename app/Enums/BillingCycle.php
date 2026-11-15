<?php

namespace App\Enums;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'รายเดือน',
            self::Yearly => 'รายปี',
        };
    }

    /**
     * Suffix after an amount: "฿590.00 / เดือน".
     */
    public function perLabel(): string
    {
        return match ($this) {
            self::Monthly => '/ เดือน',
            self::Yearly => '/ ปี',
        };
    }
}
