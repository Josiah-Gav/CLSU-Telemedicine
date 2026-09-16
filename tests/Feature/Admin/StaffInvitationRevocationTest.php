<?php

use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/*
 * Regression cover for audit finding H-1.
 *
 * staff_invitation_tokens is keyed by email because Laravel's
 * DatabaseTokenRepository requires that schema. users.email is mutable through
 * the admin edit form, so an invitation left behind at an old address could be
 * redeemed against whoever was assigned that address next.
 */

function revocationAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function pendingNurse(string $email, array $overrides = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'first_name' => 'Alice',
        'last_name' => 'Reyes',
        'email' => $email,
        'role' => 'nurse',
        'account_status' => 'inactive',
        'password' => Hash::make('placeholder-never-shared'),
    ], $overrides));
}

function editUser(User $user, array $overrides = []): TestResponse
{
    return test()->actingAs(revocationAdmin())->put(route('admin.users.update', $user), array_merge([
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        'role' => $user->role,
        'account_status' => $user->account_status,
    ], $overrides));
}

function tokenRowExists(string $email): bool
{
    return DB::table('staff_invitation_tokens')->where('email', $email)->exists();
}

// --- the H-1 attack path, end to end ----------------------------------------

test('an invitation cannot be redeemed against a different account after an email change', function () {
    // 1-2. Alice is invited at address A.
    $alice = pendingNurse('shared-address@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    expect(Password::broker('staff_invitations')->tokenExists($alice, $token))->toBeTrue();

    // 3. An admin corrects Alice's address to B.
    editUser($alice, ['email' => 'alice.reyes@clsu.edu.ph'])->assertSessionHasNoErrors();

    // 4. The invitation at address A is gone.
    expect(tokenRowExists('shared-address@clsu.edu.ph'))->toBeFalse();

    // 5. A different eligible nurse is later assigned address A.
    $bob = pendingNurse('bob@clsu.edu.ph', ['first_name' => 'Bob', 'last_name' => 'Santos']);
    editUser($bob, ['email' => 'shared-address@clsu.edu.ph'])->assertSessionHasNoErrors();

    // 6. Alice's original link is replayed against address A by someone who is
    //    not signed in, which is how a real invitee arrives.
    auth()->logout();

    test()->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => 'shared-address@clsu.edu.ph',
        'password' => 'attacker-chosen-password-1',
        'password_confirmation' => 'attacker-chosen-password-1',
    ])->assertSessionHasErrors('email');

    // 7-8. Bob's account is untouched.
    $bob->refresh();

    expect($bob->account_status)->toBe('inactive')
        ->and($bob->email_verified_at)->toBeNull()
        ->and(Hash::check('attacker-chosen-password-1', $bob->password))->toBeFalse()
        ->and(Hash::check('placeholder-never-shared', $bob->password))->toBeTrue();
});

test('the original invitee cannot use their own link after their email is changed', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    editUser($alice, ['email' => 'alice.new@clsu.edu.ph'])->assertSessionHasNoErrors();

    auth()->logout();

    // Neither the old address nor the new one accepts the revoked token.
    test()->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => 'alice@clsu.edu.ph',
        'password' => 'chosen-password-1',
        'password_confirmation' => 'chosen-password-1',
    ])->assertSessionHasErrors('email');

    test()->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => 'alice.new@clsu.edu.ph',
        'password' => 'chosen-password-1',
        'password_confirmation' => 'chosen-password-1',
    ])->assertSessionHasErrors('email');

    expect($alice->fresh()->account_status)->toBe('inactive');
});

// --- the revocation is precisely scoped -------------------------------------

test('editing unrelated fields leaves the invitation intact', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    editUser($alice, [
        'first_name' => 'Alicia',
        'department' => 'University Health Service',
        'staff_position' => 'Head Nurse',
    ])->assertSessionHasNoErrors();

    expect($alice->fresh()->first_name)->toBe('Alicia')
        ->and(tokenRowExists('alice@clsu.edu.ph'))->toBeTrue()
        ->and(Password::broker('staff_invitations')->tokenExists($alice->fresh(), $token))->toBeTrue();
});

test('the invitation still activates the account after an unrelated edit', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    editUser($alice, ['department' => 'Nursing'])->assertSessionHasNoErrors();

    auth()->logout();

    test()->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => 'alice@clsu.edu.ph',
        'password' => 'chosen-by-alice-1',
        'password_confirmation' => 'chosen-by-alice-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect($alice->fresh()->account_status)->toBe('active');
});

test('resubmitting the same email does not revoke the invitation', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($alice);

    // The edit form always posts the email, changed or not.
    editUser($alice, ['email' => 'alice@clsu.edu.ph'])->assertSessionHasNoErrors();

    expect(tokenRowExists('alice@clsu.edu.ph'))->toBeTrue();
});

test('a user with no invitation is unaffected by an email change', function () {
    $patient = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);

    editUser($patient, ['email' => 'renamed.patient@clsu.edu.ph'])->assertSessionHasNoErrors();

    expect($patient->fresh()->email)->toBe('renamed.patient@clsu.edu.ph')
        ->and(DB::table('staff_invitation_tokens')->count())->toBe(0);
});

// --- atomicity of the revocation --------------------------------------------

test('a failed update leaves the invitation in place', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    // Validation rejects the duplicate address before the transaction opens.
    $taken = User::factory()->create(['email' => 'taken@clsu.edu.ph']);

    editUser($alice, ['email' => $taken->email])->assertSessionHasErrors('email');

    expect($alice->fresh()->email)->toBe('alice@clsu.edu.ph')
        ->and(Password::broker('staff_invitations')->tokenExists($alice->fresh(), $token))->toBeTrue();
});

test('the invitation deletion rolls back if the user update fails', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    // Fail the write after the token has already been deleted inside the
    // transaction, which is the only window where the two could diverge.
    User::updating(function (): void {
        throw new RuntimeException('write failed mid-update');
    });

    test()->withoutExceptionHandling();

    expect(fn () => editUser($alice, ['email' => 'alice.new@clsu.edu.ph']))
        ->toThrow(RuntimeException::class);

    expect($alice->fresh()->email)->toBe('alice@clsu.edu.ph')
        ->and(Password::broker('staff_invitations')->tokenExists($alice->fresh(), $token))->toBeTrue();
});

// --- a pending account cannot be activated by the admin ---------------------

test('an admin cannot flip a pending staff account to active', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    editUser($alice, ['account_status' => 'active'])->assertSessionHasErrors('account_status');

    $alice->refresh();

    expect($alice->account_status)->toBe('inactive')
        ->and($alice->email_verified_at)->toBeNull()
        // the account is untouched, so the invitation must still work
        ->and(Password::broker('staff_invitations')->tokenExists($alice, $token))->toBeTrue();
});

test('the block applies even once the invitation has expired', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($alice);

    test()->travelTo(now()->addDays(8));

    // An expired invitation is still not a licence to skip activation: the
    // password remains the unknown random placeholder.
    editUser($alice, ['account_status' => 'active'])->assertSessionHasErrors('account_status');

    expect($alice->fresh()->account_status)->toBe('inactive');
});

test('an activated staff account can still be deactivated and reactivated', function () {
    $alice = pendingNurse('alice@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($alice);

    auth()->logout();

    test()->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $alice->email,
        'password' => 'chosen-by-alice-1',
        'password_confirmation' => 'chosen-by-alice-1',
    ])->assertSessionHasNoErrors();

    // Once verified, an admin's only "deactivate" option is 'suspended' —
    // 'inactive' means "awaiting activation" and UserManagementController::update()
    // only ever accepts it as a target for a still-inactive user (see its
    // account_status rule), never as something to switch a verified account
    // back to. This test originally asserted 'inactive' here, from before
    // that rule was tightened; 'suspended' is the real current equivalent
    // of "deactivated".
    editUser($alice->fresh(), ['account_status' => 'suspended'])->assertSessionHasNoErrors();
    expect($alice->fresh()->account_status)->toBe('suspended');

    // Now verified, so the guard does not apply and the admin stays in control.
    editUser($alice->fresh(), ['account_status' => 'active'])->assertSessionHasNoErrors();
    expect($alice->fresh()->account_status)->toBe('active');
});

// Resolved: reading (a) was chosen. UserManagementController::update()'s
// account_status rule is now scoped with User::awaitsStaffActivation()
// instead of a bare `account_status === 'inactive'`, so the restriction
// applies only to invited nurses/physicians — whose password can only be set
// through their own invitation link — and no longer to patients, who set
// their own password at registration and were previously left permanently
// unactivatable. The role-scoped guard beneath that rule
// (in_array($user->role, self::INVITED_ROLES, true)) is reachable again,
// which is what keeps an unactivated nurse/physician from being flipped
// active by an admin. resources/views/admin/users/edit.blade.php gates its
// status field on the same method so the form and the rule cannot disagree.
test('patients and admins are unaffected by the activation guard', function () {
    $patient = User::factory()->unverified()->create([
        'role' => 'patient',
        'account_status' => 'inactive',
    ]);

    editUser($patient, ['account_status' => 'active'])->assertSessionHasNoErrors();

    expect($patient->fresh()->account_status)->toBe('active');
});

// --- creation flow is not disturbed by the revocation -----------------------

test('creating a staff account after an email change still issues a fresh invitation', function () {
    Notification::fake();

    $alice = pendingNurse('shared-address@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($alice);

    editUser($alice, ['email' => 'alice.new@clsu.edu.ph'])->assertSessionHasNoErrors();

    test()->actingAs(revocationAdmin())->post(route('admin.users.store'), [
        'first_name' => 'Carla',
        'last_name' => 'Diaz',
        'email' => 'shared-address@clsu.edu.ph',
        'role' => 'physician',
    ])->assertSessionHasNoErrors();

    $carla = User::where('email', 'shared-address@clsu.edu.ph')->firstOrFail();

    expect(tokenRowExists('shared-address@clsu.edu.ph'))->toBeTrue();

    Notification::assertSentTo($carla, StaffAccountInvitation::class);
});
