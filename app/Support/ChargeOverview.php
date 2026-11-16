<?php

namespace App\Support;

use App\Enums\SubscriptionCategory;
use App\Models\AccountingPeriodClosure;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Actual-charge figures for the dashboard and the charge list, always
 * limited to what $user may see (SubscriptionCharge::visibleTo).
 *
 * Money rules:
 * - Baht totals use the baht actually paid: THB charges' `amount`, and
 *   USD charges' `amount_thb`. USD amounts are never added to baht.
 * - USD charges whose `amount_thb` is not filled in yet are NOT in any baht
 *   total; they are counted and listed separately (missing THB).
 * - For Owner/Accountant, a closed accounting month shows the figures
 *   saved when it was closed (its snapshot). Later entries in that month
 *   are flagged `entered_after_close` and never change the closed figure.
 */
class ChargeOverview
{
    private const CATEGORY_COLORS = [
        'software' => '#6366f1',
        'cloud_hosting' => '#06b6d4',
        'marketing' => '#f59e0b',
        'communication' => '#10b981',
        'design' => '#ec4899',
        'productivity' => '#8b5cf6',
        'finance' => '#0ea5e9',
        'other' => '#94a3b8',
    ];

    private CarbonImmutable $today;

    public function __construct(private User $user, ?DateTimeInterface $today = null)
    {
        $this->today = CarbonImmutable::instance($today ?? now())->startOfDay();
    }

    /**
     * Owner and Accountant see company-wide figures.
     */
    public function seesAll(): bool
    {
        return $this->user->isOwner() || $this->user->isAccountant();
    }

    /**
     * Charged in the month of $month (default: this month), visible to the user.
     *
     * @return Collection<int, SubscriptionCharge>
     */
    public function chargesInMonth(?DateTimeInterface $month = null): Collection
    {
        return SubscriptionCharge::query()
            ->visibleTo($this->user)
            ->chargedInMonth($month ?? $this->today)
            ->with('subscription')
            ->get();
    }

    /**
     * @return array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}
     */
    public function monthSummary(?DateTimeInterface $month = null): array
    {
        return self::totals($this->chargesInMonth($month));
    }

    /**
     * Every USD charge still waiting for its baht amount (any month),
     * oldest first.
     *
     * @return Collection<int, SubscriptionCharge>
     */
    public function missingThb(): Collection
    {
        return SubscriptionCharge::query()
            ->visibleTo($this->user)
            ->charged()
            ->where('currency', '!=', 'THB')
            ->whereNull('amount_thb')
            ->with('subscription')
            ->orderBy('charged_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Charges recorded or changed after their month was closed and not yet
     * verified by the accountant.
     *
     * @return Collection<int, SubscriptionCharge>
     */
    public function afterCloseUnverified(): Collection
    {
        return SubscriptionCharge::query()
            ->visibleTo($this->user)
            ->where('entered_after_close', true)
            ->whereNull('verified_at')
            ->with('subscription')
            ->orderBy('charged_on')
            ->get();
    }

    /**
     * Most recently recorded charges.
     *
     * @return Collection<int, SubscriptionCharge>
     */
    public function recent(int $limit = 5): Collection
    {
        return SubscriptionCharge::query()
            ->visibleTo($this->user)
            ->with(['subscription', 'creditCard'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Baht actually paid this month per category, for <x-ui.donut-chart>.
     * USD charges without a baht amount are left out (listed separately).
     *
     * @return list<array{label: string, value: int, color: string}>
     */
    public function categoryBreakdown(?DateTimeInterface $month = null): array
    {
        $byCategory = [];

        foreach ($this->chargesInMonth($month) as $charge) {
            $thb = $charge->thbAmount();

            if ($thb === null || $thb === 0) {
                continue;
            }

            $key = $charge->subscription->category->value;
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $thb;
        }

        arsort($byCategory);

        $segments = [];
        foreach ($byCategory as $key => $value) {
            $segments[] = [
                'label' => SubscriptionCategory::from($key)->label(),
                'value' => $value,
                'color' => self::CATEGORY_COLORS[$key],
            ];
        }

        return $segments;
    }

    /**
     * Baht paid per month for the last $count months (oldest first), for
     * <x-ui.bar-chart>. Closed months use their closing snapshot for
     * company-wide viewers.
     *
     * @return list<array{label: string, value: int, current: bool, closed: bool}>
     */
    public function monthlySeries(int $count = 6): array
    {
        $months = ThaiFormat::recentMonths($count, $this->today);
        $closed = $this->seesAll()
            ? AccountingPeriodClosure::closedAmong(array_map(fn ($m) => AccountingPeriodClosure::periodOf($m), $months))
            : [];

        $series = [];

        foreach ($months as $month) {
            $period = AccountingPeriodClosure::periodOf($month);
            $snapshot = isset($closed[$period]) ? $closed[$period]->snapshot : null;

            $series[] = [
                'label' => ThaiFormat::monthShort($month),
                'value' => $snapshot !== null ? (int) $snapshot['thb_total'] : $this->monthSummary($month)['thb_total'],
                'current' => $month->isSameMonth($this->today),
                'closed' => isset($closed[$period]),
            ];
        }

        return $series;
    }

    /**
     * Company-wide figures of one month, saved when the month is closed.
     *
     * @return array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}
     */
    public static function snapshotFor(string $period): array
    {
        $charges = SubscriptionCharge::query()
            ->chargedInMonth(AccountingPeriodClosure::startOf($period))
            ->get();

        return self::totals($charges);
    }

    /**
     * @param  Collection<int, SubscriptionCharge>  $charges  charged rows only
     * @return array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}
     */
    public static function totals(Collection $charges): array
    {
        $totals = ['thb_total' => 0, 'charge_count' => 0, 'missing_thb_count' => 0, 'missing_thb_usd' => 0];

        foreach ($charges as $charge) {
            if (! $charge->isCharged()) {
                continue;
            }

            $totals['charge_count']++;
            $thb = $charge->thbAmount();

            if ($thb === null) {
                $totals['missing_thb_count']++;
                $totals['missing_thb_usd'] += $charge->amount;
            } else {
                $totals['thb_total'] += $thb;
            }
        }

        return $totals;
    }
}
