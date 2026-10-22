<?php

namespace App\Console\Commands;

use App\Jobs\SyncEncounterToChis;
use App\Models\ConsultationSession;
use Illuminate\Console\Command;

class ChisSyncPending extends Command
{
    protected $signature = 'chis:sync-pending';

    protected $description = 'Re-queue CHIS encounter syncs still pending after 10 minutes (e.g. after an outage outlasted the job retries).';

    public function handle(): int
    {
        $ids = ConsultationSession::query()
            ->where('chis_sync_status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->pluck('id');

        // ShouldBeUnique drops a dispatch for a session whose job is still
        // queued or waiting out its backoff, so in-flight ones are not doubled.
        foreach ($ids as $id) {
            SyncEncounterToChis::dispatch($id);
        }

        $this->info("Queued {$ids->count()} pending CHIS sync(s).");

        return self::SUCCESS;
    }
}
