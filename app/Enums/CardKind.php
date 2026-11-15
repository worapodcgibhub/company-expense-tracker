<?php

namespace App\Enums;

/**
 * What kind of card it is (credit, debit or a virtual card).
 */
enum CardKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'บัตรเครดิต',
            self::Debit => 'บัตรเดบิต',
            self::Virtual => 'บัตรเสมือน (Virtual)',
        };
    }
}
