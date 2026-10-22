<?php

namespace App\Jobs;

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use App\Exceptions\Chis\InvalidChisPayloadException;
use App\Models\ConsultationSession;
use App\Services\ChisClient;
use App\Services\ChisEncounterPayload;
use App\Services\ChisEncounterSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes one completed consultation's summary to CHIS.
 *
 * Carries ONLY the session id: QUEUE_CONNECTION=database serializes the job
 * into jobs.payload, so clinical data must never be a property here. The
 * payload is rebuilt from the database at send time.
 */
class SyncEncounterToChis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Expires a lock left by a crashed worker; longer than the full backoff (~81 min). */
    public int $uniqueFor = 7200;

    public function __construct(public int $sessionId) {}

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ChisClient $chis): void
    {
        // Decide and build under the row lock; never hold it across HTTP.
        $payload = DB::transaction(function (): ?array {
            $session = ConsultationSession::whereKey($this->sessionId)->lockForUpdate()->first();

            if (! $session || $session->chis_sync_status !== 'pending') {
                return null; // synced, failed, skipped or gone: nothing to do.
            }

            if ($reason = ChisEncounterSync::ineligibilityReason($session)) {
                $session->forceFill(['chis_sync_status' => 'skipped', 'chis_last_error' => $reason])->save();

                return null;
            }

            try {
                $payload = ChisEncounterPayload::build($session);
            } catch (InvalidChisPayloadException $e) {
                $session->forceFill(['chis_sync_status' => 'failed', 'chis_last_error' => 'invalid_payload:'.$e->getMessage()])->save();

                return null;
            }

            $session->forceFill(['chis_attempts' => $session->chis_attempts + 1])->save();

            return $payload;
        });

        if ($payload === null) {
            return;
        }

        try {
            $receipt = $chis->pushEncounter($payload);
        } catch (ChisRequestException $e) {
            // Permanent: record for a human, never retry.
            $this->record('failed', 'http_'.$e->status.($e->chisCode ? ':'.$e->chisCode : ''));

            return;
        } catch (ChisUnavailableException $e) {
            // Transient: stay pending and let the queue retry.
            $this->record('pending', $e->reason);

            throw $e;
        }

        $this->record('synced', null, [
            'chis_reference' => isset($receipt['chis_encounter_id']) ? (string) $receipt['chis_encounter_id'] : null,
            'chis_synced_at' => now(),
        ]);
    }

    /**
     * Tries exhausted. The row stays pending on purpose: a transient outage
     * must never become a permanent failure; chis:sync-pending keeps trying.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('CHIS encounter sync gave up for now; left pending for the sweeper.', [
            'session_id' => $this->sessionId,
            'exception' => $exception::class,
        ]);
    }

    private function record(string $status, ?string $error, array $extra = []): void
    {
        ConsultationSession::whereKey($this->sessionId)->update([
            'chis_sync_status' => $status,
            'chis_last_error' => $error,
            ...$extra,
        ]);
    }
}
