<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

// Both tests below were still the unmodified stock Breeze scaffolding:
// they submitted 'name'/'email', but this app's ProfileUpdateRequest
// requires 'first_name'/'last_name' and does not accept 'email' as a
// profile-form field at all — so both always failed validation with
// "The first name field is required. The last name field is required."
// regardless of any recent frontend work. Rewritten to match the app's
// real fields and its real behavior: since email can never be submitted
// through this form, ProfileController::update()'s isDirty('email') check
// is never true here, so a profile update never touches email_verified_at
// either way.
test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test', $user->first_name);
    $this->assertSame('User', $user->last_name);
    $this->assertNotNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->unverified()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNull($user->refresh()->email_verified_at);
});

test('account deletion route no longer exists', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    // '/profile' is still a valid path (GET/PATCH), so a removed DELETE route
    // surfaces as "method not allowed" rather than "not found".
    $response->assertMethodNotAllowed();
    $this->assertNotNull($user->fresh());
});
