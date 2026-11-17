<?php

namespace App\Enums;

enum ReceiptKind: string
{
    case Receipt = 'receipt';
    case TaxInvoice = 'tax_invoice';
    case Statement = 'statement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'ใบเสร็จรับเงิน',
            self::TaxInvoice => 'ใบกำกับภาษี',
            self::Statement => 'ใบแจ้งยอดบัตร',
            self::Other => 'เอกสารอื่น',
        };
    }
}
