<?php

namespace App\Services;

use App\Jobs\SyncEncounterToChis;
use App\Models\ConsultationSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues a completed consultation's summary for CHIS. Completion only queues:
 * a CHIS outage must never make completion fail or slow down.
 *
 * chis_sync_status: pending (queued / retrying after a transient error),
 * synced, failed (permanent, needs a human), skipped (not eligible),
 * NULL (not applicable).
 */
class ChisEncounterSync
{
    /**
     * Why this session must not be sent to CHIS, or null when it may be.
     * The single eligibility rule, checked at completion and again by the job
     * at send time (consent can be withdrawn in between).
     */
    public static function ineligibilityReason(ConsultationSession $session): ?string
    {
        $patient = $session->request?->patient;

        return match (true) {
            ! $patient?->hasChisConsent() => 'no_consent',
            blank($patient->clsu_id) => 'no_clsu_id',
            default => null,
        };
    }

    /**
     * Plain-language meaning of a chis_last_error code, for the admin sync
     * page. The one mapping; an unknown code returns null (show the raw code).
     */
    public static function describeError(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match (true) {
            $code === 'no_consent' => 'Patient has not consented',
            $code === 'no_clsu_id' => 'Patient has no CLSU ID',
            $code === 'connection_failed' => 'CHIS could not be reached',
            $code === 'not_configured' => 'CHIS is not configured in Telemed',
            $code === 'invalid_response' => 'CHIS sent an unreadable response',
            $code === 'http_409:ENCOUNTER_CONFLICT' => 'CHIS already holds different content for this consultation',
            $code === 'http_404:PATIENT_NOT_FOUND' => 'CHIS has no record of this CLSU ID',
            str_starts_with($code, 'http_401') => 'CHIS rejected Telemed\'s access token',
            str_starts_with($code, 'http_422') => 'CHIS rejected the summary as invalid',
            str_starts_with($code, 'invalid_payload:') => 'Summary is incomplete or invalid',
            str_starts_with($code, 'http_429') || (bool) preg_match('/^http_5\d\d/', $code) => 'CHIS temporarily unavailable',
            default => null,
        };
    }

    /**
     * Call only after the completion transaction has committed.
     */
    public static function queue(ConsultationSession $session): void
    {
        $reason = self::ineligibilityReason($session);

        // Conditional on NULL, so re-completion or two concurrent completions
        // can never queue the same session twice.
        $claimed = ConsultationSession::whereKey($session->id)
            ->whereNull('chis_sync_status')
            ->update([
                'chis_sync_status' => $reason ? 'skipped' : 'pending',
                'chis_last_error' => $reason,
            ]);

        if (! $claimed || $reason) {
            return;
        }

        try {
            SyncEncounterToChis::dispatch($session->id);
        } catch (Throwable $e) {
            // Only reachable with a synchronous queue driver (or a broken
            // queue). The row stays pending, so chis:sync-pending retries it.
            Log::warning('CHIS encounter sync could not be queued.', [
                'session_id' => $session->id,
                'exception' => $e::class,
            ]);
        }
    }
}
