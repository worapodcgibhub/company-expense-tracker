<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects text that contains what looks like a full card number, so it can
 * never end up in a free-text field (name, notes, ...).
 *
 * A "card number" is a run of 13–19 digits, optionally separated by single
 * spaces or dashes ("4111 1111 1111 1111", "4111-1111-1111-1111"), that
 * passes the Luhn checksum every real card number has. The checksum keeps
 * false alarms low for other long numbers. Runs longer than 19 digits are
 * rejected outright: there is no reason to store them on a card record.
 */
class NoFullCardNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::containsCardNumber($value)) {
            $fail('ห้ามใส่เลขบัตรเต็มใน:attribute ให้เก็บเฉพาะเลขท้าย 4 หลักในช่องที่กำหนด');
        }
    }

    public static function containsCardNumber(string $text): bool
    {
        preg_match_all('/\d(?:[ \-]?\d)*/', $text, $matches);

        foreach ($matches[0] as $run) {
            $digits = (string) preg_replace('/\D/', '', $run);
            $length = strlen($digits);

            if ($length > 19 || ($length >= 13 && self::passesLuhn($digits))) {
                return true;
            }
        }

        return false;
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
