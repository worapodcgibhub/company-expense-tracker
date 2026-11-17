<?php

namespace App\Enums;

enum BankAccountKind: string
{
    case Savings = 'savings';
    case Current = 'current';

    public function label(): string
    {
        return match ($this) {
            self::Savings => 'ออมทรัพย์',
            self::Current => 'กระแสรายวัน',
        };
    }
}
