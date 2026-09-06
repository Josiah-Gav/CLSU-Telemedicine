<?php

use App\Enums\NotificationType;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\Message;
use App\Models\Notification;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\NotificationService;

// ---------------------------------------------------------------------------
// Notification creation
// ---------------------------------------------------------------------------

it('creates a notification for the correct user with type, title, and message', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $notification = NotificationService::send(
        $patient->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Consultation Scheduled',
        'Your consultation is scheduled for August 12 at 2:00 PM.',
        ['consultation_id' => 123, 'schedule_slot_id' => 55]
    );

    expect($notification)->not->toBeNull();
    expect($notification->user_id)->toBe($patient->user_id);
    expect($notification->type)->toBe('consultation_scheduled');
    expect($notification->title)->toBe('Consultation Scheduled');
    expect($notification->message)->toBe('Your consultation is scheduled for August 12 at 2:00 PM.');
    expect($notification->read_at)->toBeNull();
});

it('stores the data JSON correctly and casts it back to an array', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $notification = NotificationService::send(
        $patient->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Consultation Scheduled',
        'Your consultation is scheduled.',
        ['consultation_id' => 123, 'schedule_slot_id' => 55]
    );

    expect($notification->data)->toBeArray();
    expect($notification->data['consultation_id'])->toBe(123);
    expect($notification->data['schedule_slot_id'])->toBe(55);

    $this->assertDatabaseHas('notifications', [
        'notification_id' => $notification->notification_id,
        'user_id' => $patient->user_id,
        'type' => 'consultation_scheduled',
    ]);
});

it('rejects invalid notification types', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $notification = NotificationService::send(
        $patient->user_id,
        'not_a_real_type',
        'Invalid',
        'This should not be created.'
    );

    expect($notification)->toBeNull();
    $this->assertDatabaseCount('notifications', 0);
});

it('prevents duplicate notifications for the same event and entity', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $first = NotificationService::sendUnique(
        $patient->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Consultation Scheduled',
        'Your consultation is scheduled.',
        ['consultation_id' => 123, 'schedule_slot_id' => 55]
    );

    $second = NotificationService::sendUnique(
        $patient->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Consultation Scheduled',
        'Your consultation is scheduled.',
        ['consultation_id' => 123, 'schedule_slot_id' => 55]
    );

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    $this->assertDatabaseCount('notifications', 1);
});

it('sends notifications to all users of a role', function () {
    $nurseOne = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $nurseTwo = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
    $inactiveNurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'inactive']);

    $count = NotificationService::sendToRole(
        'nurse',
        NotificationType::CONSULTATION_SUBMITTED,
        'New Consultation',
        'A new consultation requires review.',
        ['consultation_id' => 1]
    );

    expect($count)->toBe(2);
    $this->assertDatabaseHas('notifications', ['user_id' => $nurseOne->user_id, 'type' => 'consultation_submitted']);
    $this->assertDatabaseHas('notifications', ['user_id' => $nurseTwo->user_id, 'type' => 'consultation_submitted']);
    $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveNurse->user_id]);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('returns only the authenticated users notifications', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $otherUser = User::factory()->create(['role' => 'patient']);

    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Scheduled', 'Message', ['consultation_id' => 1]);
    NotificationService::send($otherUser->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Other', 'Other message', ['consultation_id' => 2]);

    $this->actingAs($patient)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Scheduled')
        ->assertJsonMissing(['title' => 'Other']);
});

it('ignores user_id query parameter and always uses the authenticated user', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $otherUser = User::factory()->create(['role' => 'patient']);

    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Mine', 'My message', ['consultation_id' => 1]);
    NotificationService::send($otherUser->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Theirs', 'Their message', ['consultation_id' => 2]);

    $this->actingAs($patient)
        ->getJson(route('notifications.index', ['user_id' => $otherUser->user_id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');
});

it('does not allow a user to mark another users notification as read', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $otherUser = User::factory()->create(['role' => 'patient']);

    $notification = NotificationService::send(
        $otherUser->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Scheduled',
        'Message',
        ['consultation_id' => 1]
    );

    $this->actingAs($patient)
        ->patchJson(route('notifications.read', $notification))
        ->assertStatus(403);

    $this->assertDatabaseHas('notifications', [
        'notification_id' => $notification->notification_id,
        'read_at' => null,
    ]);
});

// ---------------------------------------------------------------------------
// Read status
// ---------------------------------------------------------------------------

it('returns unread count for the authenticated user', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Scheduled', 'Message', ['consultation_id' => 1]);
    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_STARTED, 'Started', 'Message', ['consultation_id' => 2]);
    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_COMPLETED, 'Completed', 'Message', ['consultation_id' => 3]);

    Notification::where('type', 'consultation_completed')->update(['read_at' => now()]);

    $this->actingAs($patient)
        ->getJson(route('notifications.unread_count'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);
});

it('marks a single notification as read', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $notification = NotificationService::send(
        $patient->user_id,
        NotificationType::CONSULTATION_SCHEDULED,
        'Scheduled',
        'Message',
        ['consultation_id' => 1]
    );

    $this->actingAs($patient)
        ->patchJson(route('notifications.read', $notification))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'notification_id' => $notification->notification_id,
    ]);

    $notification->refresh();
    expect($notification->read_at)->not->toBeNull();
});

it('marks all notifications as read for the authenticated user only', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $otherUser = User::factory()->create(['role' => 'patient']);

    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Scheduled', 'Message', ['consultation_id' => 1]);
    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_STARTED, 'Started', 'Message', ['consultation_id' => 2]);
    NotificationService::send($otherUser->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Other', 'Message', ['consultation_id' => 3]);

    $this->actingAs($patient)
        ->patchJson(route('notifications.read_all'))
        ->assertOk()
        ->assertJsonPath('data.updated_count', 2);

    $this->assertDatabaseMissing('notifications', [
        'user_id' => $patient->user_id,
        'read_at' => null,
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $otherUser->user_id,
        'read_at' => null,
    ]);
});

// ---------------------------------------------------------------------------
// All Notifications page
// ---------------------------------------------------------------------------

it('renders the all-notifications page with only the authenticated users notifications', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $otherUser = User::factory()->create(['role' => 'patient']);

    NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Mine', 'My message', ['consultation_id' => 1]);
    NotificationService::send($otherUser->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Theirs', 'Their message', ['consultation_id' => 2]);

    $this->actingAs($patient)
        ->get(route('notifications.all'))
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('requires authentication to view the all-notifications page', function () {
    $this->get(route('notifications.all'))
        ->assertRedirect(route('login'));
});

it('filters the notifications index to unread only when requested', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $unread = NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Unread', 'Message', ['consultation_id' => 1]);
    $read = NotificationService::send($patient->user_id, NotificationType::CONSULTATION_STARTED, 'Read', 'Message', ['consultation_id' => 2]);
    $read->update(['read_at' => now()]);

    $this->actingAs($patient)
        ->getJson(route('notifications.index', ['unread' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Unread');

    $this->actingAs($patient)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters the notifications index by date range', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $today = NotificationService::send($patient->user_id, NotificationType::CONSULTATION_SCHEDULED, 'Today', 'Message', ['consultation_id' => 1]);
    $old = NotificationService::send($patient->user_id, NotificationType::CONSULTATION_STARTED, 'Old', 'Message', ['consultation_id' => 2]);
    // created_at isn't mass-assignable (Notification::$fillable), so update()
    // would silently no-op here — forceFill bypasses that guard.
    $old->forceFill(['created_at' => now()->subDays(40)])->save();

    $this->actingAs($patient)
        ->getJson(route('notifications.index', ['date_filter' => 'today']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Today');

    $this->actingAs($patient)
        ->getJson(route('notifications.index', ['date_filter' => 'last_30_days']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Today');

    $this->actingAs($patient)
        ->getJson(route('notifications.index', ['date_filter' => 'bogus']))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------------------
// Workflow integration
// ---------------------------------------------------------------------------

it('notifies nurses when a patient submits a consultation', function () {
    // Consultation intake must be open for store() to accept a new request.
    // Added only to this test, not a shared helper, so the physician it creates
    // cannot alter sendToRole('physician') fan-out counts elsewhere in this file.
    makeConsultationIntakeAvailable();

    $patient = User::factory()->create(['role' => 'patient']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);

    $this->actingAs($patient)
        ->postJson(route('consultations.store'), [
            'concern_category' => 'Headache',
            'symptoms_payload' => json_encode([['name' => 'Headache', 'severity' => 'mild']]),
            'online_reason' => 'Need consultation',
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $nurse->user_id,
        'type' => 'consultation_submitted',
    ]);
});

it('notifies physicians when a consultation is approved by a nurse', function () {
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
        ->postJson(route('consultations.approve', $consultation), [
            'priority_level' => 'Normal',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'consultation_reviewed',
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $physician->user_id,
        'type' => 'consultation_assigned',
    ]);
});

it('notifies the patient when a consultation is scheduled', function () {
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
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'consultation_scheduled',
    ]);
});

it('notifies the physician when a patient sends a message', function () {
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
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.messaging.store', $session), [
            'message' => 'Hello doctor, I have a question.',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $physician->user_id,
        'type' => 'new_message',
    ]);
});

it('notifies nurses when a patient submits a follow-up request', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $nurse = User::factory()->create(['role' => 'nurse', 'account_status' => 'active']);
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

    $this->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $session]), [
            'reason' => 'I still have symptoms.',
        ])
        ->assertRedirect(route('patient.follow_up_list'));

    $this->assertDatabaseHas('notifications', [
        'user_id' => $nurse->user_id,
        'type' => 'follow_up_submitted',
    ]);
});

it('notifies the patient when a physician approves a follow-up request', function () {
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
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'follow_up_approved',
    ]);
});