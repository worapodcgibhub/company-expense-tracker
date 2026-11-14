<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('login page shows the Company Expense Tracker branding in Thai', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee('ยินดีต้อนรับกลับมา')
        ->assertSee('เข้าสู่ระบบ')
        ->assertSee('ลืมรหัสผ่าน?');
});

test('login page uses light mode only', function () {
    $html = $this->get(route('login'))->getContent();

    expect($html)->not->toMatch('/<html[^>]*class="[^"]*\bdark\b/');
});

test('passkey buttons are hidden by default but the feature is kept', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('Sign in with a passkey'));

    expect(Route::has('passkey.login'))->toBeTrue();
});

test('passkey button can be re-enabled through config', function () {
    config(['fortify.passkeys.show_buttons' => true]);

    $this->get(route('login'))->assertSee(__('Sign in with a passkey'));
});

test('failed login shows a Thai error message', function () {
    $user = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
});

test('app layout shows Thai navigation without starter kit links', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('เมนูหลัก')
        ->assertSee('ภาพรวม')
        ->assertSee('ออกจากระบบ')
        ->assertDontSee('github.com/laravel/livewire-starter-kit')
        ->assertDontSee('laravel.com/docs')
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation');
});

test('settings navigation hides the appearance menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee(route('appearance.edit'));
});
