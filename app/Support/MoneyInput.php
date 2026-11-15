<?php

namespace App\Support;

/**
 * Converts what people type into minor units (satang / cents) and back,
 * using string arithmetic only — never floats, which cannot represent
 * amounts like 0.10 exactly.
 */
class MoneyInput
{
    /**
     * 99,999,999.99 — far above any subscription, low enough to catch typos.
     */
    public const MAX_MINOR = 9_999_999_999;

    /**
     * "1,234.56" → 123456, "590" → 59000, "0.1" → 10.
     * Returns null for anything that is not a plain positive amount with at
     * most two decimals ("1.234", "-5", "1e3", "12,34", "", ...).
     */
    public static function toMinor(string $input): ?int
    {
        $input = trim($input);

        $grouped = '\d{1,3}(?:,\d{3})+';
        $plain = '\d+';

        if (preg_match('/^(?:'.$grouped.'|'.$plain.')(?:\.(\d{1,2}))?$/', $input, $matches) !== 1) {
            return null;
        }

        [$whole] = explode('.', str_replace(',', '', $input));
        $fraction = str_pad($matches[1] ?? '', 2, '0');

        $whole = ltrim($whole, '0');

        // Longer than the maximum can be: reject before converting to int.
        if (strlen($whole) > 8) {
            return null;
        }

        return (int) (($whole === '' ? '0' : $whole).$fraction);
    }

    /**
     * 123456 → "1234.56" (for putting a stored amount back into a form).
     */
    public static function fromMinor(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
