<?php

namespace Database\Factories;

use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Models\CreditCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'บัตร '.fake()->unique()->word(),
            'issuer' => fake()->randomElement(['ธนาคารกสิกรไทย', 'ธนาคารไทยพาณิชย์', 'ธนาคารกรุงเทพ', 'ธนาคารกรุงไทย']),
            'network' => fake()->randomElement(CardNetwork::cases()),
            'kind' => CardKind::Credit,
            'last_four' => fake()->numerify('####'),
            'holder_name' => fake()->name(),
            'notes' => null,
        ];
    }

    /**
     * Indicate that the Owner has deactivated this card.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }
}
