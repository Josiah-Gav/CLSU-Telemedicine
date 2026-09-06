<?php

use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
| Phase 1 of physician consultation intake availability: schema, models and
| configuration only. Nothing here exercises HTTP behaviour, because nothing
| yet reads either table — the gate, the heartbeat and the expiry command all
| arrive in later phases.
|
| The enum assertions matter more than they look. The project's two
| ALTER ... MODIFY COLUMN enum migrations return early on SQLite (see
| CLAUDE.md), so enum values added by a later ALTER are enforced in MySQL but
| absent from the test schema. These tables declare every value in the CREATE
| precisely to avoid that, and the round-trip tests below are what prove it on
| the SQLite test driver.
*/

/**
 * Test files in this suite each declare their own uniquely-named factory
 * helper (makeVideoConsultationSession, makePresenceScenario, and so on).
 * ConsultationConcurrencyTest already declares a global makePhysician(), so
 * this one is named distinctly to avoid a redeclaration fatal when both files
 * load in the same run.
 */
function makeIntakePhysician(): User
{
    return User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);
}

it('creates the physician_schedules table with the expected columns', function () {
    expect(Schema::hasTable('physician_schedules'))->toBeTrue()
        ->and(Schema::hasColumns('physician_schedules', [
            'id',
            'physician_id',
            'day_of_week',
            'start_time',
            'end_time',
            'is_active',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('creates the physician_availability_sessions table with the expected columns', function () {
    expect(Schema::hasTable('physician_availability_sessions'))->toBeTrue()
        ->and(Schema::hasColumns('physician_availability_sessions', [
            'id',
            'physician_id',
            'started_at',
            'last_seen_at',
            'ended_at',
            'status',
            'mode',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('defaults a schedule window to active', function () {
    $physician = makeIntakePhysician();

    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '14:00:00',
        'end_time' => '17:00:00',
    ]);

    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('allows several schedule windows on the same day for one physician', function () {
    $physician = makeIntakePhysician();

    // The Monday morning-clinic / evening-clinic case. This is why the unique
    // key includes start_time rather than stopping at (physician_id, day_of_week).
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '14:00:00',
        'end_time' => '17:00:00',
    ]);

    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '19:00:00',
        'end_time' => '21:00:00',
    ]);

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(2);
});

it('rejects two schedule windows starting at the same time on the same day', function () {
    $physician = makeIntakePhysician();

    $window = [
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '14:00:00',
        'end_time' => '17:00:00',
    ];

    PhysicianSchedule::create($window);

    expect(fn () => PhysicianSchedule::create($window))->toThrow(QueryException::class);
});

it('lets two physicians hold the same weekday and start time', function () {
    $first = makeIntakePhysician();
    $second = makeIntakePhysician();

    foreach ([$first, $second] as $physician) {
        PhysicianSchedule::create([
            'physician_id' => $physician->user_id,
            'day_of_week' => 1,
            'start_time' => '14:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    expect(PhysicianSchedule::count())->toBe(2);
});

it('stores every availability session status value', function () {
    $physician = makeIntakePhysician();

    foreach (['open', 'closed', 'expired'] as $status) {
        $session = PhysicianAvailabilitySession::create([
            'physician_id' => $physician->user_id,
            'started_at' => now(),
            'last_seen_at' => now(),
            'ended_at' => $status === 'open' ? null : now(),
            'status' => $status,
            'mode' => 'scheduled',
        ]);

        expect($session->fresh()->status)->toBe($status);
    }

    expect(PhysicianAvailabilitySession::count())->toBe(3);
});

it('stores both availability session mode values', function () {
    $physician = makeIntakePhysician();

    foreach (['scheduled', 'overtime'] as $mode) {
        $session = PhysicianAvailabilitySession::create([
            'physician_id' => $physician->user_id,
            'started_at' => now(),
            'last_seen_at' => now(),
            'status' => 'open',
            'mode' => $mode,
        ]);

        expect($session->fresh()->mode)->toBe($mode);
    }
});

it('defaults a new availability session to open with a null ended_at', function () {
    $physician = makeIntakePhysician();

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'mode' => 'overtime',
    ])->fresh();

    expect($session->status)->toBe('open')
        ->and($session->ended_at)->toBeNull();
});

it('casts the availability session timestamps to dates', function () {
    $physician = makeIntakePhysician();

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'ended_at' => now(),
        'status' => 'closed',
        'mode' => 'scheduled',
    ])->fresh();

    expect($session->started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($session->last_seen_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($session->ended_at)->toBeInstanceOf(CarbonInterface::class);
});

it('resolves both models back to the physician through users.user_id', function () {
    $physician = makeIntakePhysician();

    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 2,
        'start_time' => '14:00:00',
        'end_time' => '17:00:00',
    ]);

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'scheduled',
    ]);

    expect($schedule->physician->user_id)->toBe($physician->user_id)
        ->and($session->physician->user_id)->toBe($physician->user_id);
});

it('removes schedules and availability sessions along with the physician', function () {
    // Proves the foreign keys really do point at users.user_id — a key
    // pointing anywhere else could not cascade.
    $physician = makeIntakePhysician();

    PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 3,
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
    ]);

    PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'overtime',
    ]);

    $physician->delete();

    expect(PhysicianSchedule::count())->toBe(0)
        ->and(PhysicianAvailabilitySession::count())->toBe(0);
});

it('refuses a schedule or session for a physician who does not exist', function () {
    expect(fn () => PhysicianSchedule::create([
        'physician_id' => 999999,
        'day_of_week' => 1,
        'start_time' => '14:00:00',
        'end_time' => '17:00:00',
    ]))->toThrow(QueryException::class);

    expect(fn () => PhysicianAvailabilitySession::create([
        'physician_id' => 999999,
        'started_at' => now(),
        'last_seen_at' => now(),
        'status' => 'open',
        'mode' => 'scheduled',
    ]))->toThrow(QueryException::class);
});

it('exposes the intake configuration with its documented defaults', function () {
    expect(config('consultations.intake.queue_limit'))->toBe(20)
        ->and(config('consultations.intake.stale_after_seconds'))->toBe(120);
});

it('exposes only the intake configuration keys', function () {
    expect(array_keys(config('consultations')))->toBe(['intake'])
        ->and(array_keys(config('consultations.intake')))
        ->toBe(['queue_limit', 'stale_after_seconds']);
});

it('reads the intake configuration through config so it can be overridden', function () {
    config()->set('consultations.intake.queue_limit', 5);

    expect(config('consultations.intake.queue_limit'))->toBe(5);
});
