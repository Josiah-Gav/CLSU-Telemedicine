<?php

use App\Models\ChisAuditLog;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('chis.health');
});

function auditAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

// --- model ---------------------------------------------------------------------

it('is append-only: updates and deletes throw and change nothing', function () {
    ChisAuditLog::record('encounter_push', 'success', 'sync_job', ['chis_reference' => 'ENC-1']);
    $log = ChisAuditLog::first();

    expect(fn () => $log->update(['outcome' => 'rejected']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);

    expect(ChisAuditLog::count())->toBe(1)
        ->and(ChisAuditLog::first()->outcome)->toBe('success');
});

it('records only whitelisted context keys', function () {
    ChisAuditLog::record('patient_lookup', 'success', 'health_command', [
        'clsu_id' => '23-0001', 'diagnosis' => 'Should never be stored', 'token' => 'nope',
    ]);

    $stored = json_encode(DB::table('chis_audit_logs')->first());

    expect($stored)->toContain('23-0001')
        ->not->toContain('Should never be stored')
        ->not->toContain('nope');
});

// --- chis:health --patient ------------------------------------------------------

it('audits a chis:health --patient lookup, and not a plain health check', function () {
    $patient = User::factory()->create(['role' => 'patient', 'clsu_id' => '23-0001']);
    Http::fake([
        'chis.test/api/v1/health' => Http::response(['status' => 'ok']),
        'chis.test/api/v1/patients/23-0001' => Http::response(['data' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz']]),
        'chis.test/api/v1/patients/99-0000' => Http::response(['message' => 'x', 'code' => 'PATIENT_NOT_FOUND'], 404),
    ]);

    $this->artisan('chis:health')->assertExitCode(0);
    expect(ChisAuditLog::count())->toBe(0);

    $this->artisan('chis:health', ['--patient' => '23-0001'])->assertExitCode(0);
    $this->artisan('chis:health', ['--patient' => '99-0000'])->assertExitCode(0);

    expect(ChisAuditLog::orderBy('id')->get()->map->only(['action', 'source', 'outcome', 'clsu_id', 'patient_user_id', 'actor_user_id', 'http_status'])->all())
        ->toBe([
            ['action' => 'patient_lookup', 'source' => 'health_command', 'outcome' => 'success', 'clsu_id' => '23-0001', 'patient_user_id' => $patient->user_id, 'actor_user_id' => null, 'http_status' => 200],
            ['action' => 'patient_lookup', 'source' => 'health_command', 'outcome' => 'not_found', 'clsu_id' => '99-0000', 'patient_user_id' => null, 'actor_user_id' => null, 'http_status' => 404],
        ]);
});

it('audits a failed chis:health --patient lookup', function () {
    Http::fake([
        'chis.test/api/v1/health' => Http::response(['status' => 'ok']),
        'chis.test/api/v1/patients/*' => Http::response(['message' => 'x', 'code' => 'UNAUTHENTICATED'], 401),
    ]);

    $this->artisan('chis:health', ['--patient' => '23-0001'])->assertExitCode(1);

    expect(ChisAuditLog::first()->only(['outcome', 'http_status', 'reason']))
        ->toBe(['outcome' => 'rejected', 'http_status' => 401, 'reason' => 'http_401:UNAUTHENTICATED']);
});

// --- admin page ----------------------------------------------------------------

function seedAuditRows(): array
{
    $patient = User::factory()->create(['role' => 'patient', 'first_name' => 'Maria', 'last_name' => 'Santos', 'clsu_id' => '23-0005']);
    $physician = User::factory()->create(['role' => 'physician', 'first_name' => 'Ana', 'last_name' => 'Reyes']);

    ChisAuditLog::record('encounter_push', 'success', 'sync_job', ['patient_user_id' => $patient->user_id, 'consultation_session_id' => 7, 'clsu_id' => '23-0005', 'http_status' => 201, 'chis_reference' => 'ENC-2026-000777', 'duration_ms' => 42]);
    ChisAuditLog::record('encounter_push', 'rejected', 'sync_job', ['patient_user_id' => $patient->user_id, 'consultation_session_id' => 8, 'clsu_id' => '23-0005', 'http_status' => 409, 'reason' => 'http_409:ENCOUNTER_CONFLICT']);
    ChisAuditLog::record('patient_lookup', 'not_found', 'consultation_view', ['actor_user_id' => $physician->user_id, 'clsu_id' => '23-0009', 'http_status' => 404]);

    return [$patient, $physician];
}

it('shows admins the audit rows in plain language', function () {
    seedAuditRows();

    $this->actingAs(auditAdmin())->get(route('admin.chis-audit.index'))
        ->assertOk()
        ->assertSee('Summary sent')
        ->assertSee('Patient record viewed')
        ->assertSee('Maria Santos')
        ->assertSee('System')
        ->assertSee('Ana Reyes')
        ->assertSee('ENC-2026-000777')
        ->assertSee('42 ms')
        ->assertSee('CHIS already holds different content for this consultation')
        ->assertSee('http_409:ENCOUNTER_CONFLICT');
});

it('filters by action, outcome, CLSU ID and consultation', function (array $query, int $expected) {
    seedAuditRows();

    $logs = $this->actingAs(auditAdmin())->get(route('admin.chis-audit.index', $query))->assertOk()->viewData('logs');

    expect($logs->total())->toBe($expected);
})->with([
    'none' => [[], 3],
    'action' => [['action' => 'patient_lookup'], 1],
    'outcome' => [['outcome' => 'rejected'], 1],
    'clsu_id exact' => [['clsu_id' => '23-0005'], 2],
    'clsu_id partial does not match' => [['clsu_id' => '23-000'], 0],
    'session' => [['session' => 8], 1],
    'invalid values ignored' => [['action' => 'drop', 'outcome' => 'x', 'session' => 'abc'], 3],
    'array values ignored' => [['action' => ['patient_lookup']], 3],
]);

it('forbids non-admins and redirects guests', function () {
    foreach (['nurse', 'physician', 'patient'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('admin.chis-audit.index'))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('admin.chis-audit.index'))->assertRedirect(route('login'));
});

it('links each CHIS sync row to its audit history', function () {
    Http::fake(['chis.test/api/v1/health' => Http::response(['status' => 'ok'])]);
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician']);
    $request = Consultation::forceCreate(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'initial', 'concern_category' => 'x', 'symptoms_desc' => [], 'online_reason' => 'x', 'request_status' => 'completed']);
    $session = ConsultationSession::create(['request_id' => $request->request_id, 'physician_id' => $physician->user_id, 'consultation_status' => 'completed', 'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r', 'completed_at' => now()]);
    $session->forceFill(['chis_sync_status' => 'synced'])->save();

    $this->actingAs(auditAdmin())->get(route('admin.chis-sync.index'))
        ->assertOk()
        ->assertSee(route('admin.chis-audit.index', ['session' => $session->id]), false)
        ->assertSee('Audit log');
});

it('labels a patient\'s profile view apart from their consultation view', function () {
    $patient = User::factory()->create(['role' => 'patient', 'clsu_id' => '23-0006']);
    ChisAuditLog::record('patient_lookup', 'success', 'patient_self_view', ['actor_user_id' => $patient->user_id, 'patient_user_id' => $patient->user_id, 'consultation_session_id' => 9, 'clsu_id' => '23-0006', 'http_status' => 200]);
    ChisAuditLog::record('patient_lookup', 'success', 'profile_view', ['actor_user_id' => $patient->user_id, 'patient_user_id' => $patient->user_id, 'clsu_id' => '23-0006', 'http_status' => 200]);

    $this->actingAs(auditAdmin())->get(route('admin.chis-audit.index'))
        ->assertOk()
        ->assertSee('Patient view (consultation)')
        ->assertSee('Patient view (profile)')
        ->assertDontSee('profile_view');
});
