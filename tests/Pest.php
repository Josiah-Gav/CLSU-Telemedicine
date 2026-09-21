<?php

use App\Models\ChisIntegrationClient;
use App\Models\PhysicianAvailabilitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Put the telemedicine service into a state where a patient may submit a NEW
 * consultation request, and return the physician holding intake open.
 *
 * ConsultationController::store() refuses a new request unless
 * PhysicianAvailabilityService::isServiceAvailable() is true, which needs an
 * eligible physician who is present, whose presence is fresh, and who has a
 * fresh open intake session. Any suite that posts to consultations.store needs
 * that state; this builds the real rows rather than faking the service, so the
 * gate is exercised exactly as it is in production.
 *
 * Lives here rather than in one test file because four existing suites plus the
 * gate's own suite all need it — this is the shared-helper location tests/Pest.php
 * documents above.
 *
 * users.last_seen_at is written through the query builder because it is not in
 * User::$fillable, which is also why TrackUserPresence and PresenceController
 * write it that way.
 */
function makeConsultationIntakeAvailable(): User
{
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'account_status' => 'active',
        'online_status' => 'online',
    ]);

    DB::table('users')
        ->where('user_id', $physician->user_id)
        ->update(['last_seen_at' => now()]);

    PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'overtime',
    ]);

    return $physician->refresh();
}

/**
 * Issue a Sanctum token for a fresh ChisIntegrationClient, for the two
 * tests/Feature/Api/Chis*Test.php suites. Shared here rather than declared
 * in either test file because both need it, and a top-level function
 * declared in two included test files fatals with "cannot redeclare".
 */
function issueChisToken(array $abilities = ['chis:read-encounters', 'chis:read-patients']): string
{
    $client = ChisIntegrationClient::create(['name' => 'Test CHIS Client']);

    return $client->createToken('test', $abilities)->plainTextToken;
}
