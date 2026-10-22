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
 * Idempotent per email: an account that already exists is left untouched,
 * except that a NULL clsu_id is backfilled. The patient CLSU IDs must match
 * the CHIS simulator's seed data exactly.
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
                'clsu_id' => '23-0001',
            ],
            [
                'first_name' => 'QA',
                'last_name' => 'Patient',
                'email' => 'patient.test2@clsu.edu.ph',
                'password' => 'patient2password',
                'role' => 'patient',
                'user_type' => 'student',
                'clsu_id' => '23-0002',
            ],
            [
                'first_name' => 'Roberto',
                'last_name' => 'Villanueva',
                'email' => 'patient.test3@clsu.edu.ph',
                'password' => 'patient3password',
                'role' => 'patient',
                'user_type' => 'staff',
                'clsu_id' => '1996-0042',
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
            $clsuId = $account['clsu_id'] ?? null;
            $existing = User::where('email', $account['email'])->first();

            // The patient IDs must match the CHIS simulator's seed data. If a
            // different account (e.g. a real registration) already holds one,
            // warn and leave both alone rather than crash on the unique index.
            if ($clsuId !== null && User::where('clsu_id', $clsuId)->where('email', '!=', $account['email'])->exists()) {
                $this->command?->warn("QA account skipped: CLSU ID {$clsuId} is already held by another account.");

                continue;
            }

            if ($existing) {
                // Existing accounts stay untouched, except that a missing
                // CLSU ID is backfilled. One already set is never overwritten.
                if ($clsuId !== null && $existing->clsu_id === null) {
                    $existing->update(['clsu_id' => $clsuId]);
                    $this->command?->info("QA account {$account['email']}: CLSU ID backfilled to {$clsuId}.");
                } else {
                    $this->command?->info("QA account skipped: {$account['email']} already exists.");
                }

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
