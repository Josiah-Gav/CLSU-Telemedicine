<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One patient/nurse/physician account per role for the QA team to log in
 * with directly, skipping registration and the staff invitation flow.
 * Invoked directly with `db:seed --class=QaTestUsersSeeder`, never through
 * the default `db:seed`, since these are not accounts a production
 * database should ever contain.
 *
 * Idempotent per email: an account that already exists is left untouched.
 */
class QaTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'first_name' => 'QA',
                'last_name' => 'Patient',
                'email' => 'patient.test1@clsu.edu.ph',
                'password' => 'patient1password',
                'role' => 'patient',
                'user_type' => 'student',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Patient',
                'email' => 'patient.test2@clsu.edu.ph',
                'password' => 'patient2password',
                'role' => 'patient',
                'user_type' => 'student',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Nurse',
                'email' => 'nurse.test1@clsu.edu.ph',
                'password' => 'nurse1password',
                'role' => 'nurse',
                'user_type' => 'staff',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Nurse',
                'email' => 'nurse.test2@clsu.edu.ph',
                'password' => 'nurse2password',
                'role' => 'nurse',
                'user_type' => 'staff',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Physician',
                'email' => 'physician.test1@clsu.edu.ph',
                'password' => 'physician1password',
                'role' => 'physician',
                'user_type' => 'staff',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Physician',
                'email' => 'physician.test2@clsu.edu.ph',
                'password' => 'physician2password',
                'role' => 'physician',
                'user_type' => 'staff',
            ],
        ];

        foreach ($accounts as $account) {
            if (User::where('email', $account['email'])->exists()) {
                $this->command?->info("QA account skipped: {$account['email']} already exists.");

                continue;
            }

            User::factory()->create([
                ...$account,
                'account_status' => 'active',
            ]);

            $this->command?->info("QA account created: {$account['email']} / {$account['password']}");
        }
    }
}
