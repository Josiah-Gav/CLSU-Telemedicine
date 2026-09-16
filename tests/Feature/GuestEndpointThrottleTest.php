<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
| Three unauthenticated POST endpoints carried no rate limit at all.
|
| forgot-password is the one that matters most: config/auth.php already throttles
| the broker 60s per *email address*, which stops a single mailbox being flooded,
| but nothing stopped a script walking a list of addresses and burning the SMTP
| quota that first login for every patient and every staff invitation depends on.
|
| The limiter keys guest routes by IP, so these tests clear it between cases —
| otherwise one test's requests would exhaust the bucket for the next.
*/

beforeEach(function () {
    RateLimiter::clear('');
    $this->app['cache']->flush();
});

it('throttles password reset requests', function () {
    // Six are allowed, the seventh is refused.
    foreach (range(1, 6) as $i) {
        $this->post(route('password.email'), ['email' => "probe{$i}@clsu.edu.ph"])
            ->assertStatus(302);
    }

    $this->post(route('password.email'), ['email' => 'probe7@clsu.edu.ph'])
        ->assertStatus(429);
});

it('throttles reset-password submissions', function () {
    foreach (range(1, 6) as $i) {
        $this->post(route('password.store'), [
            'token' => 'invalid-token',
            'email' => "probe{$i}@clsu.edu.ph",
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
    }

    $this->post(route('password.store'), [
        'token' => 'invalid-token',
        'email' => 'probe7@clsu.edu.ph',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(429);
});

it('throttles registration, but leaves room for a shared campus IP', function () {
    // Deliberately 10 rather than 6: CLSU traffic is NATed, so a demo or
    // orientation session puts many legitimate registrations behind one
    // apparent IP within the same minute.
    //
    // The attempts below use a non-CLSU address so each one fails validation.
    // That is both the realistic abuse shape (a script probing the endpoint)
    // and the only way to measure the limiter here: a *successful* registration
    // signs the user in, after which the route's own guest middleware redirects
    // every later POST before the throttle is ever reached. Middleware runs
    // ahead of validation, so a rejected attempt still consumes its slot.
    $attempt = fn (int $i) => $this->post(route('register'), [
        'first_name' => 'Probe',
        'last_name' => "User{$i}",
        'clsu_id' => "20{$i}00000",
        'email' => "probe{$i}@example.com",
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    foreach (range(1, 10) as $i) {
        $attempt($i)->assertStatus(302);   // rejected by validation, not the limiter
    }

    $attempt(11)->assertStatus(429);       // now the limiter

    expect(User::where('email', 'like', 'probe%@example.com')->count())->toBe(0);
});

it('lets a normal single registration through untouched', function () {
    $this->post(route('register'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'clsu_id' => '202012345',
        'email' => 'juan.delacruz@clsu.edu.ph',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect(User::where('email', 'juan.delacruz@clsu.edu.ph')->exists())->toBeTrue();
});

it('lets a normal password reset request through untouched', function () {
    $user = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
        'account_status' => 'active',
    ]);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors();
});

it('leaves the existing login limiter alone', function () {
    // Login is throttled inside LoginRequest (5 attempts per email+IP), not by
    // route middleware. That limiter is deliberately untouched by this change;
    // this test pins that it still fires.
    $user = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
        'account_status' => 'active',
    ]);

    foreach (range(1, 5) as $i) {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->get('email')[0])->toContain('seconds');
});
