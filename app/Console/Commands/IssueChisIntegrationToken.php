<?php

namespace App\Console\Commands;

use App\Models\ChisIntegrationClient;
use Illuminate\Console\Command;

/**
 * Issues (or reissues) the Sanctum token used to demo the simulated CHIS
 * integration in Postman. The plaintext token only ever exists in this
 * command's console output — it is never stored or logged, the same rule
 * the staff-invitation token follows (see StaffAccountInvitation).
 *
 * Re-running this for the same --name revokes that client's previous
 * tokens first, so at most one token is ever valid per named client —
 * mirrors how staff invitation tokens are re-issued.
 */
class IssueChisIntegrationToken extends Command
{
    protected $signature = 'chis:issue-token {--name=CHIS Demo Client}';

    protected $description = 'Issue a Sanctum token for the simulated CHIS integration demo (Postman).';

    public function handle(): int
    {
        $name = (string) $this->option('name');

        $client = ChisIntegrationClient::query()->firstOrCreate(['name' => $name]);
        $client->tokens()->delete();

        $token = $client->createToken(
            name: 'postman-demo',
            abilities: ['chis:read-encounters', 'chis:read-patients'],
        );

        $this->info("Token for \"{$name}\" (use as a Bearer token in Postman):");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
