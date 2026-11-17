<?php

namespace Database\Factories;

use App\Enums\ReceiptKind;
use App\Models\ChargeReceipt;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ReceiptStorage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test data only: a database row without a real file. Real documents are
 * attached through SubscriptionCharge::attachReceipt(), which stores the
 * file and writes the history.
 *
 * @extends Factory<ChargeReceipt>
 */
class ChargeReceiptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_charge_id' => SubscriptionCharge::factory(),
            'kind' => ReceiptKind::Receipt,
            'document_number' => null,
            'document_date' => null,
            'original_name' => 'receipt.pdf',
            'disk' => ReceiptStorage::DISK,
            'path' => 'receipts/test/'.Str::uuid()->toString().'.pdf',
            'mime' => 'application/pdf',
            'size' => 12_345,
            'sha256' => hash('sha256', Str::random(32)),
            'uploaded_by' => User::factory()->owner(),
        ];
    }

    public function removed(string $reason = 'ไฟล์ผิด'): static
    {
        return $this->state(fn (array $attributes) => [
            'removed_at' => now(),
            'removed_by' => User::factory()->owner(),
            'remove_reason' => $reason,
        ]);
    }
}
