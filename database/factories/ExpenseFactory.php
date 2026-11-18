<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only.
 *
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'ค่า'.fake()->word(),
            'category' => ExpenseCategory::Equipment,
            'paid_on' => now()->startOfDay()->subDays(fake()->numberBetween(1, 5)),
            'currency' => Currency::THB,
            'amount' => 250_000,
            'amount_thb' => null,
            'payment_method_id' => PaymentMethod::factory()->cash(),
            'owner_user_id' => User::factory(),
            'recorded_by' => fn (array $attributes) => $attributes['owner_user_id'],
            'notes' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => $user->id,
            'recorded_by' => $user->id,
        ]);
    }

    public function paidOn(DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => ['paid_on' => $date]);
    }

    /**
     * A USD expense; $thb is the baht really paid (null = not known yet).
     */
    public function usd(int $amount = 2_000, ?int $thb = null): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => Currency::USD,
            'amount' => $amount,
            'amount_thb' => $thb,
        ]);
    }

    public function receiptReceived(): static
    {
        return $this->state(fn (array $attributes) => ['receipt_received_at' => now()]);
    }
}
