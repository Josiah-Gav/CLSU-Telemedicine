<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
| Account status used to be consulted only by LoginRequest::authenticate(),
| which answers "may this person sign in?". Suspending an account therefore did
| nothing to a session that was already open — a suspended nurse or physician
| kept full access until the session expired on its own, up to SESSION_LIFETIME
| minutes later. EnsureAccountIsActive closes that window on the next request.
*/

function activeUser(string $role = 'patient'): User
{
    return User::factory()->create([
        'role' => $role,
        'user_type' => $role === 'patient' ? 'student' : 'staff',
        'account_status' => 'active',
    ]);
}

// profile.edit rather than /dashboard: DashboardController::index branches on
// role and redirects a nurse to nurse.dashboard, so /dashboard is not a uniform
// "any authenticated page" for a role-parameterised test. The profile page
// renders identically for all four roles and sits in the same web group, so it
// exercises this middleware without dragging in dashboard routing.
it('lets an active user continue normally', function (string $role) {
    $user = activeUser($role);

    $this->actingAs($user)->get(route('profile.edit'))->assertSuccessful();
    expect(Auth::check())->toBeTrue();
})->with(['patient', 'nurse', 'physician', 'admin']);

it('ends the session of a user suspended mid-session, for every role', function (string $role) {
    $user = activeUser($role);

    // Session is already open and working.
    $this->actingAs($user)->get(route('profile.edit'))->assertSuccessful();

    // An admin suspends them while they are still browsing.
    $user->forceFill(['account_status' => 'suspended'])->save();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect(route('login'));

    // Not merely redirected — actually signed out.
    expect(Auth::check())->toBeFalse();
})->with(['patient', 'nurse', 'physician', 'admin']);

it('also ends the session of an account set back to inactive', function () {
    $user = activeUser('nurse');
    $user->forceFill(['account_status' => 'inactive'])->save();

    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
});

it('answers a polled JSON endpoint with 401 rather than an HTML redirect', function () {
    // The messaging and dashboard pages poll several JSON endpoints every few
    // seconds; handing those a login page would have the client parse markup.
    $user = activeUser('patient');
    $user->forceFill(['account_status' => 'suspended'])->save();

    $this->actingAs($user)
        ->getJson(route('consultations.messaging.unread_counts'))
        ->assertUnauthorized();
});

it('explains why the session ended instead of failing silently', function () {
    $user = activeUser('physician');
    $user->forceFill(['account_status' => 'suspended'])->save();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
});

it('does not interfere with guest routes', function () {
    // The check is skipped entirely when nobody is authenticated, which is also
    // what makes a redirect loop impossible: the login page is a guest request.
    $this->get(route('login'))->assertSuccessful();
    $this->get(route('register'))->assertSuccessful();
    $this->get(route('password.request'))->assertSuccessful();
});

it('does not redirect-loop on the login page after being kicked out', function () {
    $user = activeUser('patient');
    $user->forceFill(['account_status' => 'suspended'])->save();

    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));

    // Following the redirect must render the page, not bounce again.
    $this->get(route('login'))->assertSuccessful();
});

it('does not refresh presence for a suspended user', function () {
    // EnsureAccountIsActive is ordered ahead of TrackUserPresence precisely so a
    // suspended physician cannot keep appearing online and holding intake open.
    $user = activeUser('physician');
    $user->forceFill(['account_status' => 'suspended', 'online_status' => 'offline'])->save();

    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));

    expect($user->fresh()->online_status)->toBe('offline');
});

it('still refuses a suspended account at the login form itself', function () {
    // The pre-existing LoginRequest check is unchanged; this middleware is the
    // second half of the same rule, not a replacement for it.
    $user = activeUser('nurse');
    $user->forceFill(['account_status' => 'suspended'])->save();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse();
});
