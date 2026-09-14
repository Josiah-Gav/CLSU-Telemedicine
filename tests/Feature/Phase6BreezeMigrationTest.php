<?php

/**
 * Phase 6: the seven Breeze authentication pages migrated onto the
 * project's <x-button-*> components. Locks in component usage, the
 * explicit type="submit" (x-button-primary defaults to type="button",
 * unlike Breeze's x-primary-button which defaults to "submit" via
 * attributes->merge — losing that would silently break every one of
 * these forms), and that no Breeze button component is referenced
 * anywhere in the codebase any more.
 */
it('migrates every Breeze x-primary-button auth page onto x-button-primary with an explicit submit type', function () {
    $pages = [
        'activate-staff-account' => 'Activate Account',
        'confirm-password' => 'Confirm',
        'forgot-password' => 'Email Password Reset Link',
        'reset-password' => 'Reset Password',
        'login' => 'Log in',
        'register' => 'Register',
        'verify-email' => 'Resend Verification Email',
    ];

    foreach ($pages as $page => $label) {
        $source = file_get_contents(resource_path("views/auth/{$page}.blade.php"));

        expect($source)->toContain('<x-button-primary')
            ->and($source)->toContain('type="submit"')
            ->and($source)->toContain($label);
    }
});

it('leaves the verify-email Log Out control as plain text, matching every other Log Out in the app', function () {
    $source = file_get_contents(resource_path('views/auth/verify-email.blade.php'));

    expect($source)->toContain('<button type="submit" class="underline text-sm text-gray-600')
        ->and($source)->toContain("route('logout')");
});

it('leaves the login/register page nav links (their own emerald color scheme) unmigrated', function () {
    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));

    expect($login)->toContain('text-emerald-700')
        ->and($login)->toContain("route('password.request')")
        ->and($register)->toContain('text-emerald-700')
        ->and($register)->toContain("route('login')");
});

it('has zero remaining references to any Breeze button component anywhere in the app', function () {
    $roots = ['resources', 'tests', 'app'];
    $needles = ['x-primary-button', 'x-secondary-button', 'x-danger-button'];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getFilename() === basename(__FILE__)) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            foreach ($needles as $needle) {
                expect($contents)->not->toContain($needle, "Found stray {$needle} in {$file->getPathname()}");
            }
        }
    }
})->skip(fn () => ! is_dir(base_path('resources')), 'resources directory missing');
