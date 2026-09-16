<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Notifications\ConsultationReminder;
use Illuminate\Support\Facades\Notification;

function reminderFixture(string $slotDate, string $startTime, string $slotStatus = 'booked', ?string $reminderSentAt = null): array
{
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician', 'account_status' => 'active']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => $slotDate,
        'start_time' => $startTime,
        'end_time' => '23:59:00',
        'status' => $slotStatus,
        'reminder_sent_at' => $reminderSentAt,
    ]);

    ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => $slot->slot_id,
        'consultation_status' => 'scheduled',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
    ]);

    return compact('patient', 'physician', 'consultation', 'slot');
}

it('emails a reminder for a consultation scheduled within the next 24 hours', function () {
    Notification::fake();

    ['patient' => $patient, 'slot' => $slot] = reminderFixture(now()->addHours(20)->toDateString(), now()->addHours(20)->format('H:i:s'));

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertSentTo($patient, ConsultationReminder::class);
    expect($slot->fresh()->reminder_sent_at)->not->toBeNull();
});

it('does not email a reminder for a consultation more than 24 hours away', function () {
    Notification::fake();

    ['patient' => $patient, 'slot' => $slot] = reminderFixture(now()->addDays(3)->toDateString(), '09:00:00');

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($patient, ConsultationReminder::class);
    expect($slot->fresh()->reminder_sent_at)->toBeNull();
});

it('does not send a duplicate reminder on a second run', function () {
    Notification::fake();

    ['patient' => $patient] = reminderFixture(now()->addHours(10)->toDateString(), now()->addHours(10)->format('H:i:s'));

    $this->artisan('consultations:send-reminders')->assertExitCode(0);
    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertSentToTimes($patient, ConsultationReminder::class, 1);
});

it('does not email a reminder for a slot that has already been reminded', function () {
    Notification::fake();

    ['patient' => $patient] = reminderFixture(now()->addHours(10)->toDateString(), now()->addHours(10)->format('H:i:s'), 'booked', now()->subMinutes(5));

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($patient, ConsultationReminder::class);
});

it('does not email a reminder for a slot that is not booked', function () {
    Notification::fake();

    ['patient' => $patient] = reminderFixture(now()->addHours(10)->toDateString(), now()->addHours(10)->format('H:i:s'), 'available');

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($patient, ConsultationReminder::class);
});

it('does not email a reminder for a slot whose window already started', function () {
    Notification::fake();

    ['patient' => $patient] = reminderFixture(now()->subHours(2)->toDateString(), now()->subHours(2)->format('H:i:s'));

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($patient, ConsultationReminder::class);
});

/**
 * Covers the "released/rescheduled slots can receive a new reminder" case:
 * once a slot already reminded is released by a reschedule (which resets
 * its reminder_sent_at — see ConsultationOwnershipService::
 * scheduleByPhysician), it must be able to earn a fresh reminder the next
 * time it is booked, rather than staying permanently suppressed by the
 * earlier booking's flag.
 */
it('sends a fresh reminder for a slot after it is released by a reschedule and rebooked', function () {
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

    // Booked, already reminded, and within the window — this is the slot
    // that gets released.
    $firstSlot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addHours(10)->toDateString(),
        'start_time' => now()->addHours(10)->format('H:i:s'),
        'end_time' => '23:59:00',
        'status' => 'available',
    ]);

    $secondSlot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addHours(12)->toDateString(),
        'start_time' => now()->addHours(12)->format('H:i:s'),
        'end_time' => '23:59:00',
        'status' => 'available',
    ]);

    // Book the first slot, then simulate a reminder having already gone out
    // for it before the reschedule happens.
    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $firstSlot->slot_id,
    ])->assertOk();

    $firstSlot->update(['reminder_sent_at' => now()]);

    // Reschedule onto the second slot — releases the first slot back to
    // available and should reset its reminder flag.
    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $secondSlot->slot_id,
    ])->assertOk();

    expect($firstSlot->fresh())
        ->status->toBe('available')
        ->reminder_sent_at->toBeNull();

    // The reminder command should now email for the newly booked second
    // slot (never reminded) — the released first slot is no longer
    // 'booked' so it is not a candidate at all.
    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertSentToTimes($patient, ConsultationReminder::class, 1);
    expect($secondSlot->fresh()->reminder_sent_at)->not->toBeNull();

    // Now book a brand-new consultation onto the released first slot and
    // confirm it is reminder-eligible again rather than permanently
    // suppressed by the earlier booking's flag.
    $secondPatient = User::factory()->create(['role' => 'patient']);
    $secondConsultation = Consultation::forceCreate([
        'patient_id' => $secondPatient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'Fever',
        'symptoms_desc' => [['name' => 'Fever', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'reviewed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($physician)->postJson(route('physician.consultations.schedule', [
        'physician' => $physician->user_id,
        'consultation' => $secondConsultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
        'slot_id' => $firstSlot->slot_id,
    ])->assertOk();

    $this->artisan('consultations:send-reminders')->assertExitCode(0);

    Notification::assertSentToTimes($secondPatient, ConsultationReminder::class, 1);
});
