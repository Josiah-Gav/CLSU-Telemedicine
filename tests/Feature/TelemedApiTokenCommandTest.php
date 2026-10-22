<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

function tokenAdmin(array $attrs = []): User
{
    return User::factory()->create(array_merge(['role' => 'admin', 'account_status' => 'active'], $attrs));
}

it('issues a token to an active admin, printing it once', function () {
    $admin = tokenAdmin();

    $this->artisan('telemed:api-token', ['email' => $admin->email, '--ability' => ['encounters:read'], '--name' => 'demo'])
        ->expectsOutputToContain('cannot be shown again')
        ->assertExitCode(0);

    $token = PersonalAccessToken::sole();
    expect($token->name)->toBe('demo')
        ->and($token->abilities)->toBe(['encounters:read'])
        ->and($token->tokenable_id)->toBe($admin->user_id)
        ->and($token->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();
});

it('refuses non-admins, inactive admins and unknown users', function (array $attrs, ?string $email) {
    $user = User::factory()->create($attrs);

    $this->artisan('telemed:api-token', ['email' => $email ?? $user->email, '--ability' => ['encounters:read']])->assertExitCode(1);

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'physician' => [['role' => 'physician'], null],
    'patient' => [['role' => 'patient'], null],
    'suspended admin' => [['role' => 'admin', 'account_status' => 'suspended'], null],
    'unknown email' => [['role' => 'admin'], 'nobody@clsu.edu.ph'],
]);

it('refuses missing, wildcard and unknown abilities', function (array $abilities) {
    $admin = tokenAdmin();

    $this->artisan('telemed:api-token', ['email' => $admin->email, '--ability' => $abilities])->assertExitCode(1);

    expect(PersonalAccessToken::count())->toBe(0);
})->with([[[]], [['*']], [['encounters:delete']], [['encounters:read', 'users:write']]]);

it('honours --days within 1 to 365 only', function (string $days, bool $ok) {
    $admin = tokenAdmin();

    $this->artisan('telemed:api-token', ['email' => $admin->email, '--ability' => ['encounters:sync'], '--days' => $days])
        ->assertExitCode($ok ? 0 : 1);

    expect(PersonalAccessToken::count())->toBe($ok ? 1 : 0);
    if ($ok) {
        expect(PersonalAccessToken::sole()->expires_at->isSameDay(now()->addDays((int) $days)))->toBeTrue();
    }
})->with([['7', true], ['365', true], ['0', false], ['366', false], ['abc', false]]);

it('lists tokens with expiry but never the token', function () {
    $admin = tokenAdmin();
    $plain = $admin->createToken('listed', ['encounters:read'], now()->addDays(10))->plainTextToken;
    [, $secret] = explode('|', $plain, 2);

    expect(Artisan::call('telemed:api-token', ['--list' => true]))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('listed')->toContain('encounters:read')->toContain($admin->email)->toContain('expires=')
        ->not->toContain($secret);
});

it('revokes a token by id, after which it gets 401', function () {
    $admin = tokenAdmin();
    $token = $admin->createToken('t', ['encounters:read']);

    $this->artisan('telemed:api-token', ['--revoke' => (string) $token->accessToken->id])->assertExitCode(0);
    $this->artisan('telemed:api-token', ['--revoke' => '9999'])->assertExitCode(1);

    expect(PersonalAccessToken::count())->toBe(0);
    $this->withToken($token->plainTextToken)->getJson('/api/v1/encounters')->assertUnauthorized();
});
