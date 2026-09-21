<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * The Sanctum token principal for an external system integration —
 * currently only the simulated CHIS demo client. This exists so a machine
 * credential (issued via `php artisan chis:issue-token`) never has to be a
 * `users` row: CHIS is a system, not a CLSU account holder, and giving it
 * its own tokenable model keeps it out of the admin user-management list
 * and off the users table's role/account_status surface entirely.
 *
 * Implements Authenticatable by hand (rather than extending the framework's
 * User base class, as App\Models\User does) because this model never logs
 * in with a password — it is only ever resolved from a Sanctum token.
 */
class ChisIntegrationClient extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    protected $fillable = [
        'name',
    ];
}
