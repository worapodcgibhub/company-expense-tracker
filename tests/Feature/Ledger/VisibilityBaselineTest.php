<?php

use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;

// Baseline: who may see a charge. Staff access follows the person currently
// responsible for the subscription, never the person who recorded it.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->staffA = User::factory()->create();
    $this->staffB = User::factory()->create();
});

test('staff access follows the current responsible person, not who recorded the charge', function () {
    $subscription = Subscription::factory()->create(['responsible_user_id' => $this->staffA->id]);
    $charge = SubscriptionCharge::factory()->for($subscription)->create(['recorded_by' => $this->owner->id]);

    $visibleTo = fn (User $user) => SubscriptionCharge::query()->visibleTo($user)->pluck('id')->all();

    expect($visibleTo($this->staffA))->toBe([$charge->id])
        ->and($visibleTo($this->staffB))->toBe([]);

    $subscription->forceFill(['responsible_user_id' => $this->staffB->id])->save();

    expect($visibleTo($this->staffA))->toBe([])
        ->and($visibleTo($this->staffB))->toBe([$charge->id]);
});

test('owner and accountant see charges of every subscription, staff see none of the others', function () {
    SubscriptionCharge::factory()->count(3)->create();
    $accountant = User::factory()->accountant()->create();

    expect(SubscriptionCharge::query()->visibleTo($this->owner)->count())->toBe(3)
        ->and(SubscriptionCharge::query()->visibleTo($accountant)->count())->toBe(3)
        ->and(SubscriptionCharge::query()->visibleTo($this->staffA)->count())->toBe(0);
});
