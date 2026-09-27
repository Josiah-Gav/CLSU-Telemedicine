<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\ConsultationVideoService;
use App\Services\JitsiService;
use Illuminate\Support\Facades\DB;

/*
| Video rows are created directly here rather than through the start endpoint, so these
| tests need no JaaS credentials at all. The two tests that do hit the video endpoints
| expect 403, which the policy returns before any token is minted.
*/

function makeCompletionScenario(array $sessionOverrides = [], array $requestOverrides = []): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ], $requestOverrides));

    $session = ConsultationSession::create(array_merge([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'diagnosis' => 'Tension headache.',
        'assessment' => 'Patient reports mild, intermittent headache.',
        'plan' => 'Rest and hydration, review in one week.',
        'recommendations' => 'Avoid screen time for 24 hours.',
        'assigned_at' => now(),
        'started_at' => now(),
    ], $sessionOverrides));

    return compact('patient', 'physician', 'session', 'consultationRequest');
}

function startVideoRoomFor(ConsultationSession $session, string $roomName): ConsultationVideoSession
{
    return ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => $roomName,
    ]);
}

it('refuses to complete a consultation with a blank required clinical field', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario(['diagnosis' => '']);

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Fill in Diagnosis before completing the consultation.');

    expect($session->fresh()->consultation_status)->toBe('active');
});

it('closes the active video session when the consultation is completed', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();
    $videoSession = startVideoRoomFor($session, str_repeat('a', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($videoSession->fresh()->ended_at)->not->toBeNull()
        ->and($videoSession->fresh()->isActive())->toBeFalse()
        ->and($session->fresh()->consultation_status)->toBe('completed');
});

it('completes a consultation that never had a video session without creating one', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    expect(ConsultationVideoSession::count())->toBe(0)
        ->and($session->fresh()->consultation_status)->toBe('completed');
});

it('keeps the video session row as history after completion', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();
    startVideoRoomFor($session, str_repeat('b', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    expect(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(1)
        ->and($session->fresh()->videoSessions()->count())->toBe(1)
        ->and($session->fresh()->activeVideoSession()->first())->toBeNull();
});

it('leaves the room name untouched when closing the video session', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();
    $roomName = str_repeat('c', 32);
    $videoSession = startVideoRoomFor($session, $roomName);

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    expect($videoSession->fresh()->room_name)->toBe($roomName);
});

it('stays idempotent on a second completion and does not overwrite ended_at', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();
    $videoSession = startVideoRoomFor($session, str_repeat('d', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    $firstEndedAt = $videoSession->fresh()->ended_at;

    $this->travel(5)->minutes();

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk()
        ->assertJsonPath('message', 'Consultation is already completed.');

    expect($videoSession->fresh()->ended_at->toIso8601String())
        ->toBe($firstEndedAt->toIso8601String())
        ->and(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(1);
});

it('rejects a physician video start after the consultation is completed', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();
    startVideoRoomFor($session, str_repeat('e', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(1);
});

it('rejects a patient video join after the consultation is completed', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makeCompletionScenario();
    startVideoRoomFor($session, str_repeat('f', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.join', $session))
        ->assertForbidden();
});

it('does not close another consultation\'s active video session', function () {
    ['physician' => $physicianA, 'session' => $sessionA] = makeCompletionScenario();
    ['session' => $sessionB] = makeCompletionScenario();

    $videoA = startVideoRoomFor($sessionA, str_repeat('1', 32));
    $videoB = startVideoRoomFor($sessionB, str_repeat('2', 32));

    $this->actingAs($physicianA)
        ->postJson(route('consultations.messaging.complete', $sessionA))
        ->assertOk();

    expect($videoA->fresh()->ended_at)->not->toBeNull()
        ->and($videoB->fresh()->ended_at)->toBeNull()
        ->and($sessionB->fresh()->consultation_status)->toBe('active');
});

it('does not close an independent follow-up consultation\'s video session', function () {
    ['physician' => $physician, 'session' => $parentSession] = makeCompletionScenario();

    ['session' => $followUpSession] = makeCompletionScenario([], [
        'type' => 'follow_up',
        'parent_consultation_id' => $parentSession->id,
    ]);

    $parentVideo = startVideoRoomFor($parentSession, str_repeat('3', 32));
    $followUpVideo = startVideoRoomFor($followUpSession, str_repeat('4', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $parentSession))
        ->assertOk();

    expect($parentVideo->fresh()->ended_at)->not->toBeNull()
        ->and($followUpVideo->fresh()->ended_at)->toBeNull()
        ->and($followUpSession->fresh()->activeVideoSession()->first()->room_name)
        ->toBe($followUpVideo->room_name);
});

it('still completes the booked schedule slot alongside the video session', function () {
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '09:30:00',
        'status' => 'booked',
    ]);

    $session->update(['slot_id' => $slot->slot_id]);
    $videoSession = startVideoRoomFor($session, str_repeat('5', 32));

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    expect($slot->fresh()->status)->toBe('completed')
        ->and($videoSession->fresh()->ended_at)->not->toBeNull();
})->skip(
    fn () => DB::connection()->getDriverName() === 'sqlite',
    'Pre-existing: schedule_slots.status still carries the original SQLite CHECK of (available, booked). '
    .'alter_status_enum_on_schedule_slots_table returns early on SQLite, so writing "completed" fails there. '
    .'This path works on MySQL, which is the production driver.'
);

it('rolls back the video closure when the completion transaction fails', function () {
    // Deliberately no schedule slot: the slot write would hit the pre-existing SQLite
    // CHECK constraint and abort the transaction before the video closure step, which
    // is not the failure this test is about.
    ['physician' => $physician, 'session' => $session] = makeCompletionScenario();

    $videoSession = startVideoRoomFor($session, str_repeat('6', 32));

    // A real failure raised *after* the video row has actually been closed: the
    // subclass performs the genuine closure, then throws, so the whole transaction
    // — including the already-written ended_at — must roll back.
    $this->app->bind(ConsultationVideoService::class, function ($app) {
        return new class($app->make(JitsiService::class)) extends ConsultationVideoService
        {
            public function end(ConsultationSession $session): bool
            {
                parent::end($session);

                throw new RuntimeException('Simulated failure after video closure.');
            }
        };
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session)))
        ->toThrow(RuntimeException::class, 'Simulated failure after video closure.');

    // Everything the transaction touched is back to its pre-request state.
    expect($videoSession->fresh()->ended_at)->toBeNull()
        ->and($videoSession->fresh()->isActive())->toBeTrue()
        ->and($session->fresh()->consultation_status)->toBe('active')
        ->and($session->fresh()->completed_at)->toBeNull()
        ->and($session->fresh()->request->request_status)->toBe('active');
});
