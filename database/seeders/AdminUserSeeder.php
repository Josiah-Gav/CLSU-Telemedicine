<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deployment-time provisioning for the single first admin account.
 *
 * Deliberately its own seeder, invoked directly with
 * `db:seed --class=AdminUserSeeder`, never through the default `db:seed` —
 * DatabaseSeeder creates a test@example.com account that must never exist in
 * production. Credentials come from ADMIN_EMAIL/ADMIN_PASSWORD so the
 * password is never typed into a script or committed; see
 * docs/HOSTINGER_QA.md for the deployment lifecycle.
 *
 * Idempotent by design: if an account with the configured email already
 * exists, this makes no changes at all (not even to unrelated fields) rather
 * than risk overwriting an admin who has since changed their own password or
 * been suspended. email_verified_at is intentionally left unset here —
 * User::booted() already stamps it for role => admin.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->command?->warn('Admin provisioning skipped: ADMIN_EMAIL is missing or not a valid email address.');

            return;
        }

        if (! is_string($password) || $password === '') {
            $this->command?->warn('Admin provisioning skipped: ADMIN_PASSWORD is missing.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin provisioning skipped: an account for {$email} already exists. No changes made.");

            return;
        }

        User::create([
            'first_name' => env('ADMIN_FIRST_NAME', 'Admin'),
            'last_name' => env('ADMIN_LAST_NAME', 'User'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'account_status' => 'active',
            'user_type' => 'staff',
            'department' => 'Infirmary',
        ]);

        $this->command?->info("Admin account created for {$email}.");
    }
}
