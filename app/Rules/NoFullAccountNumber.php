<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects text that contains a long run of digits, so a full bank account
 * number (10–12 digits in Thailand), a PromptPay ID (phone or national ID
 * number) or a card number can never end up in a free-text field.
 *
 * A run is digits optionally separated by single spaces or dashes, like
 * "123-4-56789-0" or "123 456 7890". Ten digits or more is rejected; the
 * four-digit "last four" field is validated separately and is not affected.
 */
class NoFullAccountNumber implements ValidationRule
{
    public const MIN_DIGITS = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::containsLongNumber($value)) {
            $fail('ห้ามใส่เลขบัญชี เลขบัตร หรือเลขพร้อมเพย์เต็มใน:attribute ให้เก็บเฉพาะเลขท้าย 4 หลักในช่องที่กำหนด');
        }
    }

    public static function containsLongNumber(string $text): bool
    {
        preg_match_all('/\d(?:[ \-]?\d)*/', $text, $matches);

        foreach ($matches[0] as $run) {
            if (strlen((string) preg_replace('/\D/', '', $run)) >= self::MIN_DIGITS) {
                return true;
            }
        }

        return false;
    }
}
