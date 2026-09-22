<?php

use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

function invitingAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function submitStaff(array $overrides = []): TestResponse
{
    return test()->actingAs(invitingAdmin())->post(route('admin.users.store'), array_merge([
        'first_name' => 'Maria',
        'last_name' => 'Cruz',
        'email' => 'maria.cruz@clsu.edu.ph',
        'role' => 'nurse',
    ], $overrides));
}

/**
 * All the text of the rendered mail message, greeting through the closing
 * lines, joined into one string for content assertions.
 */
function renderedInvitationText(StaffAccountInvitation $notification, User $user): string
{
    $mail = $notification->toMail($user);

    return collect([$mail->subject, $mail->greeting])
        ->merge($mail->introLines)
        ->merge([$mail->actionText])
        ->merge($mail->outroLines)
        ->filter()
        ->implode(' ');
}

// --- the notification must never be queued ----------------------------------

test('the staff invitation notification is not queueable', function () {
    // Queuing it would serialize the plaintext invitation token into the
    // 'database' queue's jobs.payload column, persisting in the clear the very
    // value staff_invitation_tokens deliberately stores only as a hash.
    // Checked by reflection so no token has to be constructed to assert it.
    expect(is_subclass_of(StaffAccountInvitation::class, ShouldQueue::class))->toBeFalse()
        ->and(class_uses_recursive(StaffAccountInvitation::class))->not->toContain(Queueable::class);
});

// --- notification is sent for staff, not for other roles --------------------

test('creating a nurse sends a staff invitation notification', function () {
    Notification::fake();

    submitStaff(['role' => 'nurse'])->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class);
});

test('creating a physician sends a staff invitation notification', function () {
    Notification::fake();

    submitStaff(['role' => 'physician'])->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class);
});

test('creating a patient does not send a staff invitation notification', function () {
    Notification::fake();

    test()->actingAs(invitingAdmin())->post(route('admin.users.store'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@clsu.edu.ph',
        'password' => 'patient-password-1',
        'password_confirmation' => 'patient-password-1',
        'role' => 'patient',
        'account_status' => 'active',
    ])->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('creating an admin sends a staff invitation notification', function () {
    Notification::fake();

    submitStaff(['role' => 'admin', 'email' => 'ada@clsu.edu.ph'])->assertSessionHasNoErrors();

    $user = User::where('email', 'ada@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class);
});

// --- notification content ----------------------------------------------------

test('the notification is addressed to the correct recipient and states their role', function () {
    Notification::fake();

    submitStaff(['role' => 'physician'])->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user) {
        $text = renderedInvitationText($notification, $user);

        return str_contains($text, 'Maria') && str_contains($text, 'Physician');
    });
});

test('the notification contains an activation url with the correct token and email', function () {
    Notification::fake();

    submitStaff()->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        // The route is staff/activate/{token}, so the token is a path segment
        // and the email rides along as a query parameter.
        expect(parse_url($url, PHP_URL_PATH))->toContain('/staff/activate/')
            ->and($url)->toContain('email='.urlencode($user->email));

        preg_match('#/staff/activate/([^/?]+)#', $url, $matches);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        // The token in the link must be the real, still-valid plaintext token
        // for this user, not a placeholder or a token for someone else.
        expect($query['email'])->toBe($user->email)
            ->and(Password::broker('staff_invitations')->tokenExists($user, $matches[1] ?? ''))->toBeTrue();

        return true;
    });
});

test('the email does not expose a temporary password', function () {
    Notification::fake();

    submitStaff()->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user) {
        // Talking about "your password" is fine ("create your password"); what
        // must never appear is the actual stored password hash.
        $text = renderedInvitationText($notification, $user);

        expect($text)->not->toContain($user->password);

        return true;
    });
});

test('the email states the seven day expiration', function () {
    Notification::fake();

    submitStaff()->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user) {
        return str_contains(renderedInvitationText($notification, $user), '7 days');
    });
});

// --- transactional safety ----------------------------------------------------

test('no notification is sent if the staff creation transaction fails', function () {
    Notification::fake();

    Schema::drop('staff_invitation_tokens');

    test()->withoutExceptionHandling();

    expect(fn () => submitStaff())->toThrow(QueryException::class);

    Notification::assertNothingSent();
    test()->assertDatabaseMissing('users', ['email' => 'maria.cruz@clsu.edu.ph']);
});

// --- the plaintext token is never persisted ----------------------------------

test('the plaintext invitation token is not stored anywhere in the database', function () {
    Notification::fake();

    submitStaff()->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user) {
        $reflection = new ReflectionClass($notification);
        $property = $reflection->getProperty('token');
        $property->setAccessible(true);
        $plaintext = $property->getValue($notification);

        $record = DB::table('staff_invitation_tokens')->where('email', $user->email)->first();
        $storedPassword = DB::table('users')->where('user_id', $user->user_id)->value('password');

        expect($record->token)->not->toBe($plaintext)
            ->and($storedPassword)->not->toBe($plaintext)
            ->and(str_contains($storedPassword, $plaintext))->toBeFalse();

        return true;
    });
});

// --- the resulting invitation still activates ---------------------------------

test('the emailed invitation link activates the account through the existing flow', function () {
    Notification::fake();

    submitStaff(['role' => 'nurse'])->assertSessionHasNoErrors();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    $capturedToken = null;

    Notification::assertSentTo($user, StaffAccountInvitation::class, function (StaffAccountInvitation $notification) use ($user, &$capturedToken) {
        $url = $notification->toMail($user)->actionUrl;
        preg_match('#/staff/activate/([^/?]+)#', $url, $matches);
        $capturedToken = $matches[1];

        return true;
    });

    auth()->logout();

    test()->post(route('staff.activate.store'), [
        'token' => $capturedToken,
        'email' => $user->email,
        'password' => 'chosen-by-the-nurse-1',
        'password_confirmation' => 'chosen-by-the-nurse-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $user->refresh();

    expect($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull();
});

// --- existing password reset notification is unaffected ---------------------

test('the existing password reset email flow is unaffected', function () {
    Notification::fake();

    $user = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);

    test()->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertNotSentTo($user, StaffAccountInvitation::class);
});
