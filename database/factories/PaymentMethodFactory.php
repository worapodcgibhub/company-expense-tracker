<?php

namespace Database\Factories;

use App\Enums\PaymentMethodType;
use App\Enums\SettlementMode;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Defaults to a card method; use forBankAccount() / cash() for the others.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'ช่องทาง '.fake()->unique()->word(),
            'type' => PaymentMethodType::Card,
            'settlement' => SettlementMode::Auto,
            'credit_card_id' => CreditCard::factory()->withoutPaymentMethod(),
            'bank_account_id' => null,
        ];
    }

    /**
     * The card's existing payment method, or a plain card/auto one when a
     * test card has none yet. Test helper only — the app never does this.
     */
    public static function ensureForCard(int $cardId): PaymentMethod
    {
        return PaymentMethod::query()->firstOrCreate(
            ['credit_card_id' => $cardId],
            [
                'name' => 'บัตร '.$cardId,
                'type' => PaymentMethodType::Card,
                'settlement' => SettlementMode::Auto,
            ],
        );
    }

    public function forBankAccount(PaymentMethodType $type = PaymentMethodType::BankTransfer): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'settlement' => $type->defaultSettlement(),
            'credit_card_id' => null,
            'bank_account_id' => BankAccount::factory(),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PaymentMethodType::Cash,
            'settlement' => SettlementMode::Manual,
            'credit_card_id' => null,
            'bank_account_id' => null,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['deactivated_at' => now()]);
    }
}
