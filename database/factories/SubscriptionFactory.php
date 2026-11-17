<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\Currency;
use App\Enums\SubscriptionCategory;
use App\Enums\SubscriptionStatus;
use App\Models\CreditCard;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Phase 3.1b: a saved subscription needs its card's payment method.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription) {
            if ($subscription->credit_card_id !== null) {
                PaymentMethodFactory::ensureForCard($subscription->credit_card_id);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $next = now()->startOfDay()->addDays(fake()->numberBetween(5, 25));

        return [
            'name' => fake()->randomElement(['Adobe', 'Canva', 'Figma', 'Notion', 'Slack', 'Zoom', 'GitHub', 'Dropbox']).' '.fake()->unique()->numberBetween(1, 99999),
            'vendor' => fake()->company(),
            'category' => SubscriptionCategory::Software,
            'responsible_user_id' => User::factory(),
            'credit_card_id' => CreditCard::factory(),
            'billing_cycle' => BillingCycle::Monthly,
            'billing_day' => $next->day,
            'next_charge_date' => $next,
            'currency' => Currency::THB,
            'estimated_amount' => 59_000,
            'remind_days_before' => 3,
            'status' => SubscriptionStatus::Active,
            'notes' => null,
        ];
    }

    /**
     * Next charge on $date (keeps billing_day consistent with it).
     */
    public function chargingOn(\DateTimeInterface $date, ?int $billingDay = null): static
    {
        return $this->state(fn (array $attributes) => [
            'next_charge_date' => $date,
            'billing_day' => $billingDay ?? (int) $date->format('j'),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SubscriptionStatus::Paused, 'status_changed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SubscriptionStatus::Cancelled, 'status_changed_at' => now()]);
    }
}
