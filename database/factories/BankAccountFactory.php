<?php

namespace Database\Factories;

use App\Enums\BankAccountKind;
use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'บัญชี '.fake()->unique()->word(),
            'bank' => fake()->randomElement(['ธนาคารกสิกรไทย', 'ธนาคารไทยพาณิชย์', 'ธนาคารกรุงเทพ']),
            'account_name' => 'บริษัท ตัวอย่าง จำกัด',
            'last_four' => fake()->numerify('####'),
            'kind' => BankAccountKind::Current,
            'notes' => null,
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['deactivated_at' => now()]);
    }
}
