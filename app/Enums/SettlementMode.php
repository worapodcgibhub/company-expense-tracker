<?php

namespace App\Enums;

/**
 * Whether the money leaves by itself or someone must confirm the payment.
 */
enum SettlementMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'ตัดเงินอัตโนมัติ',
            self::Manual => 'ต้องกดยืนยันว่าจ่ายจริง',
        };
    }
}
