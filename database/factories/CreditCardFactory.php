<?php

namespace Database\Factories;

use App\Enums\CardKind;
use App\Enums\CardNetwork;
use App\Models\CreditCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /** @var WeakMap<CreditCard, bool>|null */
    private static ?WeakMap $withoutMethod = null;

    /**
     * Phase 3.1b: in the app every card has a payment method (backfilled in
     * 3.1a). Test cards get one too, unless withoutPaymentMethod() is used.
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (CreditCard $card) {
                if ($card->getAttribute('without_payment_method') === true) {
                    $card->offsetUnset('without_payment_method');
                    self::$withoutMethod ??= new WeakMap;
                    self::$withoutMethod[$card] = true;
                }
            })
            ->afterCreating(function (CreditCard $card) {
                if (! isset(self::$withoutMethod[$card])) {
                    PaymentMethodFactory::ensureForCard($card->id);
                }
            });
    }

    /**
     * A card that has no payment method (to test the error case).
     */
    public function withoutPaymentMethod(): static
    {
        return $this->state(fn (array $attributes) => ['without_payment_method' => true]);
    }

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
