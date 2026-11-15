<?php

use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
});

/*
|--------------------------------------------------------------------------
| Pages (route level)
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $subscription = Subscription::factory()->create();

    $this->get(route('subscriptions.index'))->assertRedirect(route('login'));
    $this->get(route('subscriptions.show', $subscription))->assertRedirect(route('login'));
});

test('owner and accountant see every subscription on the list', function (UserRole $role) {
    Subscription::factory()->create(['name' => 'Canva ทีมการตลาด']);
    Subscription::factory()->create(['name' => 'Figma ทีมออกแบบ']);

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('subscriptions.index'))
        ->assertOk()
        ->assertSee('Canva ทีมการตลาด')
        ->assertSee('Figma ทีมออกแบบ');
})->with([UserRole::Owner, UserRole::Accountant]);

test('staff see only the subscriptions they are responsible for', function () {
    $staff = User::factory()->create();
    Subscription::factory()->create(['name' => 'Canva ของฉัน', 'responsible_user_id' => $staff->id]);
    Subscription::factory()->create(['name' => 'Figma ของคนอื่น']);

    $this->actingAs($staff)
        ->get(route('subscriptions.index'))
        ->assertOk()
        ->assertSee('Subscription ของฉัน')
        ->assertSee('Canva ของฉัน')
        ->assertDontSee('Figma ของคนอื่น')
        ->assertDontSee('data-test="add-subscription-button"', false);
});

test('staff can open their own subscription but get 403 for anyone else\'s', function () {
    $staff = User::factory()->create();
    $own = Subscription::factory()->create(['responsible_user_id' => $staff->id]);
    $other = Subscription::factory()->create();

    $this->actingAs($staff)->get(route('subscriptions.show', $own))->assertOk();
    $this->actingAs($staff)->get(route('subscriptions.show', $other))->assertForbidden();
});

test('only the owner can open the add and edit pages', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $subscription = Subscription::factory()->create(['responsible_user_id' => $user->id]);

    $this->actingAs($user)->get(route('subscriptions.create'))->assertForbidden();
    $this->actingAs($user)->get(route('subscriptions.edit', $subscription))->assertForbidden();
})->with([UserRole::Accountant, UserRole::Staff]);

test('the owner can open the add and edit pages', function () {
    $owner = User::factory()->owner()->create();
    $subscription = Subscription::factory()->create();

    $this->actingAs($owner)->get(route('subscriptions.create'))->assertOk();
    $this->actingAs($owner)->get(route('subscriptions.edit', $subscription))->assertOk()->assertSee($subscription->name);
});

test('the accountant sees the detail page without any change buttons', function () {
    $subscription = Subscription::factory()->create();

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertDontSee('data-test="edit-button"', false)
        ->assertDontSee('data-test="edit-notes-button"', false)
        ->assertDontSee('data-test="pause-button"', false)
        ->assertDontSee('data-test="cancel-button"', false);
});

test('responsible staff see only the edit-notes button', function () {
    $staff = User::factory()->create();
    $subscription = Subscription::factory()->create(['responsible_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertSee('data-test="edit-notes-button"', false)
        ->assertDontSee('data-test="edit-button"', false)
        ->assertDontSee('data-test="pause-button"', false)
        ->assertDontSee('data-test="cancel-button"', false);
});

test('the finance menu shows "my subscriptions" to staff and the full menu to others', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('data-test="subscriptions-menu"', false)
        ->assertSee('Subscription ของฉัน')
        ->assertDontSee('data-test="credit-cards-menu"', false);

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('dashboard'))
        ->assertSee('data-test="subscriptions-menu"', false)
        ->assertSee('data-test="credit-cards-menu"', false);
});

/*
|--------------------------------------------------------------------------
| Server side (Livewire actions called directly)
|--------------------------------------------------------------------------
*/

test('non-owners cannot use the add/edit form component directly', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $subscription = Subscription::factory()->create(['responsible_user_id' => $user->id]);

    Livewire::actingAs($user)->test('pages::subscriptions.form')->assertForbidden();
    Livewire::actingAs($user)->test('pages::subscriptions.form', ['subscription' => $subscription])->assertForbidden();
})->with([UserRole::Accountant, UserRole::Staff]);

test('staff cannot open someone else\'s subscription component directly', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::subscriptions.show', ['subscription' => Subscription::factory()->create()])
        ->assertForbidden();
});

test('a deactivated owner cannot do anything', function () {
    $owner = User::factory()->owner()->deactivated()->create();

    Livewire::actingAs($owner)->test('pages::subscriptions.index')->assertForbidden();
    Livewire::actingAs($owner)->test('pages::subscriptions.form')->assertForbidden();
});

test('the accountant cannot change status or notes even when calling actions directly', function (string $method, array $args) {
    $subscription = Subscription::factory()->create(['notes' => 'เดิม']);
    $subscription->forceFill(['status' => $method === 'openResume' ? 'paused' : 'active'])->save();

    Livewire::actingAs(User::factory()->accountant()->create())
        ->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->call($method, ...$args)
        ->assertForbidden();

    expect($subscription->fresh()->notes)->toBe('เดิม');
})->with([
    'pause' => ['openStatusChange', ['pause']],
    'cancel' => ['openStatusChange', ['cancel']],
    'confirm' => ['confirmStatusChange', []],
    'resume' => ['openResume', []],
    'confirm resume' => ['confirmResume', []],
    'open notes' => ['openNotes', []],
    'save notes' => ['saveNotes', []],
]);

test('responsible staff cannot change status even when calling actions directly', function (string $method, array $args) {
    $staff = User::factory()->create();
    $subscription = Subscription::factory()->create(['responsible_user_id' => $staff->id]);

    Livewire::actingAs($staff)
        ->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->call($method, ...$args)
        ->assertForbidden();

    expect($subscription->fresh()->isActive())->toBeTrue();
})->with([
    'pause' => ['openStatusChange', ['pause']],
    'cancel' => ['openStatusChange', ['cancel']],
    'confirm' => ['confirmStatusChange', []],
    'resume' => ['openResume', []],
]);

test('responsible staff can edit the notes and nothing else', function () {
    $staff = User::factory()->create();
    $subscription = Subscription::factory()->create(['responsible_user_id' => $staff->id, 'estimated_amount' => 59000, 'notes' => null]);
    $before = $subscription->fresh()->only(['name', 'vendor', 'estimated_amount', 'currency', 'credit_card_id', 'billing_cycle', 'billing_day', 'next_charge_date', 'status']);

    Livewire::actingAs($staff)
        ->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->call('openNotes')
        ->set('notes', 'ต่ออายุอัตโนมัติ ใช้ 3 ที่นั่ง')
        ->call('saveNotes')
        ->assertHasNoErrors();

    $subscription->refresh();

    expect($subscription->notes)->toBe('ต่ออายุอัตโนมัติ ใช้ 3 ที่นั่ง')
        ->and($subscription->updated_by)->toBe($staff->id)
        ->and($subscription->only(array_keys($before)))->toEqual($before);
});

test('notes from staff are still checked for full card numbers', function () {
    $staff = User::factory()->create();
    $subscription = Subscription::factory()->create(['responsible_user_id' => $staff->id]);

    Livewire::actingAs($staff)
        ->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->set('notes', 'บัตร 4111 1111 1111 1111')
        ->call('saveNotes')
        ->assertHasErrors(['notes']);

    expect($subscription->fresh()->notes)->toBeNull();
});

test('an accountant who is responsible still cannot edit notes', function () {
    $accountant = User::factory()->accountant()->create();
    $subscription = Subscription::factory()->create(['responsible_user_id' => $accountant->id]);

    Livewire::actingAs($accountant)
        ->test('pages::subscriptions.show', ['subscription' => $subscription])
        ->call('saveNotes')
        ->assertForbidden();
});
