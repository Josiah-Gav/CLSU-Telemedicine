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

// Email a reminder once a booked consultation falls inside its 24-hour
// window. Every 15 minutes is frequent enough for a once-per-booking email —
// schedule_slots.reminder_sent_at is the de-duplication guard, not the run
// cadence, so a shorter interval would not change how many reminders go out.
Schedule::command('consultations:send-reminders')
    ->everyFifteenMinutes()
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
