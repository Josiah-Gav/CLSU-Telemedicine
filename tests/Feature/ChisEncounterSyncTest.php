<?php

use App\Exceptions\Chis\ChisUnavailableException;
use App\Exceptions\Chis\InvalidChisPayloadException;
use App\Jobs\SyncEncounterToChis;
use App\Models\ChisAuditLog;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\User;
use App\Services\ChisClient;
use App\Services\ChisEncounterPayload;
use App\Services\ChisEncounterSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A request + session for a consenting patient with a CLSU ID. Completed by
 * default; pass consultation_status => active (and completed_at => null) to
 * complete it through the real endpoint.
 */
function chisScenario(array $session = [], array $request = [], array $patient = [], ?string $consent = '2026-10-01 09:00:00'): ConsultationSession
{
    $patientUser = User::factory()->create(array_merge([
        'role' => 'patient', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'clsu_id' => '23-0001',
        'email' => 'juan.patient@clsu.edu.ph', 'contact_num' => '09171234567',
    ], $patient));
    $patientUser->forceFill(['chis_consent_at' => $consent])->save();

    $physician = User::factory()->create([
        'role' => 'physician', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate(array_merge([
        'patient_id' => $patientUser->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3, 'date' => '2026-10-19', 'time' => '08:30']],
        'online_reason' => 'Cannot travel',
        'additional_information' => null,
        'request_status' => 'completed',
        'priority_level' => 'High',
    ], $request));

    return ConsultationSession::create(array_merge([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'diagnosis' => 'Tension headache',
        'assessment' => 'Mild intermittent headache, no red flags.',
        'plan' => 'Rest and hydration.',
        'recommendations' => 'Return if it worsens.',
        'assigned_at' => '2026-10-20 09:55:00',
        'started_at' => '2026-10-20 10:00:00',
        'completed_at' => '2026-10-20 10:30:00',
    ], $session));
}

function chisPending(array $session = [], array $request = [], array $patient = [], ?string $consent = '2026-10-01 09:00:00'): ConsultationSession
{
    $s = chisScenario($session, $request, $patient, $consent);
    $s->forceFill(['chis_sync_status' => 'pending'])->save();

    return $s;
}

function runChisJob(ConsultationSession $session): void
{
    (new SyncEncounterToChis($session->id))->handle(app(ChisClient::class));
}

function chisReceiptResponse(int $status = 201)
{
    return Http::response(['data' => [
        'chis_encounter_id' => 'CHIS-ENC-000042', 'telemed_encounter_id' => 1,
        'patient_clsu_id' => '23-0001', 'received_at' => '2026-10-20T10:31:00+08:00', 'status' => 'received',
    ]], $status);
}

// --- payload -------------------------------------------------------------------

it('builds the exact contract payload for a general consultation', function () {
    $session = chisScenario();
    $request = $session->request;

    expect(ChisEncounterPayload::build($session))->toBe([
        'schema_version' => '1.0',
        'telemed_encounter_id' => $session->id,
        'telemed_request_id' => $request->request_id,
        'consent' => ['data_sharing_consented_at' => '2026-10-01T09:00:00+08:00'],
        'patient' => ['clsu_id' => '23-0001', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz'],
        'physician' => ['telemed_user_id' => $session->physician_id, 'name' => 'Ana Reyes', 'specialization' => 'General Medicine'],
        'encounter' => [
            'type' => 'general', 'parent_telemed_encounter_id' => null, 'mode' => 'teleconsultation',
            'started_at' => '2026-10-20T10:00:00+08:00', 'completed_at' => '2026-10-20T10:30:00+08:00',
        ],
        'presenting_complaint' => [
            'concern_category' => 'Headache',
            'symptoms' => [['name' => 'Headache', 'severity' => 3, 'severity_label' => 'moderate', 'onset_date' => '2026-10-19', 'onset_time' => '08:30']],
            'additional_information' => null,
        ],
        'clinical' => [
            'diagnosis' => 'Tension headache', 'assessment' => 'Mild intermittent headache, no red flags.',
            'plan' => 'Rest and hydration.', 'recommendations' => 'Return if it worsens.',
        ],
        'follow_up' => ['required' => null, 'date' => null],
        'prescription' => ['attached' => false, 'file_name' => null, 'mime_type' => null],
    ]);
});

it('links a follow-up to its parent session, and rejects one without a parent', function () {
    $parent = chisScenario();
    $followUp = chisScenario(request: ['type' => 'follow_up', 'parent_consultation_id' => $parent->id], patient: ['clsu_id' => '23-0002', 'email' => 'p2@clsu.edu.ph']);

    expect(ChisEncounterPayload::build($followUp)['encounter'])
        ->toMatchArray(['type' => 'follow_up', 'parent_telemed_encounter_id' => $parent->id]);

    $orphan = chisScenario(request: ['type' => 'follow_up', 'parent_consultation_id' => null], patient: ['clsu_id' => '23-0003', 'email' => 'p3@clsu.edu.ph']);

    expect(fn () => ChisEncounterPayload::build($orphan))->toThrow(InvalidChisPayloadException::class, 'follow_up_without_parent');
});

it('normalises symptoms and skips invalid entries', function () {
    $session = chisScenario(request: ['symptoms_desc' => [
        ['name' => ' Fever ', 'severity' => '4', 'date' => '', 'time' => ''],
        ['name' => 'Cough', 'severity' => 1, 'date' => '2026-10-18', 'time' => '7:05:00'],
        ['name' => 'Legacy', 'severity' => 'mild'],
        ['name' => 'Out of scale', 'severity' => 9],
        ['name' => '', 'severity' => 2],
        ['severity' => 2],
        ['name' => str_repeat('x', 256), 'severity' => 2],
        ['name' => 'Bad onset', 'severity' => 2, 'date' => 'yesterday', 'time' => '25:00'],
        'not an entry',
    ]]);

    expect(ChisEncounterPayload::build($session)['presenting_complaint']['symptoms'])->toBe([
        ['name' => 'Fever', 'severity' => 4, 'severity_label' => 'severe', 'onset_date' => null, 'onset_time' => null],
        ['name' => 'Cough', 'severity' => 1, 'severity_label' => 'very_mild', 'onset_date' => '2026-10-18', 'onset_time' => '07:05'],
        ['name' => 'Bad onset', 'severity' => 2, 'severity_label' => 'mild', 'onset_date' => null, 'onset_time' => null],
    ]);
});

it('is invalid when no valid symptom remains', function () {
    $session = chisScenario(request: ['symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']]]);

    expect(fn () => ChisEncounterPayload::build($session))->toThrow(InvalidChisPayloadException::class, 'no_valid_symptoms');
});

it('enforces the contract length limits without truncating', function () {
    expect(ChisEncounterPayload::build(chisScenario(['assessment' => str_repeat('a', 10000)]))['clinical']['assessment'])
        ->toHaveLength(10000);

    $tooLong = chisScenario(['assessment' => str_repeat('a', 10001)], patient: ['clsu_id' => '23-0002', 'email' => 'p2@clsu.edu.ph']);

    expect(fn () => ChisEncounterPayload::build($tooLong))->toThrow(InvalidChisPayloadException::class, 'assessment_too_long');
});

it('includes only the prescription name and type, never its path, and no forbidden data', function () {
    $session = chisScenario([
        'prescription_file_name' => 'rx.pdf',
        'prescription_file_path' => 'consultation-prescriptions/7/secret-path.pdf',
        'prescription_mime_type' => 'application/pdf',
    ]);
    Message::create(['consultation_id' => $session->id, 'sender_id' => $session->physician_id, 'message' => 'Private chat about your rash']);

    $payload = ChisEncounterPayload::build($session);
    $json = json_encode($payload);

    expect($payload['prescription'])->toBe(['attached' => true, 'file_name' => 'rx.pdf', 'mime_type' => 'application/pdf']);
    foreach (['secret-path', 'consultation-prescriptions', 'juan.patient@clsu.edu.ph', '09171234567', 'Private chat', 'Cannot travel', 'High', '"patient_id"'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

// --- completion ----------------------------------------------------------------

function completeAsPhysician(ConsultationSession $session)
{
    return test()->actingAs(User::find($session->physician_id))
        ->postJson(route('consultations.messaging.complete', $session));
}

function activeChisScenario(array $patient = [], ?string $consent = '2026-10-01 09:00:00'): ConsultationSession
{
    return chisScenario(['consultation_status' => 'active', 'completed_at' => null], ['request_status' => 'active'], $patient, $consent);
}

it('queues the sync once, after commit, for an eligible patient', function () {
    Queue::fake();
    $session = activeChisScenario();

    completeAsPhysician($session)->assertOk();
    completeAsPhysician($session)->assertOk(); // already completed: early return

    expect($session->refresh()->chis_sync_status)->toBe('pending');
    Queue::assertPushed(SyncEncounterToChis::class, 1);
    Queue::assertPushed(SyncEncounterToChis::class, fn ($job) => $job->sessionId === $session->id);
});

it('never queues the same session twice', function () {
    Queue::fake();
    $session = chisScenario();

    ChisEncounterSync::queue($session);
    ChisEncounterSync::queue($session);

    Queue::assertPushed(SyncEncounterToChis::class, 1);
});

it('marks the session skipped, without queueing, when the patient is not eligible', function (array $patient, ?string $consent, string $reason) {
    Queue::fake();
    $session = activeChisScenario($patient, $consent);

    completeAsPhysician($session)->assertOk();

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error']))
        ->toBe(['chis_sync_status' => 'skipped', 'chis_last_error' => $reason]);
    Queue::assertNotPushed(SyncEncounterToChis::class);
})->with([
    'no consent' => [[], null, 'no_consent'],
    'no clsu_id' => [['clsu_id' => null], '2026-10-01 09:00:00', 'no_clsu_id'],
]);

it('still completes when CHIS is down, even with a synchronous queue', function () {
    Http::fake(['chis.test/*' => Http::response(['message' => 'down', 'code' => 'UNAVAILABLE'], 503)]);
    $session = activeChisScenario();

    completeAsPhysician($session)->assertOk();

    expect($session->refresh()->consultation_status)->toBe('completed')
        ->and($session->chis_sync_status)->toBe('pending')
        ->and($session->chis_last_error)->toBe('http_503');
});

// --- job -----------------------------------------------------------------------

it('records synced with the CHIS reference on 201 and on an identical-resend 200', function (int $status) {
    Http::fake(['chis.test/*' => chisReceiptResponse($status)]);
    $session = chisPending();

    runChisJob($session);

    $session->refresh();
    expect($session->chis_sync_status)->toBe('synced')
        ->and($session->chis_reference)->toBe('CHIS-ENC-000042')
        ->and($session->chis_synced_at)->not->toBeNull()
        ->and($session->chis_last_error)->toBeNull()
        ->and($session->chis_attempts)->toBe(1);
    Http::assertSent(fn ($r) => $r->url() === 'http://chis.test/api/v1/encounters' && $r['telemed_encounter_id'] === $session->id);
})->with([201, 200]);

it('records a permanent rejection as failed with its code, without retrying', function (int $status, string $code) {
    Http::fake(['chis.test/*' => Http::response(['message' => 'no', 'code' => $code], $status)]);
    $session = chisPending();

    runChisJob($session); // does not throw

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error']))
        ->toBe(['chis_sync_status' => 'failed', 'chis_last_error' => "http_{$status}:{$code}"]);
    Http::assertSentCount(1);
})->with([[409, 'ENCOUNTER_CONFLICT'], [422, 'VALIDATION_FAILED'], [404, 'PATIENT_NOT_FOUND']]);

it('keeps a transient failure pending, counts the attempt and rethrows for the queue', function ($response, string $reason) {
    Http::fake(['chis.test/*' => $response]);
    $session = chisPending();

    expect(fn () => runChisJob($session))->toThrow(ChisUnavailableException::class);

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error', 'chis_attempts']))
        ->toBe(['chis_sync_status' => 'pending', 'chis_last_error' => $reason, 'chis_attempts' => 1]);
})->with([
    '503' => [fn () => Http::response(['message' => 'x', 'code' => 'UNAVAILABLE'], 503), 'http_503'],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'connection_failed'],
]);

it('skips without any request when consent was withdrawn after completion', function () {
    Http::fake();
    $session = chisPending(consent: null);

    runChisJob($session);

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error']))
        ->toBe(['chis_sync_status' => 'skipped', 'chis_last_error' => 'no_consent']);
    Http::assertNothingSent();
});

it('does nothing for a session that is already synced', function () {
    Http::fake();
    $session = chisScenario();
    $session->forceFill(['chis_sync_status' => 'synced', 'chis_reference' => 'CHIS-1'])->save();

    runChisJob($session);

    expect($session->refresh()->chis_sync_status)->toBe('synced');
    Http::assertNothingSent();
});

it('fails an invalid payload locally without sending it', function () {
    Http::fake();
    $session = chisPending(request: ['symptoms_desc' => [['name' => 'x', 'severity' => 'mild']]]);

    runChisJob($session);

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error', 'chis_attempts']))
        ->toBe(['chis_sync_status' => 'failed', 'chis_last_error' => 'invalid_payload:no_valid_symptoms', 'chis_attempts' => 0]);
    Http::assertNothingSent();
});

it('leaves the session pending when the job finally gives up', function () {
    $session = chisPending();

    (new SyncEncounterToChis($session->id))->failed(new ChisUnavailableException('x', 'http_503', 503));

    expect($session->refresh()->chis_sync_status)->toBe('pending');
});

it('is configured to retry with backoff and stay unique per session', function () {
    $job = new SyncEncounterToChis(42);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600])
        ->and($job->uniqueId())->toBe('42')
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
});

it('serializes with only the session id, never clinical data', function () {
    $session = chisScenario();

    $serialized = serialize(new SyncEncounterToChis($session->id));

    foreach (['Tension headache', 'Mild intermittent', 'Rest and hydration', 'Return if it worsens', 'Headache', 'Dela Cruz', '23-0001'] as $clinical) {
        expect($serialized)->not->toContain($clinical);
    }
});

// --- sweeper -------------------------------------------------------------------

it('re-queues only pending sessions untouched for over 10 minutes', function () {
    Queue::fake();
    $stale = chisPending();
    $fresh = chisPending(patient: ['clsu_id' => '23-0002', 'email' => 'p2@clsu.edu.ph']);
    $syncedOld = chisScenario(patient: ['clsu_id' => '23-0003', 'email' => 'p3@clsu.edu.ph']);
    $failedOld = chisScenario(patient: ['clsu_id' => '23-0004', 'email' => 'p4@clsu.edu.ph']);

    $old = now()->subMinutes(11);
    ConsultationSession::whereKey($stale->id)->update(['updated_at' => $old]);
    ConsultationSession::whereKey($syncedOld->id)->update(['chis_sync_status' => 'synced', 'updated_at' => $old]);
    ConsultationSession::whereKey($failedOld->id)->update(['chis_sync_status' => 'failed', 'updated_at' => $old]);

    $this->artisan('chis:sync-pending')->expectsOutputToContain('Queued 1 pending CHIS sync(s).')->assertExitCode(0);

    Queue::assertPushed(SyncEncounterToChis::class, 1);
    Queue::assertPushed(SyncEncounterToChis::class, fn ($job) => $job->sessionId === $stale->id);
});

// --- chis:health --patient -----------------------------------------------------

it('chis:health --patient reports a found patient by name only', function () {
    Http::fake([
        'chis.test/api/v1/health' => Http::response(['status' => 'ok']),
        'chis.test/api/v1/patients/*' => Http::response(['data' => [
            'clsu_id' => '23-0001', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'blood_type' => 'O+',
            'medical_profile' => ['allergies' => ['Penicillin'], 'chronic_conditions' => ['Asthma']],
        ]]),
    ]);

    $this->artisan('chis:health', ['--patient' => '23-0001'])
        ->expectsOutputToContain('Patient 23-0001: found (Juan Dela Cruz)')
        ->doesntExpectOutputToContain('Penicillin')
        ->doesntExpectOutputToContain('Asthma')
        ->doesntExpectOutputToContain('O+')
        ->doesntExpectOutputToContain('test-chis-token')
        ->assertExitCode(0);
});

it('chis:health --patient reports not found and errors', function () {
    Http::fake([
        'chis.test/api/v1/health' => Http::response(['status' => 'ok']),
        'chis.test/api/v1/patients/99-0000' => Http::response(['message' => 'x', 'code' => 'PATIENT_NOT_FOUND'], 404),
        'chis.test/api/v1/patients/23-0001' => Http::response(['message' => 'x', 'code' => 'SERVER_ERROR'], 500),
        'chis.test/api/v1/patients/23-0002' => Http::response(['message' => 'x', 'code' => 'UNAUTHENTICATED'], 401),
    ]);

    $this->artisan('chis:health', ['--patient' => '99-0000'])->expectsOutputToContain('Patient 99-0000: not found')->assertExitCode(0);
    $this->artisan('chis:health', ['--patient' => '23-0001'])
        ->expectsOutputToContain('Patient 23-0001: error ChisUnavailableException (http_500)')->assertExitCode(1);
    $this->artisan('chis:health', ['--patient' => '23-0002'])
        ->expectsOutputToContain('Patient 23-0002: error ChisRequestException (HTTP 401, UNAUTHENTICATED)')->assertExitCode(1);
});

// --- consent is forward-only ---------------------------------------------------

it('never re-sends a session skipped for no consent, even after the patient consents', function () {
    Queue::fake();
    $session = chisScenario(consent: null);
    ChisEncounterSync::queue($session);
    expect($session->refresh()->chis_sync_status)->toBe('skipped');

    $session->request->patient->forceFill(['chis_consent_at' => now()])->save();
    ConsultationSession::whereKey($session->id)->update(['updated_at' => now()->subHour()]);
    $this->artisan('chis:sync-pending')->assertExitCode(0);

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error']))
        ->toBe(['chis_sync_status' => 'skipped', 'chis_last_error' => 'no_consent']);
    Queue::assertNotPushed(SyncEncounterToChis::class);
});

// --- audit log -----------------------------------------------------------------

it('writes exactly one audit row per push, with outcome, status, reason, reference and duration', function ($response, string $outcome, ?int $status, ?string $reason, ?string $reference) {
    Http::fake(['chis.test/*' => $response]);
    $session = chisPending();

    try {
        runChisJob($session);
    } catch (ChisUnavailableException) {
        // transient: rethrown for the queue, still audited
    }

    $rows = ChisAuditLog::all();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->only(['action', 'outcome', 'source', 'actor_user_id', 'consultation_session_id', 'patient_user_id', 'clsu_id', 'http_status', 'reason', 'chis_reference']))
        ->toBe([
            'action' => 'encounter_push', 'outcome' => $outcome, 'source' => 'sync_job', 'actor_user_id' => null,
            'consultation_session_id' => $session->id, 'patient_user_id' => $session->request->patient_id, 'clsu_id' => '23-0001',
            'http_status' => $status, 'reason' => $reason, 'chis_reference' => $reference,
        ])
        ->and($rows->first()->duration_ms)->toBeInt()
        ->and($rows->first()->created_at)->not->toBeNull();
})->with([
    'success 201' => [fn () => chisReceiptResponse(201), 'success', 201, null, 'CHIS-ENC-000042'],
    'identical resend 200' => [fn () => chisReceiptResponse(200), 'success', 200, null, 'CHIS-ENC-000042'],
    '409' => [fn () => Http::response(['message' => 'x', 'code' => 'ENCOUNTER_CONFLICT'], 409), 'rejected', 409, 'http_409:ENCOUNTER_CONFLICT', null],
    '503' => [fn () => Http::response(['message' => 'x', 'code' => 'UNAVAILABLE'], 503), 'unavailable', 503, 'http_503', null],
    'connection error' => [fn () => fn () => throw new ConnectionException('cURL error 7'), 'unavailable', null, 'connection_failed', null],
]);

it('writes no audit row when no call is made', function (Closure $setup) {
    Http::fake();
    runChisJob($setup());

    expect(ChisAuditLog::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'skipped (consent withdrawn)' => [fn () => chisPending(consent: null)],
    'invalid payload' => [fn () => chisPending(request: ['symptoms_desc' => [['name' => 'x', 'severity' => 'mild']]])],
    'already synced' => [fn () => tap(chisScenario(), fn ($s) => $s->forceFill(['chis_sync_status' => 'synced'])->save())],
]);

it('still syncs when the audit write fails', function () {
    Http::fake(['chis.test/*' => chisReceiptResponse(201)]);
    $session = chisPending();
    Schema::drop('chis_audit_logs');

    runChisJob($session);

    expect($session->refresh()->only(['chis_sync_status', 'chis_reference']))
        ->toBe(['chis_sync_status' => 'synced', 'chis_reference' => 'CHIS-ENC-000042']);
});

it('stores no clinical text or token in the audit row', function () {
    Http::fake(['chis.test/*' => chisReceiptResponse(201)]);
    $session = chisPending();

    runChisJob($session);

    $stored = json_encode(DB::table('chis_audit_logs')->get());
    foreach (['Tension headache', 'Mild intermittent', 'Rest and hydration', 'Return if it worsens', 'Headache', 'Dela Cruz', 'Juan', 'test-chis-token'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});
