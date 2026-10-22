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
