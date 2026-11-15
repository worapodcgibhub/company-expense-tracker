<?php

namespace App\Enums;

/**
 * The payment network printed on the card (Visa, Mastercard, ...).
 */
enum CardNetwork: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Jcb = 'jcb';
    case Amex = 'amex';
    case UnionPay = 'unionpay';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Jcb => 'JCB',
            self::Amex => 'American Express',
            self::UnionPay => 'UnionPay',
            self::Other => 'อื่น ๆ',
        };
    }
}
