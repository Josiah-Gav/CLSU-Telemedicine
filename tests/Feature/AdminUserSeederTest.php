<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sets ADMIN_* the same way phpunit.xml's own <env> block does — putenv() plus
 * $_ENV/$_SERVER — so env() resolves it regardless of which adapter Laravel's
 * Env repository picked, then always clears the same three afterward.
 */
function setAdminEnv(?string $email, ?string $password, ?string $firstName = null, ?string $lastName = null): void
{
    $vars = [
        'ADMIN_EMAIL' => $email,
        'ADMIN_PASSWORD' => $password,
        'ADMIN_FIRST_NAME' => $firstName,
        'ADMIN_LAST_NAME' => $lastName,
    ];

    foreach ($vars as $key => $value) {
        if ($value === null) {
            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function clearAdminEnv(): void
{
    foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD', 'ADMIN_FIRST_NAME', 'ADMIN_LAST_NAME'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

afterEach(function () {
    clearAdminEnv();
});

it('creates the admin from ADMIN_EMAIL/ADMIN_PASSWORD with a working hashed password', function () {
    setAdminEnv('admin@clsu.edu.ph', 'a-strong-password', 'First', 'Last');

    (new AdminUserSeeder)->run();

    $admin = User::where('email', 'admin@clsu.edu.ph')->first();

    expect($admin)->not->toBeNull();
    expect($admin->role)->toBe('admin');
    expect($admin->account_status)->toBe('active');
    expect($admin->user_type)->toBe('staff');
    expect($admin->department)->toBe('Infirmary');
    expect($admin->first_name)->toBe('First');
    expect($admin->last_name)->toBe('Last');
    expect(Hash::check('a-strong-password', $admin->password))->toBeTrue();
});

it('lets User::booted() verify the admin email, without the seeder setting it itself', function () {
    setAdminEnv('admin@clsu.edu.ph', 'a-strong-password');

    (new AdminUserSeeder)->run();

    expect(User::where('email', 'admin@clsu.edu.ph')->first()->email_verified_at)->not->toBeNull();
});

it('defaults first/last name to Admin/User when not supplied', function () {
    setAdminEnv('admin@clsu.edu.ph', 'a-strong-password');

    (new AdminUserSeeder)->run();

    $admin = User::where('email', 'admin@clsu.edu.ph')->first();

    expect($admin->first_name)->toBe('Admin');
    expect($admin->last_name)->toBe('User');
});

it('does not create a duplicate admin when run twice', function () {
    setAdminEnv('admin@clsu.edu.ph', 'a-strong-password');

    (new AdminUserSeeder)->run();
    (new AdminUserSeeder)->run();

    expect(User::where('email', 'admin@clsu.edu.ph')->count())->toBe(1);
});

it('does not overwrite an existing admin password on a second run', function () {
    setAdminEnv('admin@clsu.edu.ph', 'first-password');
    (new AdminUserSeeder)->run();

    setAdminEnv('admin@clsu.edu.ph', 'second-password');
    (new AdminUserSeeder)->run();

    $admin = User::where('email', 'admin@clsu.edu.ph')->first();

    expect(Hash::check('first-password', $admin->password))->toBeTrue();
    expect(Hash::check('second-password', $admin->password))->toBeFalse();
});

it('does not change an existing user role or account status on a second run', function () {
    $existing = User::factory()->create([
        'email' => 'admin@clsu.edu.ph',
        'role' => 'patient',
        'account_status' => 'suspended',
    ]);

    setAdminEnv('admin@clsu.edu.ph', 'a-strong-password');
    (new AdminUserSeeder)->run();

    $existing->refresh();

    expect($existing->role)->toBe('patient');
    expect($existing->account_status)->toBe('suspended');
});

it('is a no-op when ADMIN_EMAIL is missing', function () {
    setAdminEnv(null, 'a-strong-password');

    (new AdminUserSeeder)->run();

    expect(User::count())->toBe(0);
});

it('is a no-op when ADMIN_EMAIL is not a valid email address', function () {
    setAdminEnv('not-an-email', 'a-strong-password');

    (new AdminUserSeeder)->run();

    expect(User::count())->toBe(0);
});

it('is a no-op when ADMIN_PASSWORD is missing', function () {
    setAdminEnv('admin@clsu.edu.ph', null);

    (new AdminUserSeeder)->run();

    expect(User::count())->toBe(0);
});
