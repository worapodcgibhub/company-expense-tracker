<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Display helpers for Thai users.
 *
 * Money is always passed in minor units (satang / cents) as integers,
 * matching how amounts will be stored in the database.
 * Dates are stored as Gregorian (ค.ศ.) and only displayed as Buddhist Era (พ.ศ.).
 */
class ThaiFormat
{
    private const MONTHS_LONG = [
        1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    private const MONTHS_SHORT = [
        1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
    ];

    private const DAYS = [
        0 => 'อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์',
    ];

    private const CURRENCY_SYMBOLS = [
        'THB' => '฿',
        'USD' => '$',
    ];

    /**
     * 180050 → "฿1,800.50"; -4900 with USD → "-$49.00".
     */
    public static function money(int $minorUnits, string $currency = 'THB'): string
    {
        $currency = strtoupper($currency);
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? $currency.' ';
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        return $sign.$symbol.number_format($whole).'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    public static function buddhistYear(DateTimeInterface $date): int
    {
        return (int) $date->format('Y') + 543;
    }

    /**
     * "14 พ.ย. 2569"
     */
    public static function date(DateTimeInterface $date): string
    {
        $month = (int) $date->format('n');

        return $date->format('j').' '.self::MONTHS_SHORT[$month].' '.self::buddhistYear($date);
    }

    /**
     * "14 พฤศจิกายน 2569"
     */
    public static function dateLong(DateTimeInterface $date): string
    {
        $month = (int) $date->format('n');

        return $date->format('j').' '.self::MONTHS_LONG[$month].' '.self::buddhistYear($date);
    }

    /**
     * "วันเสาร์ที่ 14 พฤศจิกายน 2569"
     */
    public static function dateWithDay(DateTimeInterface $date): string
    {
        return 'วัน'.self::DAYS[(int) $date->format('w')].'ที่ '.self::dateLong($date);
    }

    /**
     * "พฤศจิกายน 2569"
     */
    public static function monthYear(DateTimeInterface $date): string
    {
        return self::MONTHS_LONG[(int) $date->format('n')].' '.self::buddhistYear($date);
    }

    /**
     * "พ.ย." (optionally with a 2-digit BE year: "พ.ย. 69")
     */
    public static function monthShort(DateTimeInterface $date, bool $withYear = false): string
    {
        $label = self::MONTHS_SHORT[(int) $date->format('n')];

        return $withYear ? $label.' '.substr((string) self::buddhistYear($date), -2) : $label;
    }

    /**
     * The last $count months (oldest first), ending with the month of $reference.
     *
     * @return list<Carbon>
     */
    public static function recentMonths(int $count = 6, ?DateTimeInterface $reference = null): array
    {
        $end = Carbon::instance($reference ?? now())->startOfMonth();

        return array_map(
            fn (int $offset) => $end->copy()->subMonthsNoOverflow($offset),
            range($count - 1, 0),
        );
    }
}
