<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Notifications\ConsultationCompleted;
use App\Notifications\ConsultationScheduled;
use App\Notifications\FollowUpScheduled;
use Illuminate\Support\Facades\Notification;

// ---------------------------------------------------------------------------
// Consultation scheduled / rescheduled
// ---------------------------------------------------------------------------

it('emails the patient when a consultation is scheduled', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'reviewed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.consultations.schedule', [
            'physician' => $physician->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physician->user_id,
            'slot_id' => $slot->slot_id,
        ])
        ->assertOk();

    Notification::assertSentTo($patient, ConsultationScheduled::class, function (ConsultationScheduled $notification) {
        $mail = $notification->toMail($notification);

        return $mail->subject === 'Your Telemedicine Consultation Has Been Scheduled';
    });
});

it('emails the patient a different subject when a consultation is rescheduled', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'reviewed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $firstSlot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'available',
    ]);

    $secondSlot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDays(2)->toDateString(),
        'start_time' => '11:00:00',
        'end_time' => '11:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $firstSlot->slot_id,
    ])->assertOk();

    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $secondSlot->slot_id,
    ])->assertOk();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'consultation_rescheduled',
    ]);

    Notification::assertSentTo($patient, ConsultationScheduled::class, function (ConsultationScheduled $notification) {
        $mail = $notification->toMail($notification);

        return $mail->subject === 'Your Telemedicine Consultation Has Been Rescheduled';
    });

    expect($firstSlot->fresh()->reminder_sent_at)->toBeNull()
        ->and($firstSlot->fresh()->status)->toBe('available');
});

// ---------------------------------------------------------------------------
// Consultation completed
// ---------------------------------------------------------------------------

it('emails the patient when a consultation is completed', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'active',
        'assessment' => 'Assessment in progress.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now()->subMinutes(20),
        'started_at' => now()->subMinutes(15),
    ]);

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', ['session' => $session->id]))
        ->assertOk();

    Notification::assertSentTo($patient, ConsultationCompleted::class, function (ConsultationCompleted $notification) {
        $mail = $notification->toMail($notification);

        return $mail->subject === 'Your Telemedicine Consultation Is Complete';
    });
});

// ---------------------------------------------------------------------------
// Follow-up scheduled
// ---------------------------------------------------------------------------

it('emails the patient when the physician approves a follow-up request onto a slot', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
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

    $followUpRequest = FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '09:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', [
            'physician' => $physician->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision' => 'approved',
            'mode' => 'scheduled',
            'slot_id' => $slot->slot_id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'follow_up_scheduled',
    ]);

    Notification::assertSentTo($patient, FollowUpScheduled::class, function (FollowUpScheduled $notification) {
        $mail = $notification->toMail($notification);

        return $mail->subject === 'Your Telemedicine Follow-Up Has Been Scheduled';
    });
});

it('does not email a follow-up-scheduled notice for an immediate follow-up approval', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
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

    $followUpRequest = FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', [
            'physician' => $physician->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertOk();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'follow_up_approved',
    ]);

    Notification::assertNotSentTo($patient, FollowUpScheduled::class);
});

it('emails the patient when a physician directly creates a scheduled follow-up', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
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

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '13:00:00',
        'end_time' => '13:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up.create', ['physician' => $physician->user_id, 'session' => $session->id]), [
            'mode' => 'scheduled',
            'slot_id' => $slot->slot_id,
            'decision_notes' => 'Please come back for a recheck.',
        ])
        ->assertOk();

    Notification::assertSentTo($patient, FollowUpScheduled::class);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'follow_up_scheduled',
    ]);
});

// ---------------------------------------------------------------------------
// In-app-only events must not send an email
// ---------------------------------------------------------------------------

it('does not email anyone when a consultation is assigned to a physician', function () {
    Notification::fake();

    $patient = User::factory()->create(['role' => 'patient']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($nurse)
        ->postJson(route('consultations.approve', $consultation), ['priority_level' => 'Normal'])
        ->assertOk();

    Notification::assertNothingSent();
});
