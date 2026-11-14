<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

const USER_MGMT_PASSWORD = 'N3w!Staff#Pass2026';

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('users.index'))->assertRedirect(route('login'));
});

test('staff and accountants cannot open the user management page', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('users.index'))
        ->assertForbidden();
})->with([UserRole::Accountant, UserRole::Staff]);

test('non-owners cannot use the user management component directly', function (UserRole $role) {
    Livewire::actingAs(User::factory()->create(['role' => $role]))
        ->test('pages::users.index')
        ->assertForbidden();
})->with([UserRole::Accountant, UserRole::Staff]);

test('the owner can open the page and sees every user', function () {
    $owner = User::factory()->owner()->create(['name' => 'เจ้าของ บริษัท']);
    User::factory()->accountant()->create(['name' => 'บัญชี หนึ่ง']);
    User::factory()->deactivated()->create(['name' => 'พนักงาน เก่า']);

    $this->actingAs($owner)
        ->get(route('users.index'))
        ->assertOk()
        ->assertSee('ผู้ใช้งานและสิทธิ์')
        ->assertSee('เจ้าของ บริษัท')
        ->assertSee('บัญชี หนึ่ง')
        ->assertSee('พนักงาน เก่า')
        ->assertSee('ปิดใช้งาน')
        ->assertDontSee('ลบผู้ใช้');
});

test('the admin menu is shown to the owner only', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->get(route('dashboard'))
        ->assertSee('data-test="admin-menu"', false)
        ->assertSee(route('users.index'));

    $this->actingAs(User::factory()->accountant()->create())
        ->get(route('dashboard'))
        ->assertDontSee('data-test="admin-menu"', false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee('data-test="admin-menu"', false);
});

/*
|--------------------------------------------------------------------------
| Creating users
|--------------------------------------------------------------------------
*/

function fillNewUser(object $component, array $overrides = []): object
{
    $data = array_merge([
        'name' => 'สมหญิง บัญชีดี',
        'email' => 'Accountant@Example.com ',
        'role' => 'accountant',
        'password' => USER_MGMT_PASSWORD,
        'password_confirmation' => USER_MGMT_PASSWORD,
    ], $overrides);

    foreach ($data as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

test('the owner can create a user with a role', function () {
    $owner = User::factory()->owner()->create();

    fillNewUser(Livewire::actingAs($owner)->test('pages::users.index')->call('openCreate'))
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'accountant@example.com')->sole();

    expect($user->name)->toBe('สมหญิง บัญชีดี')
        ->and($user->role)->toBe(UserRole::Accountant)
        ->and($user->isActive())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe(USER_MGMT_PASSWORD)
        ->and(Hash::check(USER_MGMT_PASSWORD, $user->password))->toBeTrue();
});

test('the new user can sign in with the password set by the owner', function () {
    $owner = User::factory()->owner()->create();

    fillNewUser(Livewire::actingAs($owner)->test('pages::users.index'), ['role' => 'staff'])
        ->call('save')
        ->assertHasNoErrors();

    auth()->logout();

    $this->post(route('login.store'), [
        'email' => 'accountant@example.com',
        'password' => USER_MGMT_PASSWORD,
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs(User::where('email', 'accountant@example.com')->sole());
});

test('creating a user validates the input', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create(['email' => 'taken@example.com']);

    fillNewUser(Livewire::actingAs($owner)->test('pages::users.index'), $overrides)
        ->call('save')
        ->assertHasErrors($field);

    expect(User::count())->toBe(1);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'duplicate email' => [['email' => 'TAKEN@example.com'], 'email'],
    'unknown role' => [['role' => 'superadmin'], 'role'],
    'weak password' => [['password' => 'password', 'password_confirmation' => 'password'], 'password'],
    'password mismatch' => [['password_confirmation' => 'Different!Pass2026'], 'password'],
]);

test('role cannot be set through mass assignment', function () {
    $user = User::create([
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => USER_MGMT_PASSWORD,
        'role' => 'owner',
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Staff);
});

/*
|--------------------------------------------------------------------------
| Editing users
|--------------------------------------------------------------------------
*/

test('the owner can edit another user including their role', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create(['name' => 'ชื่อเดิม']);

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openEdit', $staff->id)
        ->assertSet('name', 'ชื่อเดิม')
        ->assertSet('role', 'staff')
        ->set('name', 'ชื่อใหม่')
        ->set('email', 'New@Example.com')
        ->set('role', 'accountant')
        ->call('save')
        ->assertHasNoErrors();

    $staff->refresh();

    expect($staff->name)->toBe('ชื่อใหม่')
        ->and($staff->email)->toBe('new@example.com')
        ->and($staff->role)->toBe(UserRole::Accountant);
});

test('editing does not change the password', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $hash = $staff->password;

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openEdit', $staff->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($staff->fresh()->password)->toBe($hash);
});

test('the owner cannot change their own role', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openEdit', $owner->id)
        ->set('role', 'staff')
        ->call('save')
        ->assertHasErrors('role');

    expect($owner->fresh()->role)->toBe(UserRole::Owner);
});

test('the owner can still edit their own name', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openEdit', $owner->id)
        ->set('name', 'Owner Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($owner->fresh())
        ->name->toBe('Owner Renamed')
        ->role->toBe(UserRole::Owner);
});

test('the owner can demote another owner', function () {
    $owner = User::factory()->owner()->create();
    $secondOwner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openEdit', $secondOwner->id)
        ->set('role', 'accountant')
        ->call('save')
        ->assertHasNoErrors();

    expect($secondOwner->fresh()->role)->toBe(UserRole::Accountant);
});

/*
|--------------------------------------------------------------------------
| Passwords
|--------------------------------------------------------------------------
*/

test('the owner can set a new password for another user', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();
    $oldToken = $staff->remember_token;

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openResetPassword', $staff->id)
        ->set('password', USER_MGMT_PASSWORD)
        ->set('password_confirmation', USER_MGMT_PASSWORD)
        ->call('resetPassword')
        ->assertHasNoErrors();

    $staff->refresh();

    expect(Hash::check(USER_MGMT_PASSWORD, $staff->password))->toBeTrue()
        ->and($staff->remember_token)->not->toBe($oldToken);
});

test('a reset password must be strong', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openResetPassword', $staff->id)
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('resetPassword')
        ->assertHasErrors('password');

    expect(Hash::check('password', $staff->fresh()->password))->toBeTrue();
});

test('the owner cannot reset their own password from this page', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('openResetPassword', $owner->id)
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Deactivating (users are never deleted)
|--------------------------------------------------------------------------
*/

test('the owner can deactivate and reactivate another user', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->create();

    $component = Livewire::actingAs($owner)->test('pages::users.index');

    $component->call('deactivate', $staff->id)->assertHasNoErrors();
    expect($staff->fresh()->isActive())->toBeFalse();

    $component->call('reactivate', $staff->id)->assertHasNoErrors();
    expect($staff->fresh()->isActive())->toBeTrue();

    expect(User::count())->toBe(2);
});

test('the owner cannot deactivate themselves', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::users.index')
        ->call('deactivate', $owner->id)
        ->assertForbidden();

    expect($owner->fresh()->isActive())->toBeTrue();
});

test('an active user cannot be reactivated and an inactive one cannot be deactivated again', function () {
    $owner = User::factory()->owner()->create();
    $active = User::factory()->create();
    $inactive = User::factory()->deactivated()->create();

    Livewire::actingAs($owner)->test('pages::users.index')->call('reactivate', $active->id)->assertForbidden();
    Livewire::actingAs($owner)->test('pages::users.index')->call('deactivate', $inactive->id)->assertForbidden();
});

test('there is always at least one active owner', function () {
    $owner = User::factory()->owner()->create();

    // The only owner can neither demote nor deactivate themselves...
    Livewire::actingAs($owner)->test('pages::users.index')
        ->call('openEdit', $owner->id)->set('role', 'accountant')->call('save')->assertHasErrors('role');
    Livewire::actingAs($owner)->test('pages::users.index')
        ->call('deactivate', $owner->id)->assertForbidden();

    expect(User::active()->where('role', UserRole::Owner)->count())->toBe(1);
});

test('a deactivated owner loses access to user management', function () {
    $owner = User::factory()->owner()->deactivated()->create();

    expect($owner->can('viewAny', User::class))->toBeFalse();
});
