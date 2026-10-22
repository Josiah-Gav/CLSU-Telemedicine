<?php

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use App\Services\ChisClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// phpunit.xml points CHIS at http://chis.test/api/v1 with token test-chis-token.

beforeEach(function () {
    Http::preventStrayRequests();
});

function chisPatient(): array
{
    return [
        'clsu_id' => '23-0001', 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz',
        'sex' => 'M', 'birth_date' => '2004-01-01', 'patient_type' => 'student', 'college' => 'CEN',
        'blood_type' => 'O+', 'medical_profile' => ['allergies' => ['Penicillin'], 'chronic_conditions' => [],
            'current_medications' => [], 'immunizations' => [], 'family_history' => []],
        'record_updated_at' => '2026-10-01T00:00:00Z',
    ];
}

function chisReceipt(): array
{
    return ['chis_encounter_id' => 'ENC-1', 'telemed_encounter_id' => 'TM-1', 'patient_clsu_id' => '23-0001',
        'received_at' => '2026-10-22T00:00:00Z', 'status' => 'received'];
}

function chisError(int $status, string $code): array
{
    return [['message' => 'x', 'code' => $code], $status];
}

/** Run $call and return whatever it threw. */
function chisCatch(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

// --- findPatient ---------------------------------------------------------------

it('returns the patient data on 200', function () {
    Http::fake(['chis.test/*' => Http::response(['data' => chisPatient()])]);

    expect(app(ChisClient::class)->findPatient('23-0001'))->toBe(chisPatient());
});

it('returns null on 404 PATIENT_NOT_FOUND', function () {
    Http::fake(['chis.test/*' => Http::response(...chisError(404, 'PATIENT_NOT_FOUND'))]);

    expect(app(ChisClient::class)->findPatient('23-0001'))->toBeNull();
});

it('classifies findPatient failures as transient or permanent', function (int $status, ?string $code, string $class) {
    Http::fake(['chis.test/*' => Http::response(['message' => 'x', 'code' => $code], $status)]);

    $e = chisCatch(fn () => app(ChisClient::class)->findPatient('23-0001'));

    expect($e)->toBeInstanceOf($class)->and($e->status)->toBe($status);
    if ($e instanceof ChisRequestException) {
        expect($e->chisCode)->toBe($code);
    }
})->with([
    '404 other code' => [404, 'NOT_FOUND', ChisRequestException::class],
    '401' => [401, 'UNAUTHENTICATED', ChisRequestException::class],
    '409' => [409, 'ENCOUNTER_CONFLICT', ChisRequestException::class],
    '422' => [422, 'VALIDATION_FAILED', ChisRequestException::class],
    '429' => [429, 'RATE_LIMITED', ChisUnavailableException::class],
    '500' => [500, 'SERVER_ERROR', ChisUnavailableException::class],
    '503' => [503, 'UNAVAILABLE', ChisUnavailableException::class],
]);

it('URL-encodes the clsu_id and sends the bearer token and Accept header', function (string $clsuId, string $path) {
    Http::fake(['chis.test/*' => Http::response(['data' => chisPatient()])]);

    app(ChisClient::class)->findPatient($clsuId);

    Http::assertSent(fn (Request $r) => $r->url() === "http://chis.test/api/v1/patients/{$path}"
        && $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer test-chis-token')
        && $r->hasHeader('Accept', 'application/json'));
})->with([
    ['1996-0042', '1996-0042'],
    ['23 0001', '23%200001'],
    ['23/0001', '23%2F0001'],
]);

// --- pushEncounter -------------------------------------------------------------

it('returns the receipt on 201 and 200', function (int $status) {
    Http::fake(['chis.test/*' => Http::response(['data' => chisReceipt()], $status)]);

    expect(app(ChisClient::class)->pushEncounter(['telemed_encounter_id' => 'TM-1']))->toBe(chisReceipt());

    Http::assertSent(fn (Request $r) => $r->url() === 'http://chis.test/api/v1/encounters'
        && $r->method() === 'POST'
        && $r['telemed_encounter_id'] === 'TM-1'
        && $r->hasHeader('Authorization', 'Bearer test-chis-token')
        && $r->hasHeader('Accept', 'application/json'));
})->with([201, 200]);

it('classifies pushEncounter failures as transient or permanent, carrying status and code', function (int $status, string $code, string $class) {
    Http::fake(['chis.test/*' => Http::response(['message' => 'x', 'code' => $code], $status)]);

    $e = chisCatch(fn () => app(ChisClient::class)->pushEncounter([]));

    expect($e)->toBeInstanceOf($class)->and($e->status)->toBe($status);
    if ($e instanceof ChisRequestException) {
        expect($e->chisCode)->toBe($code);
    }
})->with([
    '400' => [400, 'BAD_REQUEST', ChisRequestException::class],
    '401' => [401, 'UNAUTHENTICATED', ChisRequestException::class],
    '404' => [404, 'PATIENT_NOT_FOUND', ChisRequestException::class],
    '409' => [409, 'ENCOUNTER_CONFLICT', ChisRequestException::class],
    '415' => [415, 'UNSUPPORTED_MEDIA_TYPE', ChisRequestException::class],
    '422' => [422, 'VALIDATION_FAILED', ChisRequestException::class],
    '429' => [429, 'RATE_LIMITED', ChisUnavailableException::class],
    '500' => [500, 'SERVER_ERROR', ChisUnavailableException::class],
    '503' => [503, 'UNAVAILABLE', ChisUnavailableException::class],
]);

// --- both endpoints: connection failures and malformed bodies -------------------

$calls = [
    'findPatient' => fn () => app(ChisClient::class)->findPatient('23-0001'),
    'pushEncounter' => fn () => app(ChisClient::class)->pushEncounter([]),
];

it('treats a connection error or timeout as transient, without leaking the URL', function (Closure $call, string $message) {
    Http::fake(['chis.test/*' => fn () => throw new ConnectionException("{$message} for http://chis.test/api/v1/patients/23-0001")]);

    $e = chisCatch($call);

    expect($e)->toBeInstanceOf(ChisUnavailableException::class)
        ->and($e->status)->toBeNull()
        ->and($e->getPrevious())->toBeNull()
        ->and($e->getMessage())->not->toContain('23-0001');
})->with($calls)->with(['cURL error 7: Failed to connect', 'cURL error 28: Operation timed out']);

it('treats a malformed non-JSON success body as transient', function (Closure $call) {
    Http::fake(['chis.test/*' => Http::response('<html>Maintenance</html>', 200)]);

    expect(chisCatch($call))->toBeInstanceOf(ChisUnavailableException::class);
})->with($calls);

it('treats a malformed non-JSON 4xx as permanent with no code, and a 5xx as transient', function (Closure $call) {
    Http::fake(['chis.test/*' => Http::sequence()
        ->push('<html>Forbidden</html>', 403)
        ->push('<html>Bad gateway</html>', 502)]);

    $first = chisCatch($call);
    expect($first)->toBeInstanceOf(ChisRequestException::class)
        ->and($first->status)->toBe(403)
        ->and($first->chisCode)->toBeNull();

    expect(chisCatch($call))->toBeInstanceOf(ChisUnavailableException::class);
})->with($calls);

it('never retries on its own', function () {
    Http::fake(['chis.test/*' => Http::response(...chisError(503, 'UNAVAILABLE'))]);

    chisCatch(fn () => app(ChisClient::class)->pushEncounter([]));

    Http::assertSentCount(1);
});

// --- not configured ------------------------------------------------------------

it('throws without sending anything when not configured, and health is false', function (string $key) {
    config(["services.chis.{$key}" => '']);
    Http::fake();

    expect(chisCatch(fn () => app(ChisClient::class)->findPatient('23-0001')))->toBeInstanceOf(ChisUnavailableException::class)
        ->and(chisCatch(fn () => app(ChisClient::class)->pushEncounter([])))->toBeInstanceOf(ChisUnavailableException::class)
        ->and(app(ChisClient::class)->health())->toBeFalse();

    Http::assertNothingSent();
})->with(['token', 'base_url']);

// --- health --------------------------------------------------------------------

it('reports health only on 200 with status ok, and never throws', function ($response, bool $expected) {
    Http::fake(['chis.test/*' => $response]);

    expect(app(ChisClient::class)->health())->toBe($expected);
})->with([
    'ok' => [fn () => Http::response(['status' => 'ok']), true],
    'degraded' => [fn () => Http::response(['status' => 'degraded']), false],
    '503' => [fn () => Http::response(['status' => 'ok'], 503), false],
    'non-JSON' => [fn () => Http::response('ok'), false],
    'connection error' => [fn () => fn () => throw new ConnectionException('down'), false],
]);

// --- privacy -------------------------------------------------------------------

it('never logs or rethrows response bodies, patient data or the token', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $body = ['message' => 'Dela Cruz allergic to Penicillin', 'code' => 'SERVER_ERROR', 'data' => chisPatient()];
    Http::fake(['chis.test/*' => Http::response($body, 500)]);

    $e = chisCatch(fn () => app(ChisClient::class)->findPatient('23-0001'));

    $everything = implode("\n", $logged)."\n".$e->getMessage();

    expect($logged)->not->toBeEmpty();
    foreach (['Dela Cruz', 'Penicillin', 'Juan', '23-0001', '2004-01-01', 'test-chis-token'] as $secret) {
        expect($everything)->not->toContain($secret);
    }
});

// --- chis:health ---------------------------------------------------------------

it('chis:health exits 0 when healthy, never printing the token', function () {
    Http::fake(['chis.test/*' => Http::response(['status' => 'ok'])]);

    $this->artisan('chis:health')
        ->expectsOutputToContain('http://chis.test/api/v1')
        ->doesntExpectOutputToContain('test-chis-token')
        ->assertExitCode(0);
});

it('chis:health exits 1 when CHIS is down, never printing the token', function () {
    Http::fake(['chis.test/*' => Http::response(...chisError(503, 'UNAVAILABLE'))]);

    $this->artisan('chis:health')
        ->expectsOutputToContain('NOT reachable')
        ->doesntExpectOutputToContain('test-chis-token')
        ->assertExitCode(1);
});
