<?php

namespace App\Console\Commands;

use App\Services\ChisClient;
use Illuminate\Console\Command;

class ChisHealth extends Command
{
    protected $signature = 'chis:health';

    protected $description = 'Check that CHIS is reachable at the configured base URL (exit 0 if healthy, 1 if not).';

    public function handle(ChisClient $chis): int
    {
        // Never print the token; only whether it is set.
        $baseUrl = config('services.chis.base_url') ?: '(CHIS_BASE_URL not set)';
        $this->line("Base URL: {$baseUrl}");
        $this->line('Token: '.(filled(config('services.chis.token')) ? 'set' : 'NOT SET (CHIS_TOKEN)'));

        if ($chis->health()) {
            $this->info('CHIS is reachable and healthy.');

            return self::SUCCESS;
        }

        $this->error('CHIS is NOT reachable or not healthy.');

        return self::FAILURE;
    }
}
