<?php

use App\Models\ChisAuditLog;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use App\Services\ChisIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::preventStrayRequests();
});

/** An active session for a patient "Juan Dela Cruz" (23-0001); the patient has never consented. */
function chisViewSession(array $patient = [], array $session = []): ConsultationSession
{
    $patientUser = User::factory()->create(array_merge(['role' => 'patient', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'clsu_id' => '23-0001'], $patient));
    $physician = User::factory()->create(['role' => 'physician']);

    $request = Consultation::forceCreate([
        'patient_id' => $patientUser->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'initial',
        'concern_category' => 'Headache', 'symptoms_desc' => [], 'online_reason' => 'x', 'request_status' => 'active',
    ]);

    return ConsultationSession::create(array_merge([
        'request_id' => $request->request_id, 'physician_id' => $physician->user_id, 'consultation_status' => 'active',
        'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r',
    ], $session));
}

function chisRecord(array $override = []): array
{
    return array_merge([
        'clsu_id' => '23-0001', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
        'sex' => 'M', 'birth_date' => '2004-01-01', 'patient_type' => 'student', 'college' => 'CEN', 'blood_type' => 'O+',
        'medical_profile' => [
            'allergies' => [['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'severe', 'internal_note' => 'secret']],
            'chronic_conditions' => [['name' => 'Asthma', 'diagnosed_on' => '2015-03-01', 'status' => 'active']],
            'current_medications' => [['name' => 'Salbutamol', 'dose' => '100mcg', 'frequency' => 'PRN']],
            'immunizations' => [['vaccine' => 'Hepatitis B', 'dose' => '3', 'date_administered' => '2010-05-01']],
            'family_history' => [['relation' => 'Father', 'condition' => 'Hypertension']],
        ],
        'record_updated_at' => '2026-10-01T00:00:00Z',
    ], $override);
}

function fakeChis($response): void
{
    Http::fake(['chis.test/api/v1/patients/*' => $response]);
}

function viewChis(ConsultationSession $session, ?User $as = null)
{
    return test()->actingAs($as ?? User::find($session->physician_id))->getJson(route('consultations.chis_profile.show', $session));
}

// --- access --------------------------------------------------------------------

it('lets the assigned physician view the profile', function () {
    fakeChis(Http::response(['data' => chisRecord()]));

    viewChis(chisViewSession())->assertOk()->assertJsonPath('state', 'ok');
});

it('forbids everyone else, without any CHIS call or audit row', function (string $who) {
    $session = chisViewSession();
    $user = match ($who) {
        'other physician' => User::factory()->create(['role' => 'physician']),
        'patient' => User::find($session->request->patient_id),
        'nurse' => User::factory()->create(['role' => 'nurse']),
        'admin' => User::factory()->create(['role' => 'admin']),
    };
    Http::fake();

    viewChis($session, $user)->assertForbidden();

    Http::assertNothingSent();
    expect(ChisAuditLog::count())->toBe(0);
})->with(['other physician', 'patient', 'nurse', 'admin']);

it('forbids a session that is not active or completed', function () {
    $session = chisViewSession(session: ['consultation_status' => 'scheduled']);
    Http::fake();

    viewChis($session)->assertForbidden();

    Http::assertNothingSent();
    expect(ChisAuditLog::count())->toBe(0);
});

it('makes no call and writes no audit row without a CLSU ID', function () {
    Http::fake();

    viewChis(chisViewSession(['clsu_id' => null]))->assertOk()->assertExactJson(['state' => 'no_clsu_id']);

    Http::assertNothingSent();
    expect(ChisAuditLog::count())->toBe(0);
});

it('looks up a patient who never consented', function () {
    fakeChis(Http::response(['data' => chisRecord()]));
    $session = chisViewSession();
    expect($session->request->patient->chis_consent_at)->toBeNull();

    viewChis($session)->assertOk()->assertJsonPath('state', 'ok');

    expect(ChisAuditLog::sole()->outcome)->toBe('success');
});

// --- ok ------------------------------------------------------------------------

it('returns only the minimised profile, uncached, with one audit row', function () {
    fakeChis(Http::response(['data' => chisRecord()]));
    $session = chisViewSession();

    $response = viewChis($session)->assertOk();
    $profile = $response->json('profile');

    expect(array_keys($profile))->toEqualCanonicalizing(['blood_type', 'sex', 'birth_date', 'record_updated_at', 'medical_profile'])
        ->and(array_keys($profile['medical_profile']))->toEqualCanonicalizing(['allergies', 'chronic_conditions', 'current_medications', 'immunizations', 'family_history'])
        ->and(array_keys($profile['medical_profile']['allergies'][0]))->toBe(['substance', 'reaction', 'severity']);

    $json = $response->getContent();
    foreach (['middle_name', 'Santos', 'college', 'CEN', 'secret', 'first_name'] as $absent) {
        expect($json)->not->toContain($absent);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $row = ChisAuditLog::sole();
    expect($row->only(['action', 'source', 'outcome', 'actor_user_id', 'patient_user_id', 'consultation_session_id', 'clsu_id', 'http_status']))->toBe([
        'action' => 'patient_lookup', 'source' => 'consultation_view', 'outcome' => 'success', 'actor_user_id' => $session->physician_id,
        'patient_user_id' => $session->request->patient_id, 'consultation_session_id' => $session->id, 'clsu_id' => '23-0001', 'http_status' => 200,
    ])->and($row->duration_ms)->toBeInt();
});

// --- other outcomes ------------------------------------------------------------

it('maps lookup failures to a state and an audit outcome', function ($response, string $state, string $outcome, ?int $status) {
    fakeChis($response);

    viewChis(chisViewSession())->assertOk()->assertJsonPath('state', $state)->assertJsonMissingPath('profile');

    expect(ChisAuditLog::sole()->only(['outcome', 'http_status']))->toBe(['outcome' => $outcome, 'http_status' => $status]);
})->with([
    'not found' => [fn () => Http::response(['message' => 'x', 'code' => 'PATIENT_NOT_FOUND'], 404), 'not_found', 'not_found', 404],
    'unavailable' => [fn () => Http::response(['message' => 'x', 'code' => 'UNAVAILABLE'], 503), 'unavailable', 'unavailable', 503],
    'rejected' => [fn () => Http::response(['message' => 'x', 'code' => 'UNAUTHENTICATED'], 401), 'error', 'rejected', 401],
]);

it('returns no data and no CHIS name on an identity mismatch', function () {
    fakeChis(Http::response(['data' => chisRecord(['first_name' => 'Maria', 'last_name' => 'Santos'])]));

    $response = viewChis(chisViewSession())->assertOk()->assertExactJson(['state' => 'identity_mismatch']);

    foreach (['Maria', 'Santos', 'Penicillin', 'blood_type'] as $leak) {
        expect($response->getContent())->not->toContain($leak);
    }
    expect(ChisAuditLog::sole()->outcome)->toBe('identity_mismatch');
});

it('fails closed: no profile when the audit row cannot be written', function () {
    fakeChis(Http::response(['data' => chisRecord()]));
    $session = chisViewSession();
    Schema::drop('chis_audit_logs');

    $response = viewChis($session)->assertOk()->assertExactJson(['state' => 'unavailable']);

    expect($response->getContent())->not->toContain('Penicillin');
});

it('persists nothing from the CHIS response, cache table included', function () {
    fakeChis(Http::response(['data' => chisRecord()]));

    viewChis(chisViewSession())->assertOk()->assertJsonPath('state', 'ok');

    foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
        $dump = json_encode(DB::table($table->name)->get());
        foreach (['Penicillin', 'Salbutamol', 'Hepatitis B', 'Hypertension'] as $medical) {
            expect($dump)->not->toContain($medical);
        }
    }
});

// --- page ----------------------------------------------------------------------

it('replaces the placeholder tab for the physician', function () {
    $session = chisViewSession();

    $this->actingAs(User::find($session->physician_id))->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee('Viewing this record is logged.')
        ->assertSee(substr(json_encode(route('consultations.chis_profile.show', $session)), 1, -1), false)
        ->assertDontSee('Hospital Information System')
        ->assertDontSee('BMI')
        ->assertDontSee('Emergency Contact')
        ->assertDontSee('Past Injuries');
});

it('shows the patient only a privacy note, with no profile endpoint', function () {
    $session = chisViewSession();

    $this->actingAs(User::find($session->request->patient_id))->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee('visible to your assigned physician only')
        ->assertSee('How your records are shared with CHIS')
        ->assertDontSee('Manage consent')
        ->assertDontSee('chis-profile', false)
        ->assertDontSee('Hospital Information System');
});

// --- identity ------------------------------------------------------------------

it('matches names ignoring case, accents, punctuation and spacing', function (string $first, string $last, string $chisFirst, string $chisLast, bool $expected) {
    $patient = new User(['first_name' => $first, 'last_name' => $last]);

    expect(ChisIdentity::matches($patient, ['first_name' => $chisFirst, 'last_name' => $chisLast]))->toBe($expected);
})->with([
    'same' => ['Juan', 'Dela Cruz', 'Juan', 'Dela Cruz', true],
    'case' => ['JUAN', 'dela cruz', 'juan', 'DELA CRUZ', true],
    'accents' => ['José', 'Peña', 'Jose', 'Pena', true],
    'extra spaces' => ['  Juan ', 'Dela   Cruz', 'Juan', 'Dela Cruz', true],
    'punctuation' => ['Juan', 'Dela Cruz', 'Juan.', 'dela-cruz', true],
    'apostrophe' => ['Ma. Lourdes', "O'Brien", 'Ma Lourdes', 'OBrien', true],
    'different first name' => ['Juan', 'Dela Cruz', 'Jose', 'Dela Cruz', false],
    'different last name' => ['Juan', 'Dela Cruz', 'Juan', 'Santos', false],
    'empty names never match' => ['', '', '', '', false],
    'missing keys' => ['Juan', 'Dela Cruz', '', '', false],
]);
