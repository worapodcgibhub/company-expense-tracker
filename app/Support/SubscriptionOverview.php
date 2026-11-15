<?php

namespace App\Support;

use App\Enums\Currency;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Subscription figures for the dashboard and the list page, always limited
 * to what $user may see (Subscription::visibleTo).
 *
 * Amounts are ESTIMATES (never actual charges) and are totalled per currency:
 * THB and USD are never added together because there is no exchange rate.
 */
class SubscriptionOverview
{
    /** @var Collection<int, Subscription> */
    private Collection $open;

    private CarbonImmutable $today;

    public function __construct(User $user, ?DateTimeInterface $today = null)
    {
        $this->today = CarbonImmutable::instance($today ?? now())->startOfDay();

        // Everything that is not cancelled; small enough to load at once.
        $this->open = Subscription::query()
            ->visibleTo($user)
            ->where('status', '!=', SubscriptionStatus::Cancelled->value)
            ->with(['creditCard', 'responsible'])
            ->orderBy('next_charge_date')
            ->orderBy('name')
            ->get();
    }

    public function activeCount(): int
    {
        return $this->active()->count();
    }

    /**
     * Active subscriptions charging from today up to $days days ahead.
     *
     * @return Collection<int, Subscription>
     */
    public function upcoming(int $days): Collection
    {
        return $this->active()
            ->filter(fn (Subscription $s) => $s->daysUntilCharge($this->today) >= 0
                && $s->daysUntilCharge($this->today) <= $days)
            ->values();
    }

    /**
     * Active subscriptions whose charge date has passed without an update.
     *
     * @return Collection<int, Subscription>
     */
    public function overdue(): Collection
    {
        return $this->active()->filter(fn (Subscription $s) => $s->isOverdue($this->today))->values();
    }

    /**
     * Not cancelled, but paid with a card that has been deactivated.
     *
     * @return Collection<int, Subscription>
     */
    public function needingNewCard(): Collection
    {
        return $this->open->filter(fn (Subscription $s) => $s->needsNewCard())->values();
    }

    /**
     * Estimated monthly cost of active subscriptions, per currency, in minor
     * units. Only currencies that are actually used appear.
     *
     * @return array<string, int> e.g. ['THB' => 189000, 'USD' => 5499]
     */
    public function monthlyEstimateByCurrency(): array
    {
        $totals = [];

        foreach (Currency::cases() as $currency) {
            $inCurrency = $this->active()->filter(fn (Subscription $s) => $s->currency === $currency);

            if ($inCurrency->isNotEmpty()) {
                $totals[$currency->value] = $inCurrency->sum(fn (Subscription $s) => $s->monthlyEstimate());
            }
        }

        return $totals;
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function active(): Collection
    {
        return $this->open->filter(fn (Subscription $s) => $s->isActive());
    }
}
