<?php

use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Phase 7: admin-only invitation recovery.
 *
 * No raw token is ever passed to expect(); token validity is asserted through
 * the broker or Hash::check, so a failing assertion cannot print a live
 * invitation token into the output.
 */

function resendAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function resendTarget(array $overrides = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'first_name' => 'Maria',
        'last_name' => 'Cruz',
        'role' => 'nurse',
        'account_status' => 'inactive',
        'password' => Hash::make('placeholder-never-shared'),
    ], $overrides));
}

function resend(User $target, ?User $actor = null, array $payload = []): TestResponse
{
    $request = test();

    if ($actor !== null) {
        $request = $request->actingAs($actor);
    }

    return $request->post(route('admin.users.resend_invitation', $target), $payload);
}

/**
 * Seed an invitation that already existed before the test acts.
 *
 * The clock is advanced past the per-recipient throttle window, because these
 * call sites stand for an invitation issued earlier — not one created in the
 * same instant the admin clicks resend, which the throttle rightly refuses.
 */
function issuedToken(User $user): string
{
    $token = Password::broker('staff_invitations')->createToken($user);

    test()->travelTo(now()->addSeconds(90));

    return $token;
}

function tokenIsValid(User $user, string $token): bool
{
    return Password::broker('staff_invitations')->tokenExists($user->fresh(), $token);
}

/**
 * Capture the token from the activation link of the sent notification.
 */
function capturedResendToken(User $user): string
{
    $captured = null;

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $n) use ($user, &$captured) {
        preg_match('#/staff/activate/([^/?]+)#', $n->toMail($user)->actionUrl, $matches);
        $captured = $matches[1] ?? '';

        return true;
    });

    return $captured;
}

function breakResendMail(): void
{
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification)
        {
            throw new TransportException('Connection could not be established with host smtp.example.test');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            throw new TransportException('Connection could not be established with host smtp.example.test');
        }
    });
}

// --- 1-4. the recoverable states -------------------------------------------

test('an admin can resend for an inactive nurse, physician, or admin holding a valid invitation', function (string $role) {
    Notification::fake();

    $target = resendTarget(['role' => $role]);
    issuedToken($target);

    resend($target, resendAdmin())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    expect(session('status'))->toContain('Invitation email sent to '.$target->email);

    Notification::assertSentTo($target, StaffAccountInvitation::class);
})->with(['nurse', 'physician', 'admin']);

test('an admin can resend once the invitation has expired', function () {
    Notification::fake();

    $target = resendTarget();
    $old = issuedToken($target);

    $this->travelTo(now()->addDays(8));

    expect(tokenIsValid($target, $old))->toBeFalse();

    resend($target, resendAdmin())->assertSessionHasNoErrors();

    Notification::assertSentTo($target, StaffAccountInvitation::class);

    expect(tokenIsValid($target, capturedResendToken($target)))->toBeTrue();
});

test('an admin can send when no invitation exists at all', function () {
    Notification::fake();

    $target = resendTarget();

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->exists())->toBeFalse();

    resend($target, resendAdmin())->assertSessionHasNoErrors();

    Notification::assertSentTo($target, StaffAccountInvitation::class);
    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->exists())->toBeTrue();
});

// --- 5-7, 26. token rotation ------------------------------------------------

test('the previous invitation dies the moment a new one is issued', function () {
    Notification::fake();

    $target = resendTarget();
    $old = issuedToken($target);

    expect(tokenIsValid($target, $old))->toBeTrue();

    resend($target, resendAdmin())->assertSessionHasNoErrors();

    $new = capturedResendToken($target);

    expect(tokenIsValid($target, $old))->toBeFalse()
        ->and(tokenIsValid($target, $new))->toBeTrue()
        // exactly one invitation exists for this address
        ->and(DB::table('staff_invitation_tokens')->where('email', $target->email)->count())->toBe(1);
});

test('the new invitation activates the account and the old one cannot', function () {
    Notification::fake();

    $target = resendTarget();
    $old = issuedToken($target);

    resend($target, resendAdmin())->assertSessionHasNoErrors();
    $new = capturedResendToken($target);

    auth()->logout();

    // The superseded link is refused.
    test()->post(route('staff.activate.store'), [
        'token' => $old,
        'email' => $target->email,
        'password' => 'from-the-old-link-1',
        'password_confirmation' => 'from-the-old-link-1',
    ])->assertSessionHasErrors('email');

    expect($target->fresh()->account_status)->toBe('inactive');

    // The current link works.
    test()->post(route('staff.activate.store'), [
        'token' => $new,
        'email' => $target->email,
        'password' => 'from-the-new-link-1',
        'password_confirmation' => 'from-the-new-link-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $target->refresh();

    expect($target->account_status)->toBe('active')
        ->and($target->email_verified_at)->not->toBeNull()
        ->and(Hash::check('from-the-new-link-1', $target->password))->toBeTrue()
        ->and(Hash::check('from-the-old-link-1', $target->password))->toBeFalse();
});

test('two resends a minute apart leave only the newest invitation valid', function () {
    Notification::fake();

    $target = resendTarget();
    $admin = resendAdmin();

    resend($target, $admin)->assertSessionHasNoErrors();
    $first = capturedResendToken($target);

    $this->travelTo(now()->addSeconds(90));

    resend($target, $admin)->assertSessionHasNoErrors();

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->count())->toBe(1)
        ->and(tokenIsValid($target, $first))->toBeFalse();
});

// --- 8-11. ineligible targets ------------------------------------------------

test('resend is refused for accounts that are not awaiting activation', function (array $attributes) {
    Notification::fake();

    $target = resendTarget($attributes);

    resend($target, resendAdmin())
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning');

    Notification::assertNothingSent();

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->exists())->toBeFalse();
})->with([
    'active staff' => [['account_status' => 'active']],
    'suspended staff' => [['account_status' => 'suspended']],
    'patient' => [['role' => 'patient']],
]);

test('resend for a nonexistent user is a 404', function () {
    Notification::fake();

    test()->actingAs(resendAdmin())
        ->post('/admin/users/999999/resend-invitation')
        ->assertNotFound();

    Notification::assertNothingSent();
});

// --- 12-16, 29. authorization ------------------------------------------------

test('a guest cannot reach the resend endpoint', function () {
    Notification::fake();

    $target = resendTarget();
    issuedToken($target);

    resend($target)->assertRedirect(route('login'));

    Notification::assertNothingSent();
});

test('non-admin roles cannot resend', function (string $role) {
    Notification::fake();

    $target = resendTarget();
    issuedToken($target);
    $actor = User::factory()->create(['role' => $role, 'account_status' => 'active']);

    resend($target, $actor)->assertForbidden();

    Notification::assertNothingSent();
})->with(['patient', 'nurse', 'physician']);

test('a submitted role or status cannot influence authorization or eligibility', function () {
    Notification::fake();

    // A nurse claiming to be an admin is still refused.
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $target = resendTarget();

    resend($target, $nurse, ['role' => 'admin', 'account_status' => 'inactive'])->assertForbidden();
    Notification::assertNothingSent();

    // An admin cannot make an ineligible account eligible by saying so.
    $active = resendTarget(['account_status' => 'active', 'email' => 'already.active@clsu.edu.ph']);

    resend($active, resendAdmin(), ['account_status' => 'inactive', 'role' => 'nurse'])
        ->assertSessionHas('warning');

    Notification::assertNothingSent();
});

test('a submitted user id or email cannot retarget the invitation', function () {
    Notification::fake();

    $target = resendTarget();
    $other = resendTarget(['email' => 'other.nurse@clsu.edu.ph']);

    // The route binding decides the recipient; body fields naming someone else
    // must be ignored entirely.
    resend($target, resendAdmin(), [
        'user_id' => $other->user_id,
        'email' => $other->email,
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo($target, StaffAccountInvitation::class);
    Notification::assertNotSentTo($other, StaffAccountInvitation::class);

    expect(DB::table('staff_invitation_tokens')->where('email', $other->email)->exists())->toBeFalse();
});

// --- 17-18. exactly one notification, carrying the new token ------------------

test('a successful resend sends exactly one notification carrying the new token', function () {
    Notification::fake();

    $target = resendTarget();

    resend($target, resendAdmin())->assertSessionHasNoErrors();

    Notification::assertSentToTimes($target, StaffAccountInvitation::class, 1);

    expect(tokenIsValid($target, capturedResendToken($target)))->toBeTrue();
});

// --- 19-21. the plaintext token stays out of storage -------------------------

test('the resent token is stored only as a hash and never leaks', function () {
    Notification::fake();

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message;
    });

    $target = resendTarget();
    $response = resend($target, resendAdmin());
    $plaintext = capturedResendToken($target);

    $stored = DB::table('staff_invitation_tokens')->where('email', $target->email)->value('token');

    expect($stored === $plaintext)->toBeFalse()
        ->and(Hash::check($plaintext, $stored))->toBeTrue()
        // not in the users table
        ->and(str_contains(DB::table('users')->where('user_id', $target->user_id)->value('password'), $plaintext))->toBeFalse()
        // not queued: the notification is synchronous by design
        ->and(DB::table('jobs')->count())->toBe(0)
        // not flashed, not in the session, not in the response
        ->and(str_contains(json_encode(session()->all()), $plaintext))->toBeFalse()
        ->and(array_key_exists('token', session('_old_input', [])))->toBeFalse()
        ->and(str_contains($response->getContent(), $plaintext))->toBeFalse()
        // not logged
        ->and(str_contains(json_encode(array_map(fn ($m) => [$m->message, $m->context], $logged)), $plaintext))->toBeFalse();
});

// --- 22-25. failure handling --------------------------------------------------

test('a mail failure redirects with a warning and leaves the new invitation valid', function () {
    $target = resendTarget();

    breakResendMail();

    $response = resend($target, resendAdmin());

    $response->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning')
        ->assertSessionMissing('status');

    expect(session('warning'))->toContain('could not be sent')
        ->and(session('warning'))->not->toContain('smtp.example.test')
        ->and($target->fresh()->account_status)->toBe('inactive')
        ->and($target->fresh()->email_verified_at)->toBeNull()
        // the invitation survives so the admin can retry delivery
        ->and(DB::table('staff_invitation_tokens')->where('email', $target->email)->exists())->toBeTrue();
});

test('a mail failure on resend logs only safe diagnostics', function () {
    $target = resendTarget();

    breakResendMail();

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message;
    });

    resend($target, resendAdmin());

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->message)->toBe('Staff invitation email could not be resent.')
        ->and($logged[0]->context['user_id'])->toBe($target->user_id)
        ->and($logged[0]->context['exception'])->toBe(TransportException::class)
        ->and(json_encode($logged[0]->context))->not->toContain('smtp.example.test');
});

test('a token creation failure sends nothing and leaves no partial state', function () {
    Notification::fake();

    $target = resendTarget();
    $admin = resendAdmin();

    Schema::drop('staff_invitation_tokens');

    test()->withoutExceptionHandling();

    expect(fn () => resend($target, $admin))->toThrow(QueryException::class);

    Notification::assertNothingSent();

    expect($target->fresh()->account_status)->toBe('inactive');
});

// --- 26-27. double submit and concurrency ------------------------------------

test('an immediate second resend is refused instead of issuing a second invitation', function () {
    Notification::fake();

    $target = resendTarget();
    $admin = resendAdmin();

    resend($target, $admin)->assertSessionHas('status');
    $first = capturedResendToken($target);

    // The double-click.
    resend($target, $admin)
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning');

    expect(session('warning'))->toContain('moments ago');

    // Exactly one email, and the link already delivered still works.
    Notification::assertSentToTimes($target, StaffAccountInvitation::class, 1);

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->count())->toBe(1)
        ->and(tokenIsValid($target, $first))->toBeTrue();
});

test('serialized resends never leave two valid invitations', function () {
    Notification::fake();

    $target = resendTarget();
    $admin = resendAdmin();
    $tokens = [];

    // Stands in for two admins acting in sequence once the row lock has
    // serialized them. See the report: SQLite compiles lockForUpdate() away,
    // so this proves the invariant, not mutual exclusion.
    foreach (range(1, 3) as $i) {
        resend($target, $admin)->assertSessionHasNoErrors();
        $tokens[] = capturedResendToken($target);

        Notification::fake();
        $this->travelTo(now()->addSeconds(90));
    }

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->count())->toBe(1);

    // Only the most recently issued token survives.
    foreach (array_slice($tokens, 0, -1) as $superseded) {
        expect(tokenIsValid($target, $superseded))->toBeFalse();
    }

    expect(tokenIsValid($target, end($tokens)))->toBeTrue();
});

// --- 28. rate limiting --------------------------------------------------------

test('the resend endpoint is rate limited', function () {
    Notification::fake();

    $admin = resendAdmin();

    // Distinct targets, so the per-recipient throttle never fires and only the
    // route limiter can stop these.
    foreach (range(1, 30) as $i) {
        $target = resendTarget(['email' => "nurse{$i}@clsu.edu.ph"]);
        resend($target, $admin)->assertRedirect(route('admin.users.index'));
    }

    $extra = resendTarget(['email' => 'nurse31@clsu.edu.ph']);

    resend($extra, $admin)->assertStatus(429);
});

// --- 17 (broker isolation) ----------------------------------------------------

test('a resent invitation token is still rejected by the password reset flow', function () {
    Notification::fake();

    $target = resendTarget();

    resend($target, resendAdmin())->assertSessionHasNoErrors();
    $token = capturedResendToken($target);

    auth()->logout();

    test()->post(route('password.store'), [
        'token' => $token,
        'email' => $target->email,
        'password' => 'should-not-work-1',
        'password_confirmation' => 'should-not-work-1',
    ])->assertSessionHasErrors('email');

    expect($target->fresh()->account_status)->toBe('inactive');
});

// --- listing UI ----------------------------------------------------------------

test('the listing shows invitation state and offers resend only where it applies', function () {
    $admin = resendAdmin();

    $pending = resendTarget(['email' => 'pending@clsu.edu.ph']);
    issuedToken($pending);

    $neverSent = resendTarget(['email' => 'never.sent@clsu.edu.ph']);
    $activeNurse = resendTarget(['email' => 'active.nurse@clsu.edu.ph', 'account_status' => 'active']);

    $response = test()->actingAs($admin)->get(route('admin.users.index'));

    $response->assertOk()
        ->assertSee('Invitation')
        ->assertSee('Not sent')
        ->assertSee(route('admin.users.resend_invitation', $pending), false)
        ->assertSee(route('admin.users.resend_invitation', $neverSent), false)
        // no resend control for an account the activation flow would refuse
        ->assertDontSee(route('admin.users.resend_invitation', $activeNurse), false);
});

test('the listing marks an expired invitation as expired', function () {
    $admin = resendAdmin();
    $target = resendTarget(['email' => 'expired@clsu.edu.ph']);
    issuedToken($target);

    $this->travelTo(now()->addDays(8));

    test()->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Expired');
});
