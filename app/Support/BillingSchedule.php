<?php

namespace App\Support;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Charge-date arithmetic for subscriptions. Pure: no database, no "now".
 *
 * Every subscription keeps its contract day (`billing_day`, 1–31). The next
 * date is always derived from that day — never from the previous date's
 * day — so short months do not make the schedule drift:
 *
 *   billing day 31, monthly: 31 Jan → 28/29 Feb → 31 Mar → 30 Apr → 31 May
 *   billing day 29, yearly from 29 Feb 2028: 28 Feb 2029 … 29 Feb 2032
 *
 * In a month that is too short, the charge falls on its last day.
 * Yearly subscriptions keep the month of their current charge date.
 */
class BillingSchedule
{
    /**
     * The charge date after $current.
     */
    public static function next(DateTimeInterface $current, BillingCycle $cycle, int $billingDay): CarbonImmutable
    {
        // Start from the 1st so adding a month/year can never overflow
        // (31 Jan + 1 month would otherwise become 3 Mar).
        $firstOfMonth = CarbonImmutable::instance($current)->startOfDay()->startOfMonth();

        $target = match ($cycle) {
            BillingCycle::Monthly => $firstOfMonth->addMonth(),
            BillingCycle::Yearly => $firstOfMonth->addYear(),
        };

        return self::onBillingDay($target, $billingDay);
    }

    /**
     * $count charge dates starting with $first itself.
     *
     * @return list<CarbonImmutable>
     */
    public static function upcoming(DateTimeInterface $first, BillingCycle $cycle, int $billingDay, int $count): array
    {
        $dates = [];
        $date = CarbonImmutable::instance($first)->startOfDay();

        for ($i = 0; $i < $count; $i++) {
            $dates[] = $date;
            $date = self::next($date, $cycle, $billingDay);
        }

        return $dates;
    }

    /**
     * Rolls $date forward along the schedule until it is not before $today.
     * Used when a paused or cancelled subscription is resumed.
     */
    public static function firstOnOrAfter(DateTimeInterface $date, BillingCycle $cycle, int $billingDay, DateTimeInterface $today): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $today = CarbonImmutable::instance($today)->startOfDay();

        while ($date->lessThan($today)) {
            $date = self::next($date, $cycle, $billingDay);
        }

        return $date;
    }

    /**
     * The day $billingDay of $month's month, or its last day if the month is
     * shorter (billing day 31 in April → 30 April).
     */
    public static function onBillingDay(DateTimeInterface $month, int $billingDay): CarbonImmutable
    {
        $month = CarbonImmutable::instance($month)->startOfDay()->startOfMonth();

        return $month->setDay(min($billingDay, $month->daysInMonth));
    }

    /**
     * Whether $date is where a charge with $billingDay falls in its month.
     */
    public static function matchesBillingDay(DateTimeInterface $date, int $billingDay): bool
    {
        return self::onBillingDay($date, $billingDay)->isSameDay($date);
    }
}
