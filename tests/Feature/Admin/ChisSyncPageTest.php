<?php

use App\Jobs\SyncEncounterToChis;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use App\Services\ChisEncounterSync;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('chis.health');
});

function chisHealthOk(): void
{
    Http::fake(['chis.test/api/v1/health' => Http::response(['status' => 'ok'])]);
}

function chisSyncAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

/** A completed session with the given CHIS sync state; clinical text is distinctive so leaks are detectable. */
function chisSyncRow(string $status, array $extra = []): ConsultationSession
{
    $patient = User::factory()->create(['role' => 'patient', 'first_name' => 'Maria', 'last_name' => 'Santos']);
    $physician = User::factory()->create(['role' => 'physician', 'first_name' => 'Ana', 'last_name' => 'Reyes']);

    $request = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Respiratory',
        'symptoms_desc' => [['name' => 'SecretSymptomCough', 'severity' => 2]],
        'online_reason' => 'x',
        'request_status' => 'completed',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $request->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'diagnosis' => 'SecretDiagnosisBronchitis',
        'assessment' => 'SecretAssessmentWheeze',
        'plan' => 'SecretPlanInhaler',
        'recommendations' => 'SecretRecommendationRest',
        'completed_at' => now(),
    ]);
    $session->forceFill(['chis_sync_status' => $status, ...$extra])->save();

    return $session;
}

// --- page ----------------------------------------------------------------------

it('shows admins the sync rows, counts and plain-language errors, but no clinical data', function () {
    chisHealthOk();
    chisSyncRow('synced', ['chis_reference' => 'ENC-2026-000777', 'chis_attempts' => 1]);
    chisSyncRow('failed', ['chis_last_error' => 'http_409:ENCOUNTER_CONFLICT', 'chis_attempts' => 1]);
    chisSyncRow('skipped', ['chis_last_error' => 'no_consent']);
    chisSyncRow('pending', ['chis_last_error' => 'some_new_code']);

    $response = $this->actingAs(chisSyncAdmin())->get(route('admin.chis-sync.index'))->assertOk();

    $response->assertSee('CHIS reachable')
        ->assertSee('ENC-2026-000777')
        ->assertSee('CHIS already holds different content for this consultation')
        ->assertSee('http_409:ENCOUNTER_CONFLICT')
        ->assertSee('Patient has not consented')
        ->assertSee('some_new_code')
        ->assertSee('Maria Santos');

    expect($response->viewData('counts')->all())->toEqual(['failed' => 1, 'pending' => 1, 'skipped' => 1, 'synced' => 1]);

    foreach (['SecretDiagnosisBronchitis', 'SecretAssessmentWheeze', 'SecretPlanInhaler', 'SecretRecommendationRest', 'SecretSymptomCough', 'Respiratory'] as $clinical) {
        $response->assertDontSee($clinical);
    }
});

it('filters by status', function () {
    chisHealthOk();
    $synced = chisSyncRow('synced', ['chis_reference' => 'ENC-SYNCED']);
    $failed = chisSyncRow('failed', ['chis_last_error' => 'connection_failed']);

    $response = $this->actingAs(chisSyncAdmin())->get(route('admin.chis-sync.index', ['status' => 'failed']))->assertOk();

    expect($response->viewData('sessions')->pluck('id')->all())->toBe([$failed->id]);
    $response->assertDontSee('ENC-SYNCED')->assertSee('CHIS could not be reached');
});

it('omits sessions that are not part of CHIS sync', function () {
    chisHealthOk();
    $row = chisSyncRow('synced');
    $legacy = chisSyncRow('synced');
    ConsultationSession::whereKey($legacy->id)->update(['chis_sync_status' => null]);

    $ids = $this->actingAs(chisSyncAdmin())->get(route('admin.chis-sync.index'))->viewData('sessions')->pluck('id')->all();

    expect($ids)->toBe([$row->id]);
});

it('shows CHIS as not reachable when health fails, and caches the result', function () {
    Http::fake(['chis.test/api/v1/health' => Http::response(['status' => 'down'], 503)]);

    $admin = chisSyncAdmin();
    $this->actingAs($admin)->get(route('admin.chis-sync.index'))->assertSee('CHIS not reachable');
    $this->actingAs($admin)->get(route('admin.chis-sync.index'))->assertSee('CHIS not reachable');

    Http::assertSentCount(1);
});

it('forbids non-admins and redirects guests', function () {
    foreach (['nurse', 'physician', 'patient'] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get(route('admin.chis-sync.index'))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('admin.chis-sync.index'))->assertRedirect(route('login'));
});

// --- retry ---------------------------------------------------------------------

it('retries a failed or pending sync: back to pending, error cleared, job dispatched', function (string $from) {
    Queue::fake();
    $session = chisSyncRow($from, ['chis_last_error' => 'connection_failed', 'chis_attempts' => 3]);

    $this->actingAs(chisSyncAdmin())
        ->from(route('admin.chis-sync.index'))
        ->post(route('admin.chis-sync.retry', $session))
        ->assertRedirect(route('admin.chis-sync.index'))
        ->assertSessionHas('status');

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error', 'chis_attempts']))
        ->toBe(['chis_sync_status' => 'pending', 'chis_last_error' => null, 'chis_attempts' => 3]);
    Queue::assertPushed(SyncEncounterToChis::class, fn ($job) => $job->sessionId === $session->id);
})->with(['failed', 'pending']);

it('never retries a synced or skipped session', function (string $from, ?string $error) {
    Queue::fake();
    $session = chisSyncRow($from, ['chis_last_error' => $error, 'chis_reference' => $from === 'synced' ? 'ENC-1' : null]);

    $this->actingAs(chisSyncAdmin())
        ->from(route('admin.chis-sync.index'))
        ->post(route('admin.chis-sync.retry', $session))
        ->assertRedirect(route('admin.chis-sync.index'))
        ->assertSessionHas('warning');

    expect($session->refresh()->only(['chis_sync_status', 'chis_last_error']))
        ->toBe(['chis_sync_status' => $from, 'chis_last_error' => $error]);
    Queue::assertNotPushed(SyncEncounterToChis::class);
})->with([['synced', null], ['skipped', 'no_consent']]);

it('forbids retry for non-admins', function (string $role) {
    Queue::fake();
    $session = chisSyncRow('failed', ['chis_last_error' => 'connection_failed']);

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->post(route('admin.chis-sync.retry', $session))
        ->assertForbidden();

    expect($session->refresh()->chis_sync_status)->toBe('failed');
    Queue::assertNotPushed(SyncEncounterToChis::class);
})->with(['nurse', 'physician', 'patient']);

// --- error descriptions ----------------------------------------------------------

it('describes known error codes and leaves unknown ones raw', function (?string $code, ?string $text) {
    expect(ChisEncounterSync::describeError($code))->toBe($text);
})->with([
    [null, null],
    ['no_consent', 'Patient has not consented'],
    ['no_clsu_id', 'Patient has no CLSU ID'],
    ['connection_failed', 'CHIS could not be reached'],
    ['http_409:ENCOUNTER_CONFLICT', 'CHIS already holds different content for this consultation'],
    ['http_422:VALIDATION_FAILED', 'CHIS rejected the summary as invalid'],
    ['invalid_payload:no_valid_symptoms', 'Summary is incomplete or invalid'],
    ['http_503', 'CHIS temporarily unavailable'],
    ['http_500:SERVER_ERROR', 'CHIS temporarily unavailable'],
    ['http_429', 'CHIS temporarily unavailable'],
    ['http_418:TEAPOT', null],
]);

it('says so, instead of claiming a retry, when an attempt is already waiting', function () {
    Queue::fake();
    $session = chisSyncRow('pending', ['chis_last_error' => 'connection_failed']);
    (new UniqueLock(Cache::store()))->acquire(new SyncEncounterToChis($session->id));

    $this->actingAs(chisSyncAdmin())
        ->from(route('admin.chis-sync.index'))
        ->post(route('admin.chis-sync.retry', $session))
        ->assertSessionHas('status', fn ($message) => str_contains($message, 'already has a sync attempt waiting'));

    expect($session->refresh()->chis_sync_status)->toBe('pending');
    Queue::assertNotPushed(SyncEncounterToChis::class);
});
