<?php

use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Regression cover for audit finding M-1.
 *
 * The invitation email is sent after the transaction commits, so a transport
 * failure cannot roll the account back. The admin must be told delivery failed
 * instead of being shown a 500, and the account plus its invitation must stay
 * intact so a future resend can use them.
 */

function mailFailureAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function createStaffAccount(array $overrides = []): TestResponse
{
    return test()->actingAs(mailFailureAdmin())->post(route('admin.users.store'), array_merge([
        'first_name' => 'Maria',
        'last_name' => 'Cruz',
        'email' => 'maria.cruz@clsu.edu.ph',
        'clsu_id' => '26-000103',
        'role' => 'nurse',
    ], $overrides));
}

/**
 * Make notification delivery throw the way a dead SMTP host would.
 *
 * Notifiable::notify() resolves the Dispatcher contract from the container, so
 * replacing that binding is enough to fail delivery at the point the controller
 * calls it.
 */
function breakMailTransport(): void
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

test('a mail transport failure does not produce a 500 and warns the admin', function () {
    breakMailTransport();

    $response = createStaffAccount();

    $response->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning')
        ->assertSessionMissing('status');

    expect(session('warning'))->toContain('could not be sent')
        ->and(session('warning'))->toContain('remains inactive');
});

test('the account and its invitation survive a mail transport failure', function () {
    breakMailTransport();

    createStaffAccount()->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    expect($user->account_status)->toBe('inactive')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->role)->toBe('nurse')
        // the invitation is preserved for a future resend
        ->and(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

test('the mail failure warning leaks no token, password or transport detail', function () {
    breakMailTransport();

    $response = createStaffAccount();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();
    $tokenHash = DB::table('staff_invitation_tokens')->where('email', $user->email)->value('token');

    $warning = session('warning');

    expect(str_contains($warning, $tokenHash))->toBeFalse()
        ->and(str_contains($warning, $user->password))->toBeFalse()
        ->and(str_contains($warning, 'smtp.example.test'))->toBeFalse()
        ->and(str_contains($warning, 'TransportException'))->toBeFalse();

    $response->assertDontSee($tokenHash, false);
});

test('the failure is logged without the exception message or the token', function () {
    breakMailTransport();

    $logged = [];

    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message;
    });

    createStaffAccount();

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('error')
        ->and($logged[0]->message)->toBe('Staff invitation email could not be sent.')
        ->and($logged[0]->context['user_id'])->toBe($user->user_id)
        ->and($logged[0]->context['exception'])->toBe(TransportException::class);

    // The SMTP host from the transport message must not reach the log.
    $serialised = json_encode([$logged[0]->message, $logged[0]->context]);

    expect($serialised)->not->toContain('smtp.example.test');
});

test('a successful send still reports success and is unchanged', function () {
    Notification::fake();

    $response = createStaffAccount();

    $response->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('status')
        ->assertSessionMissing('warning');

    expect(session('status'))->toContain('invitation email sent');

    $user = User::where('email', 'maria.cruz@clsu.edu.ph')->firstOrFail();

    Notification::assertSentTo($user, StaffAccountInvitation::class);

    expect($user->account_status)->toBe('inactive')
        ->and(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

test('patient creation is unaffected by the mail failure handling', function () {
    breakMailTransport();

    test()->actingAs(mailFailureAdmin())->post(route('admin.users.store'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan@clsu.edu.ph',
        'clsu_id' => '23-0101',
        'password' => 'patient-password-1',
        'password_confirmation' => 'patient-password-1',
        'role' => 'patient',
        'account_status' => 'active',
    ])->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('status')
        ->assertSessionMissing('warning');

    expect(User::where('email', 'juan@clsu.edu.ph')->exists())->toBeTrue();
});
