<?php

namespace App\Console\Commands;

use App\Services\PhysicianAvailabilityService;
use Illuminate\Console\Command;

/**
 * Closes physician intake sessions whose heartbeat has gone quiet.
 *
 * Deliberately thin: the staleness rule and the write both live in
 * PhysicianAvailabilityService::expireStaleSessions(), which stays the single
 * writer of physician_availability_sessions. Duplicating the threshold here
 * would let the janitor and the read path disagree about what "stale" means.
 *
 * This command is a tidier, not the authority. The service already treats a
 * stale open session as unavailable at read time, so intake is correctly closed
 * to new requests the moment the heartbeat stops — whether or not this command
 * has run yet. What it adds is an honest stored status, so the physician's own
 * page and any later reporting show 'expired' rather than a session that looks
 * open but is not.
 *
 * Safe to run repeatedly: expireStaleSessions() matches only rows still marked
 * 'open', so a second run in the same minute finds nothing left to do.
 */
class ExpireStaleIntakeSessions extends Command
{
    protected $signature = 'consultations:expire-intake-sessions';

    protected $description = 'Expire physician consultation intake sessions whose heartbeat has gone stale.';

    public function handle(PhysicianAvailabilityService $availabilityService): int
    {
        $expiredCount = $availabilityService->expireStaleSessions();

        $this->info('Expired '.$expiredCount.' stale intake session(s).');

        return self::SUCCESS;
    }
}
