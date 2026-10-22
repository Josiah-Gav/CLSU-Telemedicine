<?php

namespace App\Console\Commands;

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use App\Services\ChisClient;
use Illuminate\Console\Command;

class ChisHealth extends Command
{
    protected $signature = 'chis:health {--patient= : Also look up this CLSU ID (checks the token and the CHIS database, which /health cannot)}';

    protected $description = 'Check that CHIS is reachable at the configured base URL (exit 0 if healthy, 1 if not).';

    public function handle(ChisClient $chis): int
    {
        // Never print the token; only whether it is set.
        $baseUrl = config('services.chis.base_url') ?: '(CHIS_BASE_URL not set)';
        $this->line("Base URL: {$baseUrl}");
        $this->line('Token: '.(filled(config('services.chis.token')) ? 'set' : 'NOT SET (CHIS_TOKEN)'));

        if (! $chis->health()) {
            $this->error('CHIS is NOT reachable or not healthy.');

            return self::FAILURE;
        }

        $this->info('CHIS is reachable and healthy.');

        $clsuId = $this->option('patient');

        if (! filled($clsuId)) {
            return self::SUCCESS;
        }

        // Only the name is ever printed; never medical data.
        try {
            $patient = $chis->findPatient($clsuId);
        } catch (ChisUnavailableException $e) {
            $this->error("Patient {$clsuId}: error ChisUnavailableException ({$e->reason})");

            return self::FAILURE;
        } catch (ChisRequestException $e) {
            $this->error("Patient {$clsuId}: error ChisRequestException (HTTP {$e->status}".($e->chisCode ? ", {$e->chisCode}" : '').')');

            return self::FAILURE;
        }

        $this->line($patient === null
            ? "Patient {$clsuId}: not found"
            : "Patient {$clsuId}: found (".trim(($patient['first_name'] ?? '').' '.($patient['last_name'] ?? '')).')');

        return self::SUCCESS;
    }
}
