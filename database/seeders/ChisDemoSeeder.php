<?php

namespace Database\Seeders;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the CHIS presentation: ONE active consultation for QA patient
 * test1 and QA physician test1, so the physician can complete it live without
 * walking the whole triage and scheduling flow.
 *
 * Invoked directly: `php artisan db:seed --class=ChisDemoSeeder`. Never called
 * from DatabaseSeeder, and refuses to run outside the local environment.
 *
 * It writes statuses directly (request_status = 'active', consultation_status =
 * 'active') for demo data only, bypassing ConsultationOwnershipService on
 * purpose: the service's claim/schedule/start transitions need a nurse, a
 * booked slot and several steps that add nothing to a CHIS demonstration. Do
 * not copy this pattern into application code.
 *
 * Idempotent: does nothing while patient.test1 already has an active session.
 */
class ChisDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('ChisDemoSeeder only runs in the local environment (APP_ENV=local): it writes consultation statuses directly and is demo data, never for a shared or production database.');

            return;
        }

        $patient = User::where('email', 'patient.test1@clsu.edu.ph')->first();
        $physician = User::where('email', 'physician.test1@clsu.edu.ph')->first();

        if (! $patient || ! $physician) {
            $this->command?->error('QA accounts are missing. Run `php artisan db:seed --class=QaTestUsersSeeder` first.');

            return;
        }

        if ($patient->activeConsultationSession()) {
            $this->command?->info('patient.test1 already has an active consultation; nothing created.');

            return;
        }

        $session = DB::transaction(function () use ($patient, $physician) {
            $request = Consultation::forceCreate([
                'patient_id' => $patient->user_id,
                'assigned_physician_id' => $physician->user_id,
                'type' => 'initial',
                'concern_category' => 'Respiratory',
                'symptoms_desc' => [
                    ['name' => 'Cough', 'severity' => 2, 'date' => now()->subDays(2)->toDateString(), 'time' => '08:30', 'custom' => false],
                    ['name' => 'Sore throat', 'severity' => 1, 'date' => now()->subDay()->toDateString(), 'time' => '19:00', 'custom' => false],
                ],
                'online_reason' => 'Demo consultation for the CHIS presentation.',
                'additional_information' => 'Created by ChisDemoSeeder.',
                'request_status' => 'active',
                'priority_level' => 'Normal',
            ]);

            return ConsultationSession::create([
                'request_id' => $request->request_id,
                'physician_id' => $physician->user_id,
                'consultation_status' => 'active',
                'diagnosis' => 'Acute upper respiratory infection (demo)',
                'assessment' => 'Mild cough and sore throat for two days; afebrile; no red flags (demo).',
                'plan' => 'Rest, fluids and symptomatic relief (demo).',
                'recommendations' => 'Return if fever or breathing difficulty develops (demo).',
                'assigned_at' => now()->subMinutes(10),
                'started_at' => now()->subMinutes(5),
            ]);
        });

        $this->command?->info("Demo consultation created: session #{$session->id} for patient.test1 / physician.test1.");
    }
}
