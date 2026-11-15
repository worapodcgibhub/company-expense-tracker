<?php

use App\Enums\BillingCycle;
use App\Support\BillingSchedule;
use Carbon\CarbonImmutable;

function ymd(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date);
}

/**
 * @return list<string>
 */
function scheduleOf(string $first, BillingCycle $cycle, int $day, int $count): array
{
    return array_map(fn (CarbonImmutable $d) => $d->format('Y-m-d'), BillingSchedule::upcoming(ymd($first), $cycle, $day, $count));
}

test('monthly on day 31 falls on each month\'s last day and returns to 31', function () {
    expect(scheduleOf('2027-01-31', BillingCycle::Monthly, 31, 6))->toBe([
        '2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30',
    ]);
});

test('monthly on day 31 uses 29 February in a leap year', function () {
    expect(scheduleOf('2028-01-31', BillingCycle::Monthly, 31, 3))->toBe(['2028-01-31', '2028-02-29', '2028-03-31']);
});

test('monthly on day 30 and 29 come back after February', function (int $day, string $first, array $expected) {
    expect(scheduleOf($first, BillingCycle::Monthly, $day, 3))->toBe($expected);
})->with([
    'day 30, normal year' => [30, '2027-01-30', ['2027-01-30', '2027-02-28', '2027-03-30']],
    'day 29, normal year' => [29, '2027-01-29', ['2027-01-29', '2027-02-28', '2027-03-29']],
    'day 29, leap year' => [29, '2028-01-29', ['2028-01-29', '2028-02-29', '2028-03-29']],
]);

test('the schedule never drifts: 24 months on day 31 always hit 31 when the month has it', function () {
    $dates = BillingSchedule::upcoming(ymd('2027-01-31'), BillingCycle::Monthly, 31, 24);

    foreach ($dates as $date) {
        expect($date->day)->toBe(min(31, $date->daysInMonth));
    }

    expect($dates[23]->format('Y-m-d'))->toBe('2028-12-31');
});

test('monthly crosses the year end', function () {
    expect(scheduleOf('2026-12-31', BillingCycle::Monthly, 31, 2))->toBe(['2026-12-31', '2027-01-31'])
        ->and(scheduleOf('2026-12-15', BillingCycle::Monthly, 15, 2))->toBe(['2026-12-15', '2027-01-15']);
});

test('yearly on 29 February uses 28 February outside leap years', function () {
    expect(scheduleOf('2028-02-29', BillingCycle::Yearly, 29, 5))->toBe([
        '2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29',
    ]);
});

test('2100 is not a leap year', function () {
    expect(BillingSchedule::next(ymd('2099-02-28'), BillingCycle::Yearly, 29)->format('Y-m-d'))->toBe('2100-02-28')
        ->and(BillingSchedule::next(ymd('2100-01-31'), BillingCycle::Monthly, 31)->format('Y-m-d'))->toBe('2100-02-28');
});

test('yearly keeps the month and day', function () {
    expect(scheduleOf('2026-11-20', BillingCycle::Yearly, 20, 3))->toBe(['2026-11-20', '2027-11-20', '2028-11-20']);
});

test('matchesBillingDay accepts the day itself or the short month\'s last day', function () {
    expect(BillingSchedule::matchesBillingDay(ymd('2027-02-28'), 31))->toBeTrue()
        ->and(BillingSchedule::matchesBillingDay(ymd('2027-04-30'), 31))->toBeTrue()
        ->and(BillingSchedule::matchesBillingDay(ymd('2027-03-30'), 31))->toBeFalse()
        ->and(BillingSchedule::matchesBillingDay(ymd('2027-03-15'), 15))->toBeTrue()
        ->and(BillingSchedule::matchesBillingDay(ymd('2027-03-16'), 15))->toBeFalse();
});

test('firstOnOrAfter rolls a past date forward along the schedule', function () {
    $today = ymd('2026-11-15');

    expect(BillingSchedule::firstOnOrAfter(ymd('2026-08-31'), BillingCycle::Monthly, 31, $today)->format('Y-m-d'))->toBe('2026-11-30')
        ->and(BillingSchedule::firstOnOrAfter(ymd('2026-08-10'), BillingCycle::Monthly, 10, $today)->format('Y-m-d'))->toBe('2026-12-10')
        ->and(BillingSchedule::firstOnOrAfter(ymd('2026-11-15'), BillingCycle::Monthly, 15, $today)->format('Y-m-d'))->toBe('2026-11-15')
        ->and(BillingSchedule::firstOnOrAfter(ymd('2025-02-28'), BillingCycle::Yearly, 29, $today)->format('Y-m-d'))->toBe('2027-02-28')
        ->and(BillingSchedule::firstOnOrAfter(ymd('2026-12-01'), BillingCycle::Monthly, 1, $today)->format('Y-m-d'))->toBe('2026-12-01');
});

test('times of day are ignored', function () {
    $next = BillingSchedule::next(CarbonImmutable::parse('2027-01-31 23:59:59', 'Asia/Bangkok'), BillingCycle::Monthly, 31);

    expect($next->format('Y-m-d H:i:s'))->toBe('2027-02-28 00:00:00');
});
