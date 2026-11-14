<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;

test('a deactivated user cannot sign in even with the correct password', function () {
    $user = User::factory()->deactivated()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => EnsureUserIsActive::MESSAGE]);

    $this->assertGuest();
});

test('a wrong password for a deactivated user gets the normal error, not the deactivated one', function () {
    $user = User::factory()->deactivated()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->not->toBe(EnsureUserIsActive::MESSAGE);

    $this->assertGuest();
});

test('a user deactivated while signed in is signed out on the next request', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['deactivated_at' => now()])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', EnsureUserIsActive::MESSAGE);

    $this->assertGuest();
});

test('a reactivated user can sign in again', function () {
    $user = User::factory()->deactivated()->create();
    $user->forceFill(['deactivated_at' => null])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});
