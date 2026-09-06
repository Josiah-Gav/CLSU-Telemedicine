<?php

use App\Enums\NotificationType;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\Notification;
use App\Models\PhysicianAvailabilitySession;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Phase 6: ConsultationController::store() refuses a NEW consultation request
| unless PhysicianAvailabilityService::isServiceAvailable() says the service
| can take one.
|
| These tests build real database state — physicians, presence, intake
| sessions, queue depth — and let the service reach its own verdict, rather
| than faking it. That way the gate is exercised exactly as it is in
| production, and none of the service's rules are restated here.
*/

function gatePatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

/**
 * A physician who counts toward service availability, with the knobs each
 * unavailability scenario needs to turn off individually.
 */
function gatePhysician(
    bool $intakeOpen = true,
    bool $presenceOnline = true,
    bool $presenceFresh = true,
    bool $intakeFresh = true,
    string $accountStatus = 'active',
): User {
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'account_status' => $accountStatus,
        'online_status' => $presenceOnline ? 'online' : 'offline',
    ]);

    // Through the query builder because last_seen_at is not mass-assignable —
    // the same reason TrackUserPresence and PresenceController write it this way.
    DB::table('users')->where('user_id', $physician->user_id)->update([
        'last_seen_at' => $presenceFresh ? now() : now()->subMinutes(30),
    ]);

    if ($intakeOpen) {
        PhysicianAvailabilitySession::create([
            'physician_id' => $physician->user_id,
            'started_at' => now()->subHour(),
            'last_seen_at' => $intakeFresh ? now() : now()->subHour(),
            'status' => 'open',
            'mode' => 'overtime',
        ]);
    }

    return $physician->refresh();
}

function gateSubmission(array $overrides = []): array
{
    return array_merge([
        'concern_category' => 'General',
        'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 2]]),
        'online_reason' => 'Need a consultation',
    ], $overrides);
}

function submitConsultation(User $patient, array $overrides = [])
{
    return test()->actingAs($patient)->postJson(route('consultations.store'), gateSubmission($overrides));
}

/** Fills the nurse-review queue with requests from other patients. */
function fillPendingQueue(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        Consultation::create([
            'patient_id' => gatePatient()->user_id,
            'concern_category' => 'General',
            'symptoms_desc' => [['name' => 'Cough']],
            'online_reason' => 'Queued for a nurse',
            'request_status' => 'pending',
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Service available
|--------------------------------------------------------------------------
*/

it('lets a patient submit when the service can take a new consultation', function () {
    gatePhysician();
    $patient = gatePatient();

    submitConsultation($patient)
        ->assertCreated()
        ->assertJsonPath('success', true);

    $consultation = Consultation::where('patient_id', $patient->user_id)->sole();

    expect($consultation->request_status)->toBe('pending')
        ->and($consultation->concern_category)->toBe('General')
        ->and($consultation->online_reason)->toBe('Need a consultation')
        ->and($consultation->symptoms_desc)->toBe([['name' => 'Headache', 'severity' => 2]])
        ->and($consultation->assigned_nurse_id)->toBeNull()
        ->and($consultation->assigned_physician_id)->toBeNull();
});

it('still notifies nurses when an accepted submission goes through the gate', function () {
    gatePhysician();
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $patient = gatePatient();

    submitConsultation($patient)->assertCreated();

    expect(Notification::where('user_id', $nurse->user_id)
        ->where('type', NotificationType::CONSULTATION_SUBMITTED->value)
        ->count())->toBe(1);
});

it('still stores attachments on an accepted submission', function () {
    Storage::fake('public');
    gatePhysician();
    $patient = gatePatient();

    // Cloudinary is unreachable in tests, so store() takes its documented
    // local-disk fallback — which is enough to prove the upload path still runs.
    test()->actingAs($patient)->postJson(route('consultations.store'), gateSubmission([
        'attachments' => [UploadedFile::fake()->create('rash.jpg', 20, 'image/jpeg')],
    ]))->assertCreated();

    expect(Consultation::where('patient_id', $patient->user_id)->sole()->file_attachments)
        ->not->toBeEmpty()
        ->and(Storage::disk('public')->allFiles('consultation-attachments'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Service unavailable
|--------------------------------------------------------------------------
*/

it('refuses a new submission with 503 when the service cannot take one', function () {
    // No physician at all — nobody has intake open.
    $patient = gatePatient();

    submitConsultation($patient)
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Consultations are currently unavailable. Please try again later.');
});

it('creates no consultation request when the service is unavailable', function () {
    $patient = gatePatient();

    submitConsultation($patient)->assertStatus(503);

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationSession::count())->toBe(0);
});

it('sends no nurse notification when the service is unavailable', function () {
    User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $patient = gatePatient();

    submitConsultation($patient)->assertStatus(503);

    expect(Notification::count())->toBe(0);
});

it('does not process attachments when the service is unavailable', function () {
    Storage::fake('public');
    $patient = gatePatient();

    test()->actingAs($patient)->postJson(route('consultations.store'), gateSubmission([
        'attachments' => [UploadedFile::fake()->create('rash.jpg', 20, 'image/jpeg')],
    ]))->assertStatus(503);

    // The gate sits before the upload block, so nothing reached either the
    // Cloudinary call or its local-disk fallback.
    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Consultation::count())->toBe(0);
});

it('does not disclose anything about individual physicians when refusing', function () {
    $physician = gatePhysician(intakeOpen: false, presenceOnline: false);
    $patient = gatePatient();

    $response = submitConsultation($patient)->assertStatus(503);
    $body = $response->getContent();

    expect($body)->not->toContain($physician->first_name)
        ->not->toContain($physician->last_name)
        ->not->toContain('physician')
        ->not->toContain('offline');
});

/*
|--------------------------------------------------------------------------
| Each unavailable reason, decided by the service from real state
|--------------------------------------------------------------------------
*/

it('refuses when no physician has intake open', function () {
    gatePhysician(intakeOpen: false);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

it('refuses when the only open intake session is stale', function () {
    gatePhysician(intakeFresh: false);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0)
        // The session row is still 'open' — the gate refused on read-time
        // staleness, without waiting for the expiry scheduler.
        ->and(PhysicianAvailabilitySession::where('status', 'open')->count())->toBe(1);
});

it('refuses when the physician holding intake is offline', function () {
    gatePhysician(presenceOnline: false);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

it('refuses when the physician presence has gone stale', function () {
    gatePhysician(presenceFresh: false);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

it('refuses when the physician account is not active', function () {
    gatePhysician(accountStatus: 'suspended');

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

it('refuses when the pending queue has reached the configured limit', function () {
    config()->set('consultations.intake.queue_limit', 3);

    gatePhysician();
    fillPendingQueue(3);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::pending()->count())->toBe(3);
});

it('accepts again once the queue drops below the configured limit', function () {
    config()->set('consultations.intake.queue_limit', 3);

    gatePhysician();
    fillPendingQueue(3);
    $patient = gatePatient();

    submitConsultation($patient)->assertStatus(503);

    // A nurse claiming a request moves it off 'pending' and frees capacity.
    Consultation::query()->pending()->first()->update(['request_status' => 'reviewed']);

    submitConsultation($patient)->assertCreated();

    expect(Consultation::pending()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Multiple physicians
|--------------------------------------------------------------------------
*/

it('accepts when at least one eligible physician has valid intake', function (bool $firstAvailable) {
    // The patient never names a physician; availability is service-level, so
    // it does not matter which of the two is the available one.
    gatePhysician(intakeOpen: $firstAvailable, presenceOnline: $firstAvailable);
    gatePhysician(intakeOpen: ! $firstAvailable, presenceOnline: ! $firstAvailable);

    submitConsultation(gatePatient())->assertCreated();

    expect(Consultation::pending()->count())->toBe(1);
})->with([
    'first physician available' => true,
    'second physician available' => false,
]);

it('refuses when every eligible physician is unavailable', function () {
    gatePhysician(intakeOpen: false);
    gatePhysician(presenceOnline: false);
    gatePhysician(intakeFresh: false);

    submitConsultation(gatePatient())->assertStatus(503);

    expect(Consultation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Duplicate-request protection keeps precedence
|--------------------------------------------------------------------------
*/

it('still refuses a duplicate submission with the existing 422 while the service is available', function () {
    gatePhysician();
    $patient = gatePatient();

    submitConsultation($patient)->assertCreated();

    submitConsultation($patient)
        ->assertStatus(422)
        ->assertJsonPath('message', 'You may only have one active consultation request at a time.');

    expect(Consultation::where('patient_id', $patient->user_id)->count())->toBe(1);
});

it('reports the duplicate, not the outage, when a patient with an open request submits while unavailable', function () {
    // Both conditions are true. The duplicate is the more specific and more
    // useful answer — this patient would be refused whatever intake was doing —
    // so the existing check keeps precedence and its exact message.
    $patient = gatePatient();

    Consultation::create([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Already waiting',
        'request_status' => 'pending',
    ]);

    submitConsultation($patient)
        ->assertStatus(422)
        ->assertJsonPath('message', 'You may only have one active consultation request at a time.');

    expect(Consultation::where('patient_id', $patient->user_id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The gate governs creation only
|--------------------------------------------------------------------------
*/

it('leaves every existing consultation untouched when a submission is refused', function () {
    $patient = gatePatient();
    $otherPatient = gatePatient();
    $physician = gatePhysician(intakeOpen: false);

    $active = Consultation::create([
        'patient_id' => $otherPatient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'In progress',
        'request_status' => 'active',
    ]);

    $activeSession = ConsultationSession::create([
        'request_id' => $active->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $scheduled = Consultation::create([
        'patient_id' => gatePatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Cough']],
        'online_reason' => 'Booked in',
        'request_status' => 'scheduled',
    ]);

    $pending = Consultation::create([
        'patient_id' => gatePatient()->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Rash']],
        'online_reason' => 'Waiting for a nurse',
        'request_status' => 'pending',
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => CarbonImmutable::now()->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    submitConsultation($patient)->assertStatus(503);

    expect($active->fresh()->request_status)->toBe('active')
        ->and($activeSession->fresh()->consultation_status)->toBe('active')
        ->and($activeSession->fresh()->completed_at)->toBeNull()
        ->and($scheduled->fresh()->request_status)->toBe('scheduled')
        ->and($pending->fresh()->request_status)->toBe('pending')
        ->and($slot->fresh()->status)->toBe('booked')
        ->and($physician->fresh()->online_status)->toBe('online');
});

it('does not gate the nurse workflow on a request that was already accepted', function () {
    gatePhysician();
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $patient = gatePatient();

    submitConsultation($patient)->assertCreated();
    $consultation = Consultation::where('patient_id', $patient->user_id)->sole();

    // Intake closes after the request was accepted.
    PhysicianAvailabilitySession::query()->update(['status' => 'closed', 'ended_at' => now()]);

    // The nurse can still claim and prioritise it exactly as before.
    test()->actingAs($nurse)
        ->postJson(route('consultations.approve', $consultation), ['priority_level' => 'High'])
        ->assertOk();

    $consultation = $consultation->fresh();

    expect($consultation->request_status)->toBe('reviewed')
        ->and($consultation->assigned_nurse_id)->toBe($nurse->user_id)
        ->and($consultation->priority_level)->toBe('High');
});

it('does not gate physician-initiated follow-ups when new patient intake is closed', function () {
    $physician = gatePhysician(intakeOpen: false);
    $patient = gatePatient();

    $completedRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Original consultation',
        'request_status' => 'completed',
    ]);

    $completedSession = ConsultationSession::create([
        'request_id' => $completedRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'Done.',
        'plan' => 'Done.',
        'recommendations' => 'Done.',
        'assigned_at' => now()->subDay(),
        'started_at' => now()->subDay(),
        'completed_at' => now()->subHour(),
    ]);

    // The patient may still ask for a follow-up: it is a separate workflow that
    // creates a follow_up_requests row, never a new pending consultation, so it
    // never passes through the intake gate.
    test()->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $completedSession->id]), [
            'reason' => 'Symptoms returned',
        ])
        ->assertSessionHasNoErrors();

    expect(FollowUpRequest::where('consultation_id', $completedSession->id)->count())->toBe(1)
        ->and(Consultation::pending()->count())->toBe(0);
});
