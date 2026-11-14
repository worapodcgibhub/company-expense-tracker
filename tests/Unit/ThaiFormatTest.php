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

test('recent months includes the current month and handles year and February boundaries', function (string $reference, int $count, array $expected) {
    $months = ThaiFormat::recentMonths($count, CarbonImmutable::parse($reference));

    expect(array_map(fn ($m) => $m->format('Y-m'), $months))->toBe($expected);
})->with([
    'only the current month' => ['2026-11-15', 1, ['2026-11']],
    'crossing into a new year' => ['2026-01-15', 3, ['2025-11', '2025-12', '2026-01']],
    'last day of the year' => ['2026-12-31 23:59:59', 2, ['2026-11', '2026-12']],
    'first day of the year' => ['2027-01-01 00:00:00', 2, ['2026-12', '2027-01']],
    'full year back across a year' => ['2026-02-10', 13, [
        '2025-02', '2025-03', '2025-04', '2025-05', '2025-06', '2025-07', '2025-08',
        '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02',
    ]],
    'February in a normal year' => ['2026-02-28', 3, ['2025-12', '2026-01', '2026-02']],
    'February in a leap year' => ['2024-02-29', 3, ['2023-12', '2024-01', '2024-02']],
    'from 31 March, February is not skipped' => ['2026-03-31', 2, ['2026-02', '2026-03']],
    'from 31 January in a leap year' => ['2024-01-31', 2, ['2023-12', '2024-01']],
    'from 29 February back one year' => ['2024-02-29', 13, [
        '2023-02', '2023-03', '2023-04', '2023-05', '2023-06', '2023-07', '2023-08',
        '2023-09', '2023-10', '2023-11', '2023-12', '2024-01', '2024-02',
    ]],
]);

test('recent months returns a list of first-of-month dates at midnight', function () {
    $months = ThaiFormat::recentMonths(6, CarbonImmutable::parse('2026-11-15 18:45:12'));

    expect($months)->toBeList()->toHaveCount(6);

    foreach ($months as $month) {
        expect($month->format('d H:i:s'))->toBe('01 00:00:00');
    }
});

test('recent months does not change the reference date', function () {
    $reference = CarbonImmutable::parse('2026-03-31 10:00:00');

    ThaiFormat::recentMonths(6, $reference);

    expect($reference->toDateTimeString())->toBe('2026-03-31 10:00:00');
});
