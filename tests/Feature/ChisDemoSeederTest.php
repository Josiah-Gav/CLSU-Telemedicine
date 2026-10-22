<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use Database\Seeders\ChisDemoSeeder;
use Database\Seeders\QaTestUsersSeeder;
use Illuminate\Support\Facades\Queue;

function seedChisDemo(string $env = 'local'): void
{
    app()['env'] = $env;
    (new ChisDemoSeeder)->run();
}

it('refuses to run outside the local environment', function () {
    (new QaTestUsersSeeder)->run();

    seedChisDemo('testing');

    expect(ConsultationSession::count())->toBe(0)->and(Consultation::count())->toBe(0);
});

it('creates one consistent active consultation, and nothing on a second run', function () {
    (new QaTestUsersSeeder)->run();

    seedChisDemo();
    seedChisDemo();

    $patient = User::where('email', 'patient.test1@clsu.edu.ph')->first();
    $physician = User::where('email', 'physician.test1@clsu.edu.ph')->first();
    $session = ConsultationSession::sole();

    expect(Consultation::count())->toBe(1)
        ->and($session->consultation_status)->toBe('active')
        ->and($session->physician_id)->toBe($physician->user_id)
        ->and($session->request->request_status)->toBe('active')
        ->and($session->request->patient_id)->toBe($patient->user_id)
        ->and($session->request->assigned_physician_id)->toBe($physician->user_id)
        ->and($patient->activeConsultationSession()?->id)->toBe($session->id)
        ->and($session->hasMeaningfulAssessment() && $session->hasMeaningfulPlan() && $session->hasMeaningfulRecommendations() && $session->hasDiagnosis())->toBeTrue()
        ->and($session->request->symptoms_desc)->toHaveCount(2);
});

it('tells you to run the QA seeder when the accounts are missing', function () {
    seedChisDemo();

    expect(ConsultationSession::count())->toBe(0);
});

it('lets the seeded consultation be completed by the physician', function () {
    (new QaTestUsersSeeder)->run();
    seedChisDemo();
    app()['env'] = 'testing';
    $session = ConsultationSession::sole();
    Queue::fake();

    $this->actingAs(User::where('email', 'physician.test1@clsu.edu.ph')->first())
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    // The demo patient never consents; completion still queues the CHIS push.
    expect($session->refresh()->consultation_status)->toBe('completed')
        ->and($session->request->patient->chis_consent_at)->toBeNull()
        ->and($session->chis_sync_status)->toBe('pending');
});
