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
            ->with(['creditCard', 'paymentMethod.creditCard', 'paymentMethod.bankAccount', 'responsible'])
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
     * Manual subscriptions (transfer / PromptPay / cash) due from today up to
     * $days days ahead: someone has to pay them.
     *
     * @return Collection<int, Subscription>
     */
    public function manualDueSoon(int $days = 7): Collection
    {
        return $this->upcoming($days)->filter(fn (Subscription $s) => $s->isManual())->values();
    }

    /**
     * Manual subscriptions past their due date and not confirmed as paid.
     *
     * @return Collection<int, Subscription>
     */
    public function manualOverdue(): Collection
    {
        return $this->overdue()->filter(fn (Subscription $s) => $s->isManual())->values();
    }

    /**
     * Auto subscriptions (card, direct debit) whose due date is today or has
     * passed, with no actual charge recorded for that cycle yet. They are
     * NOT paid until someone records the actual amount.
     *
     * @return Collection<int, Subscription>
     */
    public function autoAwaitingConfirmation(): Collection
    {
        return $this->active()
            ->filter(fn (Subscription $s) => ! $s->isManual() && $s->daysUntilCharge($this->today) <= 0)
            ->values();
    }

    /**
     * How many of the subscriptions due within $days days are Manual / Auto.
     *
     * @return array{manual: int, auto: int}
     */
    public function upcomingBySettlement(int $days): array
    {
        $upcoming = $this->upcoming($days);
        $manual = $upcoming->filter(fn (Subscription $s) => $s->isManual())->count();

        return ['manual' => $manual, 'auto' => $upcoming->count() - $manual];
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
     * Not cancelled, card fine, but the payment method (or its bank account)
     * has been deactivated.
     *
     * @return Collection<int, Subscription>
     */
    public function needingNewPaymentMethod(): Collection
    {
        return $this->open->filter(fn (Subscription $s) => $s->needsNewPaymentMethod())->values();
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
