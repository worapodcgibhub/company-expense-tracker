<?php

use App\Support\ThaiFormat;
use Carbon\CarbonImmutable;

test('money formats minor units as Thai baht', function (int $minor, string $expected) {
    expect(ThaiFormat::money($minor))->toBe($expected);
})->with([
    'zero' => [0, '฿0.00'],
    'satang only' => [5, '฿0.05'],
    'whole baht' => [180000, '฿1,800.00'],
    'with satang' => [180050, '฿1,800.50'],
    'millions' => [123456789, '฿1,234,567.89'],
    'negative' => [-4900, '-฿49.00'],
]);

test('money supports US dollars and unknown currencies', function () {
    expect(ThaiFormat::money(4900, 'USD'))->toBe('$49.00')
        ->and(ThaiFormat::money(4900, 'usd'))->toBe('$49.00')
        ->and(ThaiFormat::money(4900, 'EUR'))->toBe('EUR 49.00');
});

test('dates are displayed in the Buddhist Era', function () {
    $date = CarbonImmutable::create(2026, 11, 14);

    expect(ThaiFormat::buddhistYear($date))->toBe(2569)
        ->and(ThaiFormat::date($date))->toBe('14 พ.ย. 2569')
        ->and(ThaiFormat::dateLong($date))->toBe('14 พฤศจิกายน 2569')
        ->and(ThaiFormat::dateWithDay($date))->toBe('วันเสาร์ที่ 14 พฤศจิกายน 2569')
        ->and(ThaiFormat::monthYear($date))->toBe('พฤศจิกายน 2569')
        ->and(ThaiFormat::monthShort($date))->toBe('พ.ย.')
        ->and(ThaiFormat::monthShort($date, withYear: true))->toBe('พ.ย. 69');
});

test('recent months returns the last N months oldest first without overflow', function () {
    $months = ThaiFormat::recentMonths(6, CarbonImmutable::create(2026, 3, 31));

    expect($months)->toHaveCount(6)
        ->and(array_map(fn ($m) => $m->format('Y-m'), $months))->toBe([
            '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03',
        ]);
});
