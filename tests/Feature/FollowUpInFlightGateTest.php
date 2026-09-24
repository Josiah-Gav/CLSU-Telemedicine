<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\PhysicianAvailabilitySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| A patient may not open a new consultation request, nor a new follow-up
| request, while they already have an unresolved follow-up anywhere — either
| their own follow-up ask not yet decided (FollowUpRequest::IN_FLIGHT_STATUSES:
| pending/forwarded), or the consultation that ask already produced, not yet
| concluded (Consultation::IN_FLIGHT_STATUSES: pending/reviewed/scheduled/
| active). FollowUpRequest::hasInFlightForPatient() is the single check behind
| all four call sites this file exercises.
*/

function fuGatePatient(): User
{
    return User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
}

function fuGatePhysician(): User
{
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'account_status' => 'active',
        'online_status' => 'online',
    ]);

    DB::table('users')->where('user_id', $physician->user_id)->update(['last_seen_at' => now()]);

    PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now()->subHour(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'overtime',
    ]);

    return $physician->refresh();
}

/** A completed original consultation + session for the given patient, eligible for a follow-up ask. */
function fuGateCompletedSession(User $patient, User $physician): ConsultationSession
{
    $request = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Original consultation',
        'request_status' => 'completed',
    ]);

    return ConsultationSession::create([
        'request_id' => $request->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Done.',
        'plan' => 'Done.',
        'recommendations' => 'Done.',
        'assigned_at' => now()->subDay(),
        'started_at' => now()->subDay(),
        'completed_at' => now()->subHour(),
    ]);
}

function fuGateConsultationPayload(): array
{
    return [
        'concern_category' => 'General',
        'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
        'online_reason' => 'Need a consultation',
    ];
}

/*
|--------------------------------------------------------------------------
| FollowUpRequest::hasInFlightForPatient() itself
|--------------------------------------------------------------------------
*/

it('reports in-flight for a pending or forwarded follow-up request', function (string $status) {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned',
        'status' => $status,
    ]);

    expect(FollowUpRequest::hasInFlightForPatient($patient->user_id))->toBeTrue();
})->with(['pending', 'forwarded']);

it('reports not in-flight once the follow-up request is approved, rejected, or cancelled', function (string $status) {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned',
        'status' => $status,
    ]);

    expect(FollowUpRequest::hasInFlightForPatient($patient->user_id))->toBeFalse();
})->with(['approved', 'rejected', 'cancelled']);

it('reports in-flight for a follow-up consultation that has not concluded', function (string $requestStatus) {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $originalSession = fuGateCompletedSession($patient, $physician);

    Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $originalSession->id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Follow-up',
        'request_status' => $requestStatus,
    ]);

    expect(FollowUpRequest::hasInFlightForPatient($patient->user_id))->toBeTrue();
})->with(['pending', 'reviewed', 'scheduled', 'active']);

it('reports not in-flight once the follow-up consultation has concluded', function (string $requestStatus) {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $originalSession = fuGateCompletedSession($patient, $physician);

    Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $originalSession->id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Follow-up',
        'request_status' => $requestStatus,
    ]);

    expect(FollowUpRequest::hasInFlightForPatient($patient->user_id))->toBeFalse();
})->with(['completed', 'rejected', 'cancelled']);

it('never blocks a different patient with no follow-up of their own', function () {
    fuGatePatient();
    $other = fuGatePatient();

    expect(FollowUpRequest::hasInFlightForPatient($other->user_id))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| ConsultationController::store() — new initial consultation
|--------------------------------------------------------------------------
*/

it('refuses a new consultation request while a follow-up request is pending', function () {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned',
        'status' => 'pending',
    ]);

    test()->actingAs($patient)
        ->postJson(route('consultations.store'), fuGateConsultationPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'You already have a follow-up request in progress.');

    // Only the pre-existing completed original consultation from setup — no
    // new one was created by the refused submission.
    expect(Consultation::where('patient_id', $patient->user_id)->count())->toBe(1);
});

it('still refuses a new consultation request while a follow-up consultation is active, via the pre-existing one-active-consultation gate', function () {
    // Consultation::where('patient_id', ...) in the pre-existing gate
    // (ConsultationController::store() item 1) does not filter by type, so
    // it already catches an in-flight follow_up-type Consultation too —
    // this new check's own message is unreachable here, and that is
    // correct: the patient is still refused either way.
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $originalSession = fuGateCompletedSession($patient, $physician);

    Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $originalSession->id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Follow-up',
        'request_status' => 'active',
    ]);

    test()->actingAs($patient)
        ->postJson(route('consultations.store'), fuGateConsultationPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'You may only have one active consultation request at a time.');
});

it('still lets a patient submit a new consultation once their follow-up has concluded', function () {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned',
        'status' => 'rejected',
    ]);

    test()->actingAs($patient)
        ->postJson(route('consultations.store'), fuGateConsultationPayload())
        ->assertCreated()
        ->assertJsonPath('success', true);
});

it('redirects away from the new-consultation form while a follow-up is in flight', function () {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned',
        'status' => 'forwarded',
    ]);

    test()->actingAs($patient)->get(route('consultations.create'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You already have a follow-up request in progress.');

    test()->actingAs($patient)->get(route('newconsultation'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You already have a follow-up request in progress.');
});

/*
|--------------------------------------------------------------------------
| FollowUpRequestController::store() — new follow-up request
|--------------------------------------------------------------------------
*/

it('refuses a new follow-up request on a different consultation while one is already in flight', function () {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();

    $sessionA = fuGateCompletedSession($patient, $physician);
    $sessionB = fuGateCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $sessionA->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned after A',
        'status' => 'pending',
    ]);

    test()->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $sessionB->id]), [
            'reason' => 'Now symptoms from B too',
        ])
        ->assertSessionHasErrors(['reason' => 'You already have a follow-up request in progress.']);

    expect(FollowUpRequest::where('consultation_id', $sessionB->id)->count())->toBe(0);
});

it('refuses a new follow-up request while an unrelated follow-up consultation is active', function () {
    // FollowUpRequestController::store() has no equivalent of the broad
    // Consultation-wide gate ConsultationController::store() already has —
    // this is the one place the Consultation-side branch of
    // hasInFlightForPatient() is actually load-bearing, not redundant with
    // an existing check.
    $patient = fuGatePatient();
    $physician = fuGatePhysician();

    $originalSessionA = fuGateCompletedSession($patient, $physician);
    $sessionB = fuGateCompletedSession($patient, $physician);

    Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $originalSessionA->id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Follow-up',
        'request_status' => 'active',
    ]);

    test()->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $sessionB->id]), [
            'reason' => 'Now symptoms from B too',
        ])
        ->assertSessionHasErrors(['reason' => 'You already have a follow-up request in progress.']);

    expect(FollowUpRequest::where('consultation_id', $sessionB->id)->count())->toBe(0);
});

it('still lets a patient ask for a follow-up when they have none in flight', function () {
    $patient = fuGatePatient();
    $physician = fuGatePhysician();
    $session = fuGateCompletedSession($patient, $physician);

    test()->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $session->id]), [
            'reason' => 'Symptoms returned',
        ])
        ->assertSessionHasNoErrors();

    expect(FollowUpRequest::where('consultation_id', $session->id)->where('status', 'pending')->count())->toBe(1);
});
