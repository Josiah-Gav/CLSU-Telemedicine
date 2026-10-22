<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates, lists and revokes Sanctum API tokens. Owners are existing active
 * admins only, abilities are explicit (no all-powerful '*' token), and every
 * token expires. The plaintext token is printed once and never logged or stored.
 */
class TelemedApiToken extends Command
{
    public const ABILITIES = ['encounters:read', 'encounters:sync'];

    protected $signature = 'telemed:api-token
        {email? : Email of an existing active admin who will own the token}
        {--name= : A label for the token (default "api")}
        {--ability=* : encounters:read and/or encounters:sync (required)}
        {--days=30 : Days until the token expires (1-365)}
        {--revoke= : Revoke the token with this id}
        {--list : List tokens (never shows the token itself)}';

    protected $description = 'Create, list or revoke Telemed API tokens (admin owners only).';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listTokens();
        }

        if (filled($this->option('revoke'))) {
            return $this->revoke((string) $this->option('revoke'));
        }

        return $this->create();
    }

    private function listTokens(): int
    {
        $tokens = PersonalAccessToken::with('tokenable')->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->line('No API tokens.');
        }

        foreach ($tokens as $t) {
            $this->line(sprintf(
                '#%d  owner=%s  name=%s  abilities=%s  last_used=%s  expires=%s',
                $t->id,
                $t->tokenable?->email ?? '(deleted user)',
                $t->name,
                implode(',', $t->abilities ?? []),
                $t->last_used_at?->toDateTimeString() ?? 'never',
                $t->expires_at?->toDateTimeString() ?? 'never',
            ));
        }

        return self::SUCCESS;
    }

    private function revoke(string $id): int
    {
        $token = ctype_digit($id) ? PersonalAccessToken::find((int) $id) : null;

        if (! $token) {
            $this->error("No token with id {$id}.");

            return self::FAILURE;
        }

        $token->delete();
        $this->info("Token {$id} revoked.");

        return self::SUCCESS;
    }

    private function create(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();

        if (! $user || $user->role !== 'admin' || $user->account_status !== 'active') {
            $this->error('Tokens can only be issued to an existing, active admin account.');

            return self::FAILURE;
        }

        $abilities = array_values(array_unique((array) $this->option('ability')));
        $unknown = array_diff($abilities, self::ABILITIES);

        if ($abilities === [] || $unknown !== []) {
            $this->error('Give at least one explicit --ability from: '.implode(', ', self::ABILITIES).' (no "*" tokens).');

            return self::FAILURE;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > 365) {
            $this->error('--days must be a whole number from 1 to 365.');

            return self::FAILURE;
        }

        $expiresAt = now()->addDays($days);
        $token = $user->createToken($this->option('name') ?: 'api', $abilities, $expiresAt);

        $this->line("Token id: {$token->accessToken->id}");
        $this->line('Abilities: '.implode(', ', $abilities));
        $this->line('Expires: '.$expiresAt->toDateTimeString());
        $this->warn('Store this token now; it cannot be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
