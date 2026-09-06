<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('consultations:mark-missed-slots')
    ->everyMinute()
    ->withoutOverlapping();

// Mark physician intake sessions expired once their heartbeat has gone stale.
// Every minute so a stored status is never far behind reality; the gate itself
// does not depend on this running, because PhysicianAvailabilityService already
// treats a stale open session as unavailable when it reads one.
Schedule::command('consultations:expire-intake-sessions')
    ->everyMinute()
    ->withoutOverlapping();

// Flush staff invitation tokens that have passed their 7-day expiry, so dead
// credential material does not accumulate. Laravel's own command, named
// explicitly so it targets the staff_invitations broker and never touches
// password_reset_tokens. deleteExpired() removes rows where
// created_at < now() - expire, which is the same boundary tokenExpired() uses,
// so it can only ever delete a token that is already unusable.
Schedule::command('auth:clear-resets staff_invitations')
    ->daily()
    ->withoutOverlapping();
