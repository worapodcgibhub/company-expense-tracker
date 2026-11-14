<?php

use App\Models\User;

test('home redirects to the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
});

test('guests visiting home end up on the login page', function () {
    $this->followingRedirects()
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('login.store'), false);

    $this->assertGuest();
});

test('authenticated users visiting home end up on the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
});

test('default laravel welcome page is no longer shown', function () {
    $this->followingRedirects()
        ->get(route('home'))
        ->assertDontSee('Deploy now')
        ->assertDontSee('Laracasts');
});
