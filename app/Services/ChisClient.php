<?php

namespace App\Services;

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the CHIS REST API (config: services.chis).
 *
 * Failures are split by the retry rule: timeouts, connection errors, 429 and
 * 5xx are transient (ChisUnavailableException); every other 4xx is permanent
 * (ChisRequestException). The client never retries itself — the caller owns
 * that.
 *
 * Privacy (RA 10173): request/response bodies, the token and patient data are
 * never logged or put in an exception message. Logs carry only the endpoint
 * name, HTTP status and exception class.
 */
class ChisClient
{
    /**
     * True only on 200 with status "ok". Never throws.
     */
    public function health(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            // /health is the one endpoint that takes no token.
            $response = $this->http()->get('/health');
        } catch (ConnectionException $e) {
            $this->logFailure('health', null, $e::class);

            return false;
        }

        return $response->status() === 200 && $response->json('status') === 'ok';
    }

    /**
     * The patient's CHIS record, or null when CHIS does not know the ID.
     *
     * @throws ChisUnavailableException
     * @throws ChisRequestException
     */
    public function findPatient(string $clsuId): ?array
    {
        $response = $this->send('findPatient', fn (PendingRequest $http) => $http->get('/patients/'.rawurlencode($clsuId)));

        if ($response->status() === 404 && $this->errorCode($response) === 'PATIENT_NOT_FOUND') {
            return null;
        }

        return $this->data('findPatient', $response);
    }

    /**
     * Push one encounter; returns the CHIS receipt (201 new, 200 identical resend).
     *
     * @throws ChisUnavailableException
     * @throws ChisRequestException
     */
    public function pushEncounter(array $payload): array
    {
        return $this->data('pushEncounter', $this->send('pushEncounter', fn (PendingRequest $http) => $http->post('/encounters', $payload)));
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(string $endpoint, Closure $call): Response
    {
        if (! $this->configured()) {
            // No request is sent at all.
            throw new ChisUnavailableException("CHIS {$endpoint}: not configured (CHIS_BASE_URL and CHIS_TOKEN must both be set).");
        }

        try {
            $response = $call($this->http()->withToken(config('services.chis.token')));
        } catch (ConnectionException $e) {
            // Not chained: its message contains the URL, which carries the CLSU ID.
            $this->logFailure($endpoint, null, ChisUnavailableException::class);

            throw new ChisUnavailableException("CHIS {$endpoint}: connection failed or timed out.");
        }

        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            $this->logFailure($endpoint, $status, ChisUnavailableException::class);

            throw new ChisUnavailableException("CHIS {$endpoint}: temporarily unavailable (HTTP {$status}).", $status);
        }

        return $response;
    }

    /**
     * The `data` object of a 2xx response; any other status is permanent.
     * An unreadable success body is treated as transient: it usually means a
     * proxy or maintenance page, and an encounter resend is idempotent in CHIS.
     */
    private function data(string $endpoint, Response $response): array
    {
        if (! $response->successful()) {
            $status = $response->status();
            $code = $this->errorCode($response);
            $this->logFailure($endpoint, $status, ChisRequestException::class);

            throw new ChisRequestException("CHIS {$endpoint}: request rejected (HTTP {$status}".($code ? ", {$code}" : '').').', $status, $code);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            $this->logFailure($endpoint, $response->status(), ChisUnavailableException::class);

            throw new ChisUnavailableException("CHIS {$endpoint}: unreadable response body (HTTP {$response->status()}).", $response->status());
        }

        return $data;
    }

    /**
     * The CHIS error code, only if it looks like one (e.g. ENCOUNTER_CONFLICT),
     * so a malformed body can never smuggle text into a message or log.
     */
    private function errorCode(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $code) ? $code : null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.chis.base_url'))
            ->acceptJson()
            ->timeout((int) config('services.chis.timeout', 5))
            ->connectTimeout((int) config('services.chis.connect_timeout', 2));
    }

    private function configured(): bool
    {
        return filled(config('services.chis.base_url')) && filled(config('services.chis.token'));
    }

    private function logFailure(string $endpoint, ?int $status, string $exceptionClass): void
    {
        Log::warning('CHIS request failed.', [
            'endpoint' => $endpoint,
            'status' => $status,
            'exception' => $exceptionClass,
        ]);
    }
}
