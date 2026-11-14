<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

const STRONG_PASSWORD = 'Str0ng!Passw0rd#2026';

function runCreateOwner(object $test, array $answers, ?bool $confirmAnother = null)
{
    $command = $test->artisan('user:create-owner');

    if ($confirmAnother !== null) {
        $command->expectsConfirmation(
            'มีบัญชี Owner อยู่แล้ว ต้องการสร้าง Owner เพิ่มอีกบัญชีหรือไม่?',
            $confirmAnother ? 'yes' : 'no',
        );

        if (! $confirmAnother) {
            return $command;
        }
    }

    return $command
        ->expectsQuestion('ชื่อ-นามสกุล', $answers['name'] ?? 'Company Owner')
        ->expectsQuestion('อีเมล', $answers['email'] ?? 'owner@example.com')
        ->expectsQuestion('รหัสผ่าน', $answers['password'] ?? STRONG_PASSWORD)
        ->expectsQuestion('ยืนยันรหัสผ่าน', $answers['confirm'] ?? $answers['password'] ?? STRONG_PASSWORD);
}

test('it creates a verified owner with a hashed password', function () {
    runCreateOwner($this, ['email' => '  Owner@Example.com '])
        ->expectsOutputToContain('สร้างบัญชี Owner เรียบร้อยแล้ว')
        ->doesntExpectOutputToContain(STRONG_PASSWORD)
        ->assertSuccessful();

    $user = User::sole();

    expect($user->email)->toBe('owner@example.com')
        ->and($user->role)->toBe(UserRole::Owner)
        ->and($user->isOwner())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe(STRONG_PASSWORD)
        ->and(Hash::check(STRONG_PASSWORD, $user->password))->toBeTrue();
});

test('the created owner can log in', function () {
    runCreateOwner($this, [])->assertSuccessful();

    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => STRONG_PASSWORD,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('it rejects weak passwords', function (string $weak) {
    runCreateOwner($this, ['password' => $weak])
        ->doesntExpectOutputToContain($weak)
        ->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'too short' => 'Ab1!short',
    'no symbol' => 'NoSymbolPassw0rd',
    'no number' => 'NoNumber!Password',
    'no uppercase' => 'no-upper-case-1!',
    'no lowercase' => 'NO-LOWER-CASE-1!',
]);

test('it rejects a mismatched password confirmation', function () {
    runCreateOwner($this, ['confirm' => 'Different!Passw0rd'])
        ->expectsOutputToContain('รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

test('it rejects an email that is already registered, ignoring case', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    runCreateOwner($this, ['email' => 'OWNER@example.com'])
        ->expectsOutputToContain('อีเมลนี้มีบัญชีอยู่แล้ว')
        ->assertFailed();

    expect(User::count())->toBe(1)
        ->and(User::sole()->role)->toBe(UserRole::Staff);
});

test('it rejects an invalid email', function () {
    runCreateOwner($this, ['email' => 'not-an-email'])->assertFailed();

    expect(User::count())->toBe(0);
});

test('it does not create another owner unless explicitly confirmed', function () {
    User::factory()->owner()->create();

    runCreateOwner($this, [], confirmAnother: false)
        ->expectsOutputToContain('ยกเลิก')
        ->assertFailed();

    expect(User::where('role', UserRole::Owner)->count())->toBe(1);
});

test('it creates another owner when explicitly confirmed', function () {
    User::factory()->owner()->create();

    runCreateOwner($this, ['email' => 'second-owner@example.com'], confirmAnother: true)
        ->assertSuccessful();

    expect(User::where('role', UserRole::Owner)->count())->toBe(2);
});

test('new users default to the staff role', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Staff)
        ->and((new User)->role)->toBe(UserRole::Staff);
});

test('role cannot be set through mass assignment', function () {
    $user = User::create([
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => STRONG_PASSWORD,
        'role' => 'owner',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Staff);
});

test('role cannot be changed through mass assignment on update', function () {
    $user = User::factory()->create();

    $user->update(['name' => 'Updated', 'role' => 'owner']);

    expect($user->fresh()->name)->toBe('Updated')
        ->and($user->fresh()->role)->toBe(UserRole::Staff);
});
