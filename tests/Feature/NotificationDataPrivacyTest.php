<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Notification;
use App\Models\ScheduleSlot;
use App\Models\User;

/**
 * Notification previews must carry only what the recipient needs to
 * identify and navigate to the resource — never clinical content, even
 * though the underlying ConsultationSession row it points at (assessment,
 * plan, recommendations, diagnosis, prescription fields) very much has some.
 * These pin the two highest-risk events: completion (where a real diagnosis
 * and prescription exist on the same session by the time the notification
 * fires) and scheduling (where free-text clinical fields already exist on
 * the row from intake).
 */
const FORBIDDEN_NOTIFICATION_DATA_KEYS = [
    'diagnosis', 'assessment', 'plan', 'recommendations',
    'prescription_file_name', 'prescription_file_path', 'symptoms_desc',
    'additional_information', 'rejection_reason', 'decision_notes',
];

it('does not put clinical fields into the consultation-completed notification data', function () {
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
        'assessment' => 'Patient presents with migraine, likely tension-type.',
        'plan' => 'Rest, hydration, follow up if symptoms persist.',
        'recommendations' => 'Avoid screen time for 24 hours.',
        'diagnosis' => 'Tension headache',
        'assigned_at' => now()->subMinutes(20),
        'started_at' => now()->subMinutes(15),
    ]);

    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', ['session' => $session->id]))
        ->assertOk();

    $notification = Notification::where('type', 'consultation_completed')
        ->where('user_id', $patient->user_id)
        ->firstOrFail();

    expect($notification->message)->not->toContain('migraine')
        ->and($notification->message)->not->toContain('Tension headache')
        ->and(array_keys($notification->data))->not->toContain(...FORBIDDEN_NOTIFICATION_DATA_KEYS);
});

it('does not put clinical fields into the consultation-scheduled notification data', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'I have had a severe migraine for three days',
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

    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $slot->slot_id,
    ])->assertOk();

    $notification = Notification::where('type', 'consultation_scheduled')
        ->where('user_id', $patient->user_id)
        ->firstOrFail();

    expect($notification->message)->not->toContain('migraine')
        ->and(array_keys($notification->data))->not->toContain(...FORBIDDEN_NOTIFICATION_DATA_KEYS);
});
