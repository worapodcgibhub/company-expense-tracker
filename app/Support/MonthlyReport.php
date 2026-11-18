<?php

namespace App\Support;

use App\Enums\Currency;
use App\Enums\ReceiptStatus;
use App\Models\Expense;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * What was really PAID in one calendar month, from both sources, limited to
 * what $user may see (visibleTo on both).
 *
 * - Subscription charges: rows charged in the month (SubscriptionCharge
 *   ::chargedInMonth, the same scope the dashboard and the charge list use),
 *   totalled by ChargeOverview::totals() so the baht figure is identical to
 *   theirs. Estimates and cycles not recorded yet are never included.
 * - One-off expenses: rows paid in the month (Expense::paidInMonth).
 * - The two sources are different tables and never overlap: a payment is
 *   either a subscription cycle or a one-off expense, so nothing is counted
 *   twice.
 * - Baht totals use the baht actually paid (THB amount, or a USD row's
 *   amount_thb). USD is never converted by an assumed rate: dollars are
 *   reported apart, and USD rows without a baht amount are listed as waiting.
 *
 * Read-only: this class never writes anything.
 */
class MonthlyReport
{
    private CarbonImmutable $month;

    /** @var Collection<int, SubscriptionCharge>|null */
    private ?Collection $charges = null;

    /** @var Collection<int, Expense>|null */
    private ?Collection $expenses = null;

    public function __construct(private User $user, DateTimeInterface $month)
    {
        $this->month = CarbonImmutable::instance($month)->startOfMonth();
    }

    public function month(): CarbonImmutable
    {
        return $this->month;
    }

    /**
     * @return Collection<int, SubscriptionCharge>
     */
    public function charges(): Collection
    {
        return $this->charges ??= SubscriptionCharge::query()
            ->visibleTo($this->user)
            ->chargedInMonth($this->month)
            ->with(['subscription', 'paymentMethod'])
            ->withCurrentReceiptCount()
            ->orderBy('charged_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Expense>
     */
    public function expenses(): Collection
    {
        return $this->expenses ??= Expense::query()
            ->visibleTo($this->user)
            ->paidInMonth($this->month)
            ->with(['paymentMethod', 'owner'])
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     thb: array{subscription: int, one_off: int, total: int},
     *     counts: array{subscription: int, one_off: int, total: int},
     *     foreign: array{count: int, usd: int},
     *     missing_thb: array{count: int, usd: int},
     *     receipts: array{subscription: array{waiting: int, unavailable: int, attached: int}, one_off: array{waiting: int, attached: int}, waiting: int}
     * }
     */
    public function summary(): array
    {
        $subscription = ChargeOverview::totals($this->charges());

        $oneOffThb = 0;
        $missingCount = $subscription['missing_thb_count'];
        $missingUsd = $subscription['missing_thb_usd'];
        $foreignCount = 0;
        $foreignUsd = 0;

        foreach ($this->charges() as $charge) {
            if ($charge->isCharged() && $charge->currency !== Currency::THB) {
                $foreignCount++;
                $foreignUsd += $charge->amount;
            }
        }

        foreach ($this->expenses() as $expense) {
            $thb = $expense->thbAmount();

            if ($expense->currency !== Currency::THB) {
                $foreignCount++;
                $foreignUsd += $expense->amount;
            }

            if ($thb === null) {
                $missingCount++;
                $missingUsd += $expense->amount;
            } else {
                $oneOffThb += $thb;
            }
        }

        $subscriptionReceipts = ['waiting' => 0, 'unavailable' => 0, 'attached' => 0];

        foreach ($this->charges() as $charge) {
            $status = $charge->receiptStatus();

            if (isset($subscriptionReceipts[$status->value])) {
                $subscriptionReceipts[$status->value]++;
            }
        }

        $oneOffReceipts = ['waiting' => 0, 'attached' => 0];

        foreach ($this->expenses() as $expense) {
            $oneOffReceipts[$expense->receiptStatus()->value]++;
        }

        $subscriptionCount = $subscription['charge_count'];
        $oneOffCount = $this->expenses()->count();

        return [
            'thb' => [
                'subscription' => $subscription['thb_total'],
                'one_off' => $oneOffThb,
                'total' => $subscription['thb_total'] + $oneOffThb,
            ],
            'counts' => [
                'subscription' => $subscriptionCount,
                'one_off' => $oneOffCount,
                'total' => $subscriptionCount + $oneOffCount,
            ],
            'foreign' => ['count' => $foreignCount, 'usd' => $foreignUsd],
            'missing_thb' => ['count' => $missingCount, 'usd' => $missingUsd],
            'receipts' => [
                'subscription' => $subscriptionReceipts,
                'one_off' => $oneOffReceipts,
                'waiting' => $subscriptionReceipts['waiting'] + $oneOffReceipts['waiting'],
            ],
        ];
    }

    /**
     * Every paid row of both sources, newest first.
     *
     * @return BaseCollection<int, array{kind: string, id: int, date: CarbonImmutable, name: string, method: ?string, owner: ?string, amount: int, currency: string, thb: ?int, receipt: ReceiptStatus, model: SubscriptionCharge|Expense}>
     */
    public function rows(): BaseCollection
    {
        $charges = $this->charges()->map(fn (SubscriptionCharge $charge) => [
            'kind' => 'subscription',
            'id' => $charge->id,
            'date' => CarbonImmutable::instance($charge->charged_on),
            'name' => $charge->subscription->name,
            'method' => $charge->paymentMethod?->name,
            'owner' => null,
            'amount' => $charge->amount,
            'currency' => $charge->currency->value,
            'thb' => $charge->thbAmount(),
            'receipt' => $charge->receiptStatus(),
            'model' => $charge,
        ]);

        $expenses = $this->expenses()->map(fn (Expense $expense) => [
            'kind' => 'one_off',
            'id' => $expense->id,
            'date' => CarbonImmutable::instance($expense->paid_on),
            'name' => $expense->title,
            'method' => $expense->paymentMethod->name,
            'owner' => $expense->owner->name,
            'amount' => $expense->amount,
            'currency' => $expense->currency->value,
            'thb' => $expense->thbAmount(),
            'receipt' => $expense->receiptStatus(),
            'model' => $expense,
        ]);

        return $charges->toBase()->concat($expenses->toBase())
            ->sortBy([['date', 'desc'], ['kind', 'asc'], ['id', 'desc']])
            ->values();
    }
}
