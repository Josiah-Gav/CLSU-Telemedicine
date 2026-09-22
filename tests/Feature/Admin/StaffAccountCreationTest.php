<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

function admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function staffPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Maria',
        'last_name' => 'Cruz',
        'email' => 'maria.cruz@clsu.edu.ph',
        'role' => 'nurse',
        'clsu_id' => '2021-12345',
        'user_type' => 'staff',
        'department' => 'University Health Service',
        'contact_num' => '09171234567',
        'staff_position' => 'Staff Nurse',
    ], $overrides);
}

function createStaff(array $overrides = []): TestResponse
{
    return test()->actingAs(admin())->post(route('admin.users.store'), staffPayload($overrides));
}

// --- creation ---------------------------------------------------------------

test('an admin can create a nurse, physician, or admin by invitation', function (string $role) {
    $response = createStaff(['role' => $role]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    expect($user->role)->toBe($role)
        ->and($user->account_status)->toBe('inactive')
        ->and($user->online_status)->toBe('offline')
        ->and($user->email_verified_at)->toBeNull();
})->with(['nurse', 'physician', 'admin']);

test('the profile fields the admin entered are stored', function () {
    createStaff(['role' => 'physician', 'specialization' => 'Cardiology']);

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    expect($user->first_name)->toBe('Maria')
        ->and($user->last_name)->toBe('Cruz')
        ->and($user->clsu_id)->toBe('2021-12345')
        ->and($user->user_type)->toBe('staff')
        ->and($user->department)->toBe('University Health Service')
        ->and($user->contact_num)->toBe('09171234567')
        ->and($user->specialization)->toBe('Cardiology');
});

test('no password is required and none is ever chosen by the admin', function () {
    $response = createStaff();

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    // Whatever placeholder fills the NOT NULL column, it must not be anything
    // an admin could have supplied or guessed.
    expect(Hash::check('password', $user->password))->toBeFalse()
        ->and(Hash::check('password123', $user->password))->toBeFalse()
        ->and(Hash::check('', $user->password))->toBeFalse()
        ->and(Hash::check($user->email, $user->password))->toBeFalse();
});

test('a submitted password is ignored rather than used for staff', function () {
    createStaff(['password' => 'admin-chosen-pass-1', 'password_confirmation' => 'admin-chosen-pass-1'])
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    expect(Hash::check('admin-chosen-pass-1', $user->password))->toBeFalse()
        ->and($user->account_status)->toBe('inactive');
});

// --- the invitation ---------------------------------------------------------

test('an invitation is generated for the new staff account', function () {
    createStaff();

    expect(DB::table('staff_invitation_tokens')->where('email', 'maria.cruz@clsu.edu.ph')->exists())->toBeTrue();
});

test('the generated invitation expires after exactly seven days', function () {
    createStaff();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();
    $record = DB::table('staff_invitation_tokens')->where('email', $user->email)->first();

    // The stored token is hashed, so validity is checked through the broker.
    $this->travelTo(now()->addDays(7)->subMinute());
    expect(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeTrue();

    $this->travelBack();

    expect(now()->diffInMinutes($record->created_at, absolute: true))->toBeLessThan(2)
        ->and(config('auth.passwords.staff_invitations.expire'))->toBe(10080);
});

test('the invitation created here works with the activation flow end to end', function () {
    createStaff(['role' => 'physician']);

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    // The admin flow does not surface the plaintext token (Phase 5 mails it),
    // so re-issue one through the same broker to drive the activation route.
    $token = Password::broker('staff_invitations')->createToken($user);

    // The invitee arrives unauthenticated; the activation routes are guest-only.
    auth()->logout();

    $this->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'chosen-by-the-staff-1',
        'password_confirmation' => 'chosen-by-the-staff-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $user->refresh();

    expect($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->role)->toBe('physician')
        ->and(Hash::check('chosen-by-the-staff-1', $user->password))->toBeTrue()
        ->and(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeFalse();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'chosen-by-the-staff-1',
    ])->assertRedirect(route('dashboard'));
});

// --- rejection --------------------------------------------------------------

test('an admin cannot create staff that are already active', function () {
    createStaff(['account_status' => 'active'])->assertSessionHasErrors('account_status');

    $this->assertDatabaseMissing('users', ['email' => 'maria.cruz@clsu.edu.ph']);
});

test('a duplicate email is rejected and no invitation is left behind', function () {
    User::factory()->create(['email' => 'maria.cruz@clsu.edu.ph']);

    createStaff()->assertSessionHasErrors('email');

    expect(User::where('email', 'maria.cruz@clsu.edu.ph')->count())->toBe(1)
        ->and(DB::table('staff_invitation_tokens')->count())->toBe(0);
});

test('a non-admin cannot create staff', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);

    $this->actingAs($actor)->post(route('admin.users.store'), staffPayload())->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'maria.cruz@clsu.edu.ph']);
    expect(DB::table('staff_invitation_tokens')->count())->toBe(0);
})->with(['patient', 'nurse', 'physician']);

test('an invalid role cannot reach the invitation path', function () {
    createStaff(['role' => 'superuser'])->assertSessionHasErrors('role');

    $this->assertDatabaseMissing('users', ['email' => 'maria.cruz@clsu.edu.ph']);
});

// --- atomicity --------------------------------------------------------------

test('the user is rolled back if the invitation cannot be written', function () {
    $actor = admin();

    Schema::drop('staff_invitation_tokens');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($actor)->post(route('admin.users.store'), staffPayload()))
        ->toThrow(QueryException::class);

    $this->assertDatabaseMissing('users', ['email' => 'maria.cruz@clsu.edu.ph']);
});

// --- preserved behaviour ----------------------------------------------------

test('patient creation still requires a password and still works', function () {
    $this->actingAs(admin())->post(route('admin.users.store'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@clsu.edu.ph',
        'password' => 'patient-password-1',
        'password_confirmation' => 'patient-password-1',
        'role' => 'patient',
        'account_status' => 'active',
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'juan@clsu.edu.ph')->firstOrFail();

    expect($user->account_status)->toBe('active')
        ->and(Hash::check('patient-password-1', $user->password))->toBeTrue()
        ->and(DB::table('staff_invitation_tokens')->count())->toBe(0);
});

test('patient creation without a password is still rejected', function () {
    $this->actingAs(admin())->post(route('admin.users.store'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@clsu.edu.ph',
        'role' => 'patient',
        'account_status' => 'active',
    ])->assertSessionHasErrors('password');

    $this->assertDatabaseMissing('users', ['email' => 'juan@clsu.edu.ph']);
});

test('an invited admin stays unverified until activation, then can sign in as admin', function () {
    // Regression coverage for a real gap this change introduced: User::booted()
    // auto-verifies any role => admin on creation, which would silently
    // bypass this invited path's 'inactive'/unverified state unless that hook
    // is scoped to exclude account_status 'inactive'.
    createStaff(['role' => 'admin', 'email' => 'ada@clsu.edu.ph']);

    $user = User::where('email', 'ada@clsu.edu.ph')->firstOrFail();

    expect($user->account_status)->toBe('inactive')
        ->and($user->email_verified_at)->toBeNull();

    $token = Password::broker('staff_invitations')->createToken($user);

    auth()->logout();

    $this->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'chosen-by-the-admin-1',
        'password_confirmation' => 'chosen-by-the-admin-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $user->refresh();

    expect($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->role)->toBe('admin')
        ->and(Hash::check('chosen-by-the-admin-1', $user->password))->toBeTrue();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'chosen-by-the-admin-1',
    ])->assertRedirect(route('dashboard'));
});

test('user editing still works', function () {
    $target = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);

    $this->actingAs(admin())->put(route('admin.users.update', $target), [
        'first_name' => 'Edited',
        'last_name' => 'Name',
        'email' => $target->email,
        'role' => 'patient',
        'account_status' => 'suspended',
        'department' => 'Nursing',
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $target->refresh();

    expect($target->first_name)->toBe('Edited')
        ->and($target->account_status)->toBe('suspended')
        ->and($target->department)->toBe('Nursing');
});

test('an admin cannot set an active account to inactive', function () {
    // 'inactive' means "awaiting email verification" and is only ever set by
    // the registration/activation flow itself. An admin choosing to disable an
    // already-verified account must use 'suspended' instead.
    $target = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);

    $this->actingAs(admin())->put(route('admin.users.update', $target), [
        'first_name' => $target->first_name,
        'last_name' => $target->last_name,
        'email' => $target->email,
        'role' => 'patient',
        'account_status' => 'inactive',
    ])->assertSessionHasErrors('account_status');

    expect($target->fresh()->account_status)->toBe('active');
});

test('editing an invited nurse does not silently verify them', function () {
    createStaff();

    $nurse = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    $this->actingAs(admin())->put(route('admin.users.update', $nurse), [
        'first_name' => 'Maria',
        'last_name' => 'Cruz',
        'email' => $nurse->email,
        'role' => 'nurse',
        'account_status' => 'inactive',
    ])->assertSessionHasNoErrors();

    expect($nurse->fresh()->email_verified_at)->toBeNull();
});

test('the user listing still renders', function () {
    createStaff();

    $this->actingAs(admin())->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('maria.cruz@clsu.edu.ph')
        ->assertSee('Inactive');
});

test('the create form no longer asks for a password', function () {
    $response = $this->actingAs(admin())->get(route('admin.users.create'));

    $response->assertOk()
        ->assertDontSee('name="password"', false)
        ->assertDontSee('name="password_confirmation"', false)
        ->assertSee('name="clsu_id"', false)
        ->assertSee('name="contact_num"', false);
});
