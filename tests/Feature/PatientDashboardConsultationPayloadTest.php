<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use App\Support\StatusBadge;

/**
 * The dashboard's consultation card is fed twice: once embedded in the page
 * (window.patientConsultation) and again every 5s by the
 * dashboard.active_consultation poll. Those used to be built by two
 * hand-kept copies that drifted — the poll had no status_meaning and used
 * older badge colours, so the card changed a few seconds after load. Both
 * now come from DashboardController::serializePatientConsultation().
 */
it('embeds the same consultation payload the dashboard poll returns', function (string $requestStatus, ?string $sessionStatus) {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $sessionStatus ? $physician->user_id : null,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => $requestStatus,
        'submitted_at' => now(),
    ]);

    if ($sessionStatus) {
        ConsultationSession::create([
            'request_id' => $consultation->request_id,
            'physician_id' => $physician->user_id,
            'consultation_status' => $sessionStatus,
            'assessment' => '',
            'plan' => '',
            'recommendations' => '',
            'assigned_at' => now(),
        ]);
    }

    $this->actingAs($patient);

    $embedded = $this->get(route('dashboard'))
        ->assertOk()
        ->viewData('patientConsultationPayload');

    $polled = $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->json('consultation');

    // Round-trip through JSON, which is how the page hands it to Alpine.
    expect(json_decode(json_encode($embedded), true))->toBe($polled)
        ->and($polled['status_meaning'])->toBe(StatusBadge::patientMeaning($requestStatus))
        ->and($polled['status_badge_class'])->toBe(StatusBadge::patientClasses($requestStatus));
})->with([
    'pending, no session' => ['pending', null],
    'scheduled' => ['scheduled', 'scheduled'],
    'active' => ['active', 'active'],
]);
