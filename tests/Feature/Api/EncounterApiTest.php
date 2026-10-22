<?php

use App\Jobs\SyncEncounterToChis;
use App\Models\ChisAuditLog;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use App\Services\ChisClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function apiAdmin(array $attrs = []): User
{
    return User::factory()->create(array_merge(['role' => 'admin', 'account_status' => 'active'], $attrs));
}

function apiToken(?User $owner = null, array $abilities = ['encounters:read', 'encounters:sync']): string
{
    return ($owner ?? apiAdmin())->createToken('test', $abilities)->plainTextToken;
}

function apiCall(string $method, string $uri, string $token, array $data = [])
{
    return test()->withToken($token)->json($method, "/api/v1{$uri}", $data);
}

/** A completed session with the given CHIS sync status. Clinical text is distinctive so leaks are detectable. */
function apiSession(string $status = 'pending', array $patient = []): ConsultationSession
{
    static $n = 0;
    $n++;

    $patientUser = User::factory()->create(array_merge([
        'role' => 'patient', 'first_name' => 'Zebediah', 'last_name' => 'Quillfeather', 'clsu_id' => sprintf('23-%04d', $n),
    ], $patient));
    $physician = User::factory()->create(['role' => 'physician', 'first_name' => 'Ana', 'last_name' => 'Reyes']);

    $request = Consultation::forceCreate([
        'patient_id' => $patientUser->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'initial',
        'concern_category' => 'Headache', 'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'x', 'request_status' => 'completed',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $request->request_id, 'physician_id' => $physician->user_id, 'consultation_status' => 'completed',
        'diagnosis' => 'SecretDiagnosisMigraine', 'assessment' => 'SecretAssessment', 'plan' => 'SecretPlan',
        'recommendations' => 'SecretRecommendation', 'started_at' => '2026-10-20 10:00:00', 'completed_at' => '2026-10-20 10:30:00',
    ]);
    $session->forceFill(['chis_sync_status' => $status])->save();

    return $session;
}

function apiReceipt(int $status = 201)
{
    return Http::response(['data' => ['chis_encounter_id' => 'ENC-2026-000042', 'status' => 'received']], $status);
}

// --- auth ----------------------------------------------------------------------

it('answers 401 JSON without a valid token', function () {
    $this->getJson('/api/v1/encounters')->assertUnauthorized()->assertExactJson([
        'message' => 'Missing, invalid, revoked or expired API token.', 'code' => 'UNAUTHENTICATED',
    ]);
    $this->withToken('999|not-a-real-token')->getJson('/api/v1/encounters')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('gives a signed-in web admin no access without a token', function () {
    $this->actingAs(apiAdmin())->getJson('/api/v1/encounters')->assertUnauthorized();
});

it('rejects a revoked token', function () {
    $admin = apiAdmin();
    $token = $admin->createToken('t', ['encounters:read']);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/encounters')->assertOk();

    $token->accessToken->delete();
    app('auth')->forgetGuards();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/encounters')->assertUnauthorized();
});

it('rejects an expired token', function () {
    $token = apiAdmin()->createToken('t', ['encounters:read'], now()->addDays(30))->plainTextToken;

    $this->travel(31)->days();

    $this->withToken($token)->getJson('/api/v1/encounters')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('answers 403 FORBIDDEN when the owner is not an active admin, or the ability is missing', function (array $owner, array $abilities) {
    $admin = apiAdmin();
    $token = $admin->createToken('t', $abilities)->plainTextToken;
    $admin->forceFill($owner)->save();

    $this->withToken($token)->getJson('/api/v1/encounters')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'demoted admin' => [['role' => 'physician'], ['encounters:read']],
    'suspended admin' => [['account_status' => 'suspended'], ['encounters:read']],
    'missing ability' => [[], ['encounters:sync']],
]);

it('keeps web error pages as HTML', function () {
    $this->get('/definitely-not-a-page')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $this->get('/api/v1/nope')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('rate limits with a JSON 429', function () {
    $token = apiToken();

    foreach (range(1, 60) as $i) {
        apiCall('GET', '/encounters', $token)->assertOk();
    }

    apiCall('GET', '/encounters', $token)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
});

// --- list / get ----------------------------------------------------------------

it('lists sync status with no names, CLSU IDs or clinical text', function () {
    $session = apiSession('failed');
    $session->forceFill(['chis_last_error' => 'http_409:ENCOUNTER_CONFLICT', 'chis_attempts' => 2, 'chis_reference' => 'ENC-1'])->save();
    $clsuId = $session->request->patient->clsu_id;

    $response = apiCall('GET', '/encounters', apiToken())->assertOk();

    expect($response->json('data.0'))->toBe([
        'telemed_encounter_id' => $session->id,
        'completed_at' => '2026-10-20T10:30:00+08:00',
        'chis_sync_status' => 'failed',
        'chis_reference' => 'ENC-1',
        'chis_attempts' => 2,
        'chis_last_error' => 'http_409:ENCOUNTER_CONFLICT',
        'chis_last_error_text' => 'CHIS already holds different content for this consultation',
    ])->and($response->json('meta.total'))->toBe(1);

    foreach (['Zebediah', 'Quillfeather', $clsuId, 'SecretDiagnosisMigraine', 'SecretAssessment', 'Headache'] as $leak) {
        expect($response->getContent())->not->toContain($leak);
    }
});

it('filters by status and omits sessions with no sync status', function () {
    $failed = apiSession('failed');
    apiSession('synced');
    $none = apiSession('synced');
    ConsultationSession::whereKey($none->id)->update(['chis_sync_status' => null]);

    $all = apiCall('GET', '/encounters', apiToken())->json('data');
    $filtered = apiCall('GET', '/encounters?status=failed', apiToken())->json('data');

    expect($all)->toHaveCount(2)
        ->and(array_column($filtered, 'telemed_encounter_id'))->toBe([$failed->id]);
});

it('gets one session, and 404s one with no sync status', function () {
    $session = apiSession('synced');
    $none = apiSession('synced');
    ConsultationSession::whereKey($none->id)->update(['chis_sync_status' => null]);
    $token = apiToken();

    apiCall('GET', "/encounters/{$session->id}", $token)->assertOk()->assertJsonPath('data.telemed_encounter_id', $session->id);
    apiCall('GET', "/encounters/{$none->id}", $token)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    apiCall('GET', '/encounters/999999', $token)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

// --- sync ----------------------------------------------------------------------

it('syncs synchronously: 201 first, then 200 with the same reference', function () {
    Http::fakeSequence('chis.test/*')->push(['data' => ['chis_encounter_id' => 'ENC-2026-000042']], 201)->push(['data' => ['chis_encounter_id' => 'ENC-2026-000042']], 200);
    $session = apiSession('pending');
    $token = apiToken();

    $first = apiCall('POST', "/encounters/{$session->id}/sync", $token)->assertOk();
    expect($first->json('data'))->toMatchArray(['chis_sync_status' => 'synced', 'chis_reference' => 'ENC-2026-000042', 'chis_http_status' => 201, 'chis_last_error' => null]);

    $again = apiCall('POST', "/encounters/{$session->id}/sync", $token)->assertOk();
    expect($again->json('data'))->toMatchArray(['chis_sync_status' => 'synced', 'chis_reference' => 'ENC-2026-000042', 'chis_http_status' => 200, 'chis_attempts' => 2]);
});

it('returns 200 with pending and an error text when CHIS is unavailable', function () {
    Http::fake(['chis.test/*' => Http::response(['message' => 'x', 'code' => 'UNAVAILABLE'], 503)]);
    $session = apiSession('pending');

    $response = apiCall('POST', "/encounters/{$session->id}/sync", apiToken())->assertOk();

    expect($response->json('data'))->toMatchArray([
        'chis_sync_status' => 'pending', 'chis_last_error' => 'http_503',
        'chis_last_error_text' => 'CHIS temporarily unavailable', 'chis_http_status' => 503,
    ]);
});

it('records a CHIS rejection as failed with its text', function () {
    Http::fake(['chis.test/*' => Http::response(['message' => 'x', 'code' => 'VALIDATION_FAILED'], 422)]);
    $session = apiSession('failed');

    $response = apiCall('POST', "/encounters/{$session->id}/sync", apiToken())->assertOk();

    expect($response->json('data'))->toMatchArray([
        'chis_sync_status' => 'failed', 'chis_last_error' => 'http_422:VALIDATION_FAILED',
        'chis_last_error_text' => 'CHIS rejected the summary as invalid', 'chis_http_status' => 422,
    ]);
});

it('refuses a skipped session with 409 NOT_ELIGIBLE and no CHIS call', function () {
    Http::fake();
    $session = apiSession('skipped');
    $session->forceFill(['chis_last_error' => 'no_clsu_id'])->save();

    apiCall('POST', "/encounters/{$session->id}/sync", apiToken())
        ->assertStatus(409)->assertJsonPath('code', 'NOT_ELIGIBLE')->assertJsonPath('reason', 'no_clsu_id');

    Http::assertNothingSent();
    expect($session->refresh()->chis_sync_status)->toBe('skipped');
});

it('refuses with 409 SYNC_IN_PROGRESS while a queued attempt holds the lock', function () {
    Http::fake();
    $session = apiSession('pending');
    (new UniqueLock(Cache::store()))->acquire(new SyncEncounterToChis($session->id));

    apiCall('POST', "/encounters/{$session->id}/sync", apiToken())->assertStatus(409)->assertJsonPath('code', 'SYNC_IN_PROGRESS');

    Http::assertNothingSent();
});

it('needs the sync ability and a 404-free session', function () {
    Http::fake();
    $session = apiSession('pending');

    apiCall('POST', "/encounters/{$session->id}/sync", apiToken(null, ['encounters:read']))->assertForbidden();
    apiCall('POST', '/encounters/999999/sync', apiToken())->assertNotFound();

    Http::assertNothingSent();
});

it('ignores another session\'s audit row when reporting chis_http_status', function () {
    $other = apiSession('synced');
    $session = apiSession('pending');

    // A queued job for a different session writes its own audit row mid-call.
    Http::fake(['chis.test/*' => function () use ($other) {
        ChisAuditLog::record('encounter_push', 'unavailable', 'sync_job', ['consultation_session_id' => $other->id, 'http_status' => 503]);

        return apiReceipt(201);
    }]);

    $response = apiCall('POST', "/encounters/{$session->id}/sync", apiToken())->assertOk();

    expect($response->json('data.chis_http_status'))->toBe(201);
});

// --- audit ---------------------------------------------------------------------

it('attributes an API sync to the token owner with source api, and queued syncs to the job', function () {
    Http::fake(['chis.test/*' => apiReceipt(201)]);
    $owner = apiAdmin();
    $session = apiSession('pending');

    apiCall('POST', "/encounters/{$session->id}/sync", apiToken($owner))->assertOk();

    $row = ChisAuditLog::sole();
    expect($row->only(['action', 'source', 'actor_user_id', 'consultation_session_id', 'outcome', 'http_status']))->toBe([
        'action' => 'encounter_push', 'source' => 'api', 'actor_user_id' => $owner->user_id,
        'consultation_session_id' => $session->id, 'outcome' => 'success', 'http_status' => 201,
    ]);

    $queued = apiSession('pending');
    (new SyncEncounterToChis($queued->id))->handle(app(ChisClient::class));

    $last = ChisAuditLog::latest('id')->first();
    expect($last->source)->toBe('sync_job')->and($last->actor_user_id)->toBeNull();
});
