<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\User;

/**
 * The nurse dashboard's positive "queue is clear" empty state used to be
 * gated on unclaimed_pending alone, so it could render directly beneath a
 * "Follow-ups awaiting triage" tile reporting a nonzero count — telling the
 * nurse there is nothing to do while a follow-up request sits untriaged.
 * unclaimed_high_priority is a subset of unclaimed_pending (both scoped
 * from Consultation::pending()->unclaimed(), see DashboardAnalyticsService),
 * so it needs no separate check; follow_ups_awaiting_triage comes from the
 * independent FollowUpRequest model and is what this test adds coverage for.
 */
function queueClearNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function queueClearPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

/**
 * Minimal completed consultation + session, matching the fixture shape
 * NotificationTest.php already uses to build a FollowUpRequest.
 */
function queueClearCompletedSession(User $patient, User $physician): ConsultationSession
{
    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    return ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Assessment complete.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);
}

it('does not claim the queue is clear while a follow-up request awaits triage', function () {
    $nurse = queueClearNurse();
    $patient = queueClearPatient();
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $session = queueClearCompletedSession($patient, $physician);

    FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Still have symptoms.',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertDontSee('The queue is clear');
});

it('still claims the queue is clear when there is genuinely no nurse work waiting', function () {
    $nurse = queueClearNurse();

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('The queue is clear');
});

it('does not claim the queue is clear while an unclaimed pending request exists', function () {
    $nurse = queueClearNurse();
    $patient = queueClearPatient();

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertDontSee('The queue is clear');
});
