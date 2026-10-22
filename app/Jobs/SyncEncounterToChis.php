<?php

namespace App\Jobs;

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use App\Exceptions\Chis\InvalidChisPayloadException;
use App\Models\ChisAuditLog;
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

    /**
     * Ids and strings only: the queued payload must never hold clinical data.
     * $actorUserId/$source attribute the audit row (the API passes the token
     * owner and 'api'; the queued/sweeper path keeps the defaults).
     */
    public function __construct(
        public int $sessionId,
        public ?int $actorUserId = null,
        public string $source = 'sync_job',
    ) {}

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
        // Returns [payload, patient user id], or null when there is nothing to send.
        $prepared = DB::transaction(function (): ?array {
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

            return [$payload, $session->request->patient_id];
        });

        if ($prepared === null) {
            return; // No call made, so no audit row: the session status shows why.
        }

        [$payload, $patientUserId] = $prepared;

        // Exactly one audit row per real call; duration measured up to the moment it is written.
        $start = hrtime(true);
        $audit = fn (string $outcome, array $context) => ChisAuditLog::record('encounter_push', $outcome, $this->source, [
            'actor_user_id' => $this->actorUserId,
            'patient_user_id' => $patientUserId,
            'consultation_session_id' => $this->sessionId,
            'clsu_id' => $payload['patient']['clsu_id'],
            'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000),
            ...$context,
        ]);

        try {
            $result = $chis->pushEncounter($payload);
        } catch (ChisRequestException $e) {
            // Permanent: record for a human, never retry.
            $reason = 'http_'.$e->status.($e->chisCode ? ':'.$e->chisCode : '');
            $audit('rejected', ['http_status' => $e->status, 'reason' => $reason]);
            $this->record('failed', $reason);

            return;
        } catch (ChisUnavailableException $e) {
            // Transient: stay pending and let the queue retry.
            $audit('unavailable', ['http_status' => $e->status, 'reason' => $e->reason]);
            $this->record('pending', $e->reason);

            throw $e;
        }

        $reference = isset($result['receipt']['chis_encounter_id']) ? (string) $result['receipt']['chis_encounter_id'] : null;
        $audit('success', ['chis_reference' => $reference, 'http_status' => $result['status']]);
        $this->record('synced', null, [
            'chis_reference' => $reference,
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
