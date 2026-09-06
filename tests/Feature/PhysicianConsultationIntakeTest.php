<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\PhysicianAvailabilitySession;
use App\Models\PhysicianSchedule;
use App\Models\ScheduleSlot;
use App\Models\User;

/*
| Phase 3: physician-facing CRUD for the recurring intake schedule
| (physician_schedules) only. No availability session is ever opened here —
| PhysicianAvailabilityService::open()/close()/touch() are never called by
| this controller, so every isolation assertion below is really checking
| that this phase introduced no accidental call into that service.
*/

/**
 * Named distinctly from the other test files' physician factory helpers
 * (makePhysician, makeIntakePhysician, makeAvailabilityPhysician) to avoid a
 * redeclaration fatal when the suite loads every file in one run.
 */
function makeScheduleManagingPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ], $overrides));
}

function intakeRoute(string $name, User $physician, array $extra = []): string
{
    return route($name, array_merge(['physician' => $physician->user_id], $extra));
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('lets a physician view their own consultation intake page', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->get(intakeRoute('physician.consultation_intake', $physician))
        ->assertOk()
        ->assertSee('Recurring Intake Schedule');
});

it('refuses a non-physician access to the consultation intake page', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $this->actingAs($nurse)
        ->get(intakeRoute('physician.consultation_intake', $nurse))
        ->assertForbidden();
});

it('refuses one physician viewing another physician\'s consultation intake page', function () {
    $owner = makeScheduleManagingPhysician();
    $intruder = makeScheduleManagingPhysician();

    $this->actingAs($intruder)
        ->get(intakeRoute('physician.consultation_intake', $owner))
        ->assertForbidden();
});

it('refuses a physician updating another physician\'s schedule', function () {
    $owner = makeScheduleManagingPhysician();
    $intruder = makeScheduleManagingPhysician();

    $schedule = PhysicianSchedule::create([
        'physician_id' => $owner->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    // Hitting the intruder's own authorized URL, but naming the owner's
    // schedule id — the payload-tampering case, not just a wrong route param.
    $this->actingAs($intruder)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $intruder, ['schedule' => $schedule->id]), [
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])
        ->assertNotFound();

    expect($schedule->fresh()->day_of_week)->toBe(1);
});

it('refuses a physician deleting another physician\'s schedule', function () {
    $owner = makeScheduleManagingPhysician();
    $intruder = makeScheduleManagingPhysician();

    $schedule = PhysicianSchedule::create([
        'physician_id' => $owner->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    $this->actingAs($intruder)
        ->deleteJson(intakeRoute('physician.consultation_intake.schedules.destroy', $intruder, ['schedule' => $schedule->id]))
        ->assertNotFound();

    expect(PhysicianSchedule::find($schedule->id))->not->toBeNull();
});

it('refuses updating another physician\'s route entirely, regardless of the schedule id supplied', function () {
    $owner = makeScheduleManagingPhysician();
    $intruder = makeScheduleManagingPhysician();

    $schedule = PhysicianSchedule::create([
        'physician_id' => $owner->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
    ]);

    // The intruder tries the owner's URL directly with their own auth session.
    $this->actingAs($intruder)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $owner, ['schedule' => $schedule->id]), [
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('lets a physician create a recurring schedule window', function () {
    $physician = makeScheduleManagingPhysician();

    $response = $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
        ->assertCreated();

    expect($response->json('success'))->toBeTrue()
        ->and(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(1);
});

it('assigns a new schedule to the authenticated physician, never a submitted physician_id', function () {
    $physician = makeScheduleManagingPhysician();
    $otherPhysician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'physician_id' => $otherPhysician->user_id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
        ->assertCreated();

    $schedule = PhysicianSchedule::first();

    expect((int) $schedule->physician_id)->toBe($physician->user_id);
});

it('defaults a newly created schedule to active', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
        ->assertCreated();

    expect(PhysicianSchedule::first()->is_active)->toBeTrue();
});

it('accepts valid day and time values at every weekday boundary', function (int $day) {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => $day,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
        ->assertCreated();
})->with([0, 6]);

it('allows creating multiple windows on the same day', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
        'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    ])->assertCreated();

    $this->actingAs($physician)->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
        'day_of_week' => 1, 'start_time' => '14:00', 'end_time' => '17:00',
    ])->assertCreated();

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(2);
});

it('allows two different physicians to hold the same weekday and time', function () {
    $first = makeScheduleManagingPhysician();
    $second = makeScheduleManagingPhysician();

    foreach ([$first, $second] as $physician) {
        $this->actingAs($physician)
            ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
                'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
            ])
            ->assertCreated();
    }

    expect(PhysicianSchedule::count())->toBe(2);
});

it('rejects an invalid day of week', function (mixed $day) {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => $day, 'start_time' => '08:00', 'end_time' => '12:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day_of_week');
})->with([7, -1, 'monday']);

it('rejects an invalid time format', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '8am', 'end_time' => '12:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_time');
});

it('rejects end_time not later than start_time', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '12:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_time');
});

it('rejects a midnight-crossing window', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '22:00', 'end_time' => '02:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_time');

    expect(PhysicianSchedule::count())->toBe(0);
});

it('rejects an exact duplicate start time on the same day cleanly', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
        'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    ])->assertCreated();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '11:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_time');

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(1);
});

it('rejects an overlapping active window', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
        'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    ])->assertCreated();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '14:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_time');

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(1);
});

it('accepts adjacent non-overlapping windows at the exact boundary', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
        'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    ])->assertCreated();

    // [08:00,12:00) and [12:00,14:00) share the instant 12:00 but never
    // overlap under a half-open interval.
    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '14:00',
        ])
        ->assertCreated();

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(2);
});

it('does not let an inactive window block a new active window from overlapping it', function () {
    $physician = makeScheduleManagingPhysician();

    $inactive = PhysicianSchedule::create([
        'physician_id' => $physician->user_id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
        'is_active' => false,
    ]);

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00',
        ])
        ->assertCreated();

    expect(PhysicianSchedule::where('physician_id', $physician->user_id)->count())->toBe(2)
        ->and($inactive->fresh()->is_active)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('lets a physician update their own schedule', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00',
        ])
        ->assertOk();

    $schedule = $schedule->fresh();
    expect($schedule->day_of_week)->toBe(2)
        ->and($schedule->start_time)->toBe('09:00:00')
        ->and($schedule->end_time)->toBe('11:00:00');
});

it('lets a physician activate and deactivate their own schedule through update', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'is_active' => false,
        ])
        ->assertOk();

    expect($schedule->fresh()->is_active)->toBeFalse();

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'is_active' => true,
        ])
        ->assertOk();

    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('does not let updating one physician\'s schedule affect another physician\'s schedule', function () {
    $first = makeScheduleManagingPhysician();
    $second = makeScheduleManagingPhysician();

    $firstSchedule = PhysicianSchedule::create([
        'physician_id' => $first->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);
    $secondSchedule = PhysicianSchedule::create([
        'physician_id' => $second->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $this->actingAs($first)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $first, ['schedule' => $firstSchedule->id]), [
            'day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '15:00',
        ])
        ->assertOk();

    expect($secondSchedule->fresh()->day_of_week)->toBe(1)
        ->and($secondSchedule->fresh()->start_time)->toBe('08:00:00');
});

it('excludes the record being updated from its own overlap check', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    // Updating the window to identical values must not trip the duplicate/
    // overlap check against itself.
    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ])
        ->assertOk();

    expect($schedule->fresh()->start_time)->toBe('08:00:00');
});

it('still enforces overlap against other windows during an update', function () {
    $physician = makeScheduleManagingPhysician();
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);
    $moving = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '14:00:00', 'end_time' => '17:00:00',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $moving->id]), [
            'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '13:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_time');

    expect($moving->fresh()->start_time)->toBe('14:00:00');
});

/*
|--------------------------------------------------------------------------
| Activate / deactivate / delete
|--------------------------------------------------------------------------
*/

it('deactivates a schedule without deleting it', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'is_active' => false,
        ])
        ->assertOk();

    $fresh = PhysicianSchedule::find($schedule->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeFalse();
});

it('reports is_active accurately for the front end to render active vs inactive', function () {
    $physician = makeScheduleManagingPhysician();
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00', 'is_active' => true,
    ]);
    PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 3,
        'start_time' => '09:00:00', 'end_time' => '12:00:00', 'is_active' => false,
    ]);

    $response = $this->actingAs($physician)
        ->get(intakeRoute('physician.consultation_intake', $physician))
        ->assertOk();

    $schedules = collect($response->viewData('schedules'));
    expect($schedules->firstWhere('day_of_week', 1)['is_active'])->toBeTrue()
        ->and($schedules->firstWhere('day_of_week', 3)['is_active'])->toBeFalse();
});

it('lets a physician delete their own schedule window', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $this->actingAs($physician)
        ->deleteJson(intakeRoute('physician.consultation_intake.schedules.destroy', $physician, ['schedule' => $schedule->id]))
        ->assertOk();

    expect(PhysicianSchedule::find($schedule->id))->toBeNull();
});

it('no longer lists a deleted schedule in the serialized response', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $response = $this->actingAs($physician)
        ->deleteJson(intakeRoute('physician.consultation_intake.schedules.destroy', $physician, ['schedule' => $schedule->id]))
        ->assertOk();

    expect($response->json('schedules'))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Isolation — the critical rule for this phase
|--------------------------------------------------------------------------
*/

it('creating a schedule window does not open an availability session', function () {
    $physician = makeScheduleManagingPhysician();

    $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ])
        ->assertCreated();

    expect(PhysicianAvailabilitySession::count())->toBe(0);
});

it('deactivating a schedule does not close an existing availability session', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(), 'last_seen_at' => now(),
        'status' => 'open', 'mode' => 'scheduled',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'is_active' => false,
        ])
        ->assertOk();

    expect($session->fresh()->status)->toBe('open');
});

it('deleting a schedule does not touch or expire an existing availability session', function () {
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(), 'last_seen_at' => now(),
        'status' => 'open', 'mode' => 'scheduled',
    ]);

    $this->actingAs($physician)
        ->deleteJson(intakeRoute('physician.consultation_intake.schedules.destroy', $physician, ['schedule' => $schedule->id]))
        ->assertOk();

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('open')
        ->and($fresh->mode)->toBe('scheduled');
});

it('changing a schedule\'s time never rewrites an already-open session\'s mode', function () {
    // The scenario from Phase 2/3's shared spec: a session opened as
    // 'scheduled' against Monday 08:00-12:00 must stay 'scheduled' even
    // after the physician moves that window to the afternoon.
    $physician = makeScheduleManagingPhysician();
    $schedule = PhysicianSchedule::create([
        'physician_id' => $physician->user_id, 'day_of_week' => 1,
        'start_time' => '08:00:00', 'end_time' => '12:00:00',
    ]);

    $session = PhysicianAvailabilitySession::create([
        'physician_id' => $physician->user_id,
        'started_at' => now(), 'last_seen_at' => now(),
        'status' => 'open', 'mode' => 'scheduled',
    ]);

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $schedule->id]), [
            'day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00',
        ])
        ->assertOk();

    expect($session->fresh()->mode)->toBe('scheduled')
        ->and($session->fresh()->status)->toBe('open');
});

it('schedule CRUD never modifies consultations, requests, slots or user presence', function () {
    $physician = makeScheduleManagingPhysician(['online_status' => 'online']);
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultationRequest = Consultation::create([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Fever']],
        'online_reason' => 'Unaffected by schedule CRUD',
        'request_status' => 'active',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'available',
    ]);

    $physicianOnlineStatusBefore = $physician->fresh()->online_status;

    $created = $this->actingAs($physician)
        ->postJson(intakeRoute('physician.consultation_intake.schedules.store', $physician), [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ])
        ->assertCreated()
        ->json('schedules.0.id');

    $this->actingAs($physician)
        ->putJson(intakeRoute('physician.consultation_intake.schedules.update', $physician, ['schedule' => $created]), [
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00', 'is_active' => false,
        ])
        ->assertOk();

    $this->actingAs($physician)
        ->deleteJson(intakeRoute('physician.consultation_intake.schedules.destroy', $physician, ['schedule' => $created]))
        ->assertOk();

    expect($consultationRequest->fresh()->request_status)->toBe('active')
        ->and($session->fresh()->consultation_status)->toBe('active')
        ->and($slot->fresh()->status)->toBe('available')
        ->and($physician->fresh()->online_status)->toBe($physicianOnlineStatusBefore);
});
