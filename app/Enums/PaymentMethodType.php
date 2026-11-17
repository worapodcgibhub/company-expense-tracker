<?php

namespace App\Enums;

/**
 * How a payment is made. PromptPay / QR payments are bank transfers.
 */
enum PaymentMethodType: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case DirectDebit = 'direct_debit';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'บัตรเครดิต/เดบิต',
            self::BankTransfer => 'โอนเงินผ่านธนาคาร / PromptPay / QR',
            self::DirectDebit => 'หักบัญชีธนาคารอัตโนมัติ',
            self::Cash => 'เงินสด',
        };
    }

    public function requiresCard(): bool
    {
        return $this === self::Card;
    }

    public function requiresBankAccount(): bool
    {
        return $this === self::BankTransfer || $this === self::DirectDebit;
    }

    /**
     * Cards and direct debit are taken by the bank by itself; transfers and
     * cash need someone to confirm the money was really paid.
     */
    public function defaultSettlement(): SettlementMode
    {
        return match ($this) {
            self::Card, self::DirectDebit => SettlementMode::Auto,
            self::BankTransfer, self::Cash => SettlementMode::Manual,
        };
    }
}
