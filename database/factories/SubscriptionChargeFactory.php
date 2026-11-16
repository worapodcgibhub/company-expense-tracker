<?php

namespace Database\Factories;

use App\Enums\ChargeOutcome;
use App\Enums\Currency;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only. Real charges are created through
 * Subscription::recordCharge(), which also moves the next charge date.
 *
 * @extends Factory<SubscriptionCharge>
 */
class SubscriptionChargeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = now()->startOfDay()->subDays(fake()->numberBetween(1, 5));

        return [
            'subscription_id' => Subscription::factory(),
            'credit_card_id' => fn (array $attributes) => self::subscriptionOf($attributes)->credit_card_id,
            'due_date' => $date,
            'outcome' => ChargeOutcome::Charged,
            'charged_on' => $date,
            'currency' => fn (array $attributes) => self::subscriptionOf($attributes)->currency,
            'amount' => 59_000,
            'amount_thb' => null,
            'entered_after_close' => false,
            'notes' => null,
            'recorded_by' => User::factory()->owner(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function subscriptionOf(array $attributes): Subscription
    {
        return Subscription::query()->whereKey($attributes['subscription_id'])->firstOrFail();
    }

    /**
     * Charged on $date (also the due date unless given).
     */
    public function chargedOn(\DateTimeInterface $date, ?\DateTimeInterface $dueDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'charged_on' => $date,
            'due_date' => $dueDate ?? $date,
        ]);
    }

    /**
     * A USD charge; $thb is the baht the bank took (null = not known yet).
     */
    public function usd(int $amount = 2_000, ?int $thb = null): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::USD,
            'amount' => $amount,
            'amount_thb' => $thb,
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => ChargeOutcome::Skipped,
            'charged_on' => null,
            'amount' => 0,
            'amount_thb' => null,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
            'verified_by' => User::factory()->accountant(),
        ]);
    }
}
