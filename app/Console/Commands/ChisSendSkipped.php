<?php

namespace App\Console\Commands;

use App\Jobs\SyncEncounterToChis;
use App\Models\ConsultationSession;
use Illuminate\Console\Command;

class ChisSendSkipped extends Command
{
    protected $signature = 'chis:send-skipped {--confirm : Actually requeue them; without it this is a dry run}';

    protected $description = 'One-time: send consultations skipped under the earlier consent rule (skipped / no_consent) to CHIS.';

    public function handle(): int
    {
        $ids = ConsultationSession::query()
            ->where('chis_sync_status', 'skipped')
            ->where('chis_last_error', 'no_consent')
            ->orderBy('id')
            ->pluck('id');

        $this->info("Found {$ids->count()} consultation(s) skipped for no consent.");

        if ($ids->isNotEmpty()) {
            $this->line('Session ids: '.$ids->implode(', '));
        }

        if (! $this->option('confirm')) {
            $this->warn('Dry run: nothing changed. Re-run with --confirm to queue them.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($ids as $id) {
            // Conditional, so a row that changed since the list was read is left alone.
            $claimed = ConsultationSession::whereKey($id)
                ->where('chis_sync_status', 'skipped')
                ->where('chis_last_error', 'no_consent')
                ->update(['chis_sync_status' => 'pending', 'chis_last_error' => null]);

            if ($claimed) {
                SyncEncounterToChis::dispatch($id);
                $queued++;
            }
        }

        $this->info("Queued {$queued} CHIS sync(s).");

        return self::SUCCESS;
    }
}
