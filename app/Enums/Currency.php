<?php

namespace App\Enums;

/**
 * Amounts are always stored in minor units (satang / cents) as integers.
 * Different currencies are never added together: there is no exchange rate
 * in the app yet, so every total is shown per currency.
 */
enum Currency: string
{
    case THB = 'THB';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::THB => 'บาท (THB)',
            self::USD => 'ดอลลาร์สหรัฐ (USD)',
        };
    }
}
