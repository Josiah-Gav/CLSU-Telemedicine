<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;

it('shows a physician initiated follow-up as a single consultation card naming the physician', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'scheduled',
        'assessment' => 'Follow-up assessment pending.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'booked',
    ]);

    $session->update(['slot_id' => $slot->slot_id]);

    $this->actingAs($patient);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Scheduled Appointment')
        ->assertSee('Noah Flores')
        ->assertSee($slot->slot_date->format('M d, Y'))
        ->assertViewMissing('physicianFollowUp');

    // Regression: this request used to come back twice, once as
    // `consultation` and again as `physician_follow_up`, and the dashboard
    // drew a card for each.
    $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->assertJsonPath('consultation.request_id', $consultationRequest->request_id)
        ->assertJsonPath('consultation.arranged_by_physician', 'Noah Flores')
        ->assertJsonPath('consultation.session.scheduled_slot.slot_date', $slot->slot_date->format('M d, Y'))
        ->assertJsonMissingPath('physician_follow_up');
});

it('prefers scheduled physician follow-up data over completed follow-up data on the patient dashboard', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $scheduledConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    $scheduledSession = ConsultationSession::create([
        'request_id' => $scheduledConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'scheduled',
        'assessment' => 'Follow-up assessment pending.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'booked',
    ]);

    $scheduledSession->update(['slot_id' => $slot->slot_id]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Scheduled')
        ->assertSee($slot->slot_date->format('M d, Y'));

    $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->assertJsonPath('consultation.request_id', $scheduledConsultation->request_id)
        ->assertJsonPath('consultation.request_status', 'scheduled');
});

it('hides the consultation card when the physician follow-up consultation is completed', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $completedFollowUp = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    ConsultationSession::create([
        'request_id' => $completedFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($patient);

    $this->get(route('dashboard'))
        ->assertOk();

    $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->assertJsonPath('consultation', null);
});

it('does not label a patient initiated follow-up as arranged by the physician', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $originalRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $originalSession = ConsultationSession::create([
        'request_id' => $originalRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Initial assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subDays(2),
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $originalSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Symptoms returned.',
        'status' => 'approved',
        'reviewed_by_nurse_id' => null,
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $patientInitiatedConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    // Patient-initiated: the session links back to the FollowUpRequest the
    // patient submitted — this is what must keep the "arranged by your
    // physician" line off it (see Consultation::isPhysicianInitiatedFollowUp).
    ConsultationSession::create([
        'request_id' => $patientInitiatedConsultation->request_id,
        'physician_id' => $physician->user_id,
        'follow_up_request_id' => $followUpRequest->id,
        'slot_id' => null,
        'consultation_status' => 'scheduled',
        'assessment' => 'Follow-up assessment pending.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => null,
    ]);

    $this->actingAs($patient);

    $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->assertJsonPath('consultation.request_id', $patientInitiatedConsultation->request_id)
        ->assertJsonPath('consultation.arranged_by_physician', null);
});

it('hides the follow-up status card on patient dashboard when latest follow-up request is rejected', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Need another follow-up review.',
        'status' => 'rejected',
        'decision_notes' => 'Follow-up not clinically indicated.',
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Your latest follow-up request');
});

it('lets a patient request a follow-up for a completed consultation within the allowed window', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [
            ['name' => 'Cough', 'severity' => 'mild'],
        ],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $session]), [
            'reason' => 'I still have symptoms and need a review.',
        ])
        ->assertRedirect(route('patient.follow_up_list'));

    $this->assertDatabaseHas('follow_up_requests', [
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'status' => 'pending',
        'reason' => 'I still have symptoms and need a review.',
    ]);
});

it('lets a patient cancel a follow-up request when it is pending or forwarded but not after approval', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $pendingRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'pending',
    ]);

    $this->actingAs($patient)
        ->postJson(route('patient.follow_up_requests.cancel', ['followUpRequest' => $pendingRequest->id]))
        ->assertJsonPath('success', true);

    $pendingRequest->refresh();
    $this->assertSame('cancelled', $pendingRequest->status);

    $forwardedRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review after review.',
        'status' => 'forwarded',
    ]);

    $this->actingAs($patient)
        ->postJson(route('patient.follow_up_requests.cancel', ['followUpRequest' => $forwardedRequest->id]))
        ->assertJsonPath('success', true);

    $forwardedRequest->refresh();
    $this->assertSame('cancelled', $forwardedRequest->status);

    $approvedRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I need this support approved.',
        'status' => 'approved',
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $this->actingAs($patient)
        ->postJson(route('patient.follow_up_requests.cancel', ['followUpRequest' => $approvedRequest->id]))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $approvedRequest->refresh();
    $this->assertSame('approved', $approvedRequest->status);
});

it('lets a physician directly schedule a follow-up consultation with decision notes and no nurse screening', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $slot = \App\Models\ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up.create', ['physician' => $physician->user_id, 'session' => $session->id]), [
            'mode' => 'scheduled',
            'slot_id' => $slot->slot_id,
            'decision_notes' => 'Patient requires a follow-up visit next week.',
        ])
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('consultation_requests', [
        'parent_consultation_id' => $session->id,
        'type' => 'follow_up',
        'request_status' => 'scheduled',
    ]);

    $this->assertDatabaseHas('follow_up_requests', [
        'consultation_id' => $session->id,
        'status' => 'approved',
        'decision_notes' => 'Patient requires a follow-up visit next week.',
        'decided_by_physician_id' => $physician->user_id,
    ]);
});

it('rejects approval without a mode and leaves the follow-up request forwarded', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);

    $followUpRequest->refresh();

    $this->assertSame('forwarded', $followUpRequest->status);
    $this->assertDatabaseCount('consultation_requests', 1);
    $this->assertDatabaseCount('consultations', 1);
    $this->assertDatabaseMissing('consultation_requests', [
        'type' => 'follow_up',
    ]);
});

it('lets a physician approve a forwarded follow-up request with immediate mode and creates an active follow-up consultation', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertJsonPath('success', true);

    $followUpRequest->refresh();

    $this->assertSame('approved', $followUpRequest->status);
    $this->assertNotNull($followUpRequest->decided_by_physician_id);
    $this->assertNotNull($followUpRequest->decided_at);

    $this->assertDatabaseCount('consultation_requests', 2);
    $this->assertDatabaseHas('consultation_requests', [
        'type' => 'follow_up',
        'parent_consultation_id' => $session->id,
        'request_status' => 'active',
        'assigned_physician_id' => $physician->user_id,
    ]);

    $this->assertDatabaseCount('consultations', 2);
    $this->assertDatabaseHas('consultations', [
        'follow_up_request_id' => $followUpRequest->id,
        'consultation_status' => 'active',
        'physician_id' => $physician->user_id,
    ]);

    $followUpSessions = ConsultationSession::where('follow_up_request_id', $followUpRequest->id)->get();
    expect($followUpSessions)->toHaveCount(1);
    expect($followUpSessions->first()->started_at)->not->toBeNull();
});

it('lets a physician approve a forwarded follow-up request with scheduled mode and creates a scheduled follow-up consultation', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
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
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
            'mode' => 'scheduled',
            'slot_id' => $slot->slot_id,
        ])
        ->assertJsonPath('success', true);

    $followUpRequest->refresh();
    $slot->refresh();

    $this->assertSame('approved', $followUpRequest->status);
    $this->assertSame('booked', $slot->status);

    $this->assertDatabaseCount('consultation_requests', 2);
    $this->assertDatabaseHas('consultation_requests', [
        'type' => 'follow_up',
        'parent_consultation_id' => $session->id,
        'request_status' => 'scheduled',
        'assigned_physician_id' => $physician->user_id,
    ]);

    $this->assertDatabaseCount('consultations', 2);
    $this->assertDatabaseHas('consultations', [
        'follow_up_request_id' => $followUpRequest->id,
        'consultation_status' => 'scheduled',
        'slot_id' => $slot->slot_id,
        'physician_id' => $physician->user_id,
    ]);

    $followUpSessions = ConsultationSession::where('follow_up_request_id', $followUpRequest->id)->get();
    expect($followUpSessions)->toHaveCount(1);
    expect($followUpSessions->first()->started_at)->toBeNull();
});

it('rejects an invalid approval mode and leaves the follow-up request forwarded', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
            'mode' => 'invalid',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mode']);

    $followUpRequest->refresh();

    $this->assertSame('forwarded', $followUpRequest->status);
    $this->assertDatabaseCount('consultation_requests', 1);
    $this->assertDatabaseCount('consultations', 1);
});

it('does not create a consultation when a physician rejects a forwarded follow-up request', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'rejected',
            'decision_notes' => 'Follow-up not clinically indicated.',
        ])
        ->assertJsonPath('success', true);

    $followUpRequest->refresh();

    $this->assertSame('rejected', $followUpRequest->status);
    $this->assertSame('Follow-up not clinically indicated.', $followUpRequest->decision_notes);
    $this->assertSame($physician->user_id, $followUpRequest->decided_by_physician_id);
    $this->assertNotNull($followUpRequest->decided_at);
    $this->assertDatabaseCount('consultation_requests', 1);
    $this->assertDatabaseCount('consultations', 1);
});

it('prevents a second approval from creating a duplicate follow-up consultation', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $decideUrl = route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]);

    $this->actingAs($physician)
        ->postJson($decideUrl, [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertJsonPath('success', true);

    $this->actingAs($physician)
        ->postJson($decideUrl, [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertStatus(422);

    $followUpRequest->refresh();

    $this->assertSame('approved', $followUpRequest->status);
    $this->assertDatabaseCount('consultation_requests', 2);
    $this->assertDatabaseCount('consultations', 2);
    $this->assertSame(1, ConsultationSession::where('follow_up_request_id', $followUpRequest->id)->count());
});

it('links the follow-up request to the created follow-up consultation session and the parent session', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertJsonPath('success', true);

    $followUpRequest->refresh();

    $followUpSession = $followUpRequest->followUpConsultation;
    expect($followUpSession)->not->toBeNull();
    expect($followUpSession->followUpRequest->id)->toBe($followUpRequest->id);
    expect($followUpSession->request->parentConsultation->id)->toBe($session->id);
    expect($followUpSession->request->type)->toBe('follow_up');
});

it('shows rejected follow-up request decision notes and rejected consultation reason in patient consultation history', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $rejectedConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'rejected',
        'rejection_reason' => 'Initial screening suggests in-person assessment is required.',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $sourceConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(15),
    ]);

    \App\Models\FollowUpRequest::create([
        'consultation_id' => $sourceSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need follow-up support.',
        'status' => 'rejected',
        'decision_notes' => 'Follow-up request rejected due to insufficient clinical indication.',
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.history'))
        ->assertOk()
        ->assertSee('Decision for Rejection')
        ->assertSee($rejectedConsultation->rejection_reason)
        ->assertSee('Decision Notes')
        ->assertSee('Follow-up request rejected due to insufficient clinical indication.');
});

it('filters patient consultation history by status and consultation type', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'cancelled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.history', ['status' => 'cancelled', 'consultation_type' => 'general', 'date_filter' => 'all']))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Follow-up Fatigue Consultation');
});

it('filters physician consultation history and searches by patient or nurse name', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $nurse = User::factory()->create([
        'first_name' => 'Nina',
        'last_name' => 'Lopez',
        'role' => 'nurse',
        'user_type' => 'staff',
    ]);

    $patientOne = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $patientTwo = User::factory()->create([
        'first_name' => 'Lara',
        'last_name' => 'Cruz',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientOne->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientTwo->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'cancelled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', [
            'physician' => $physician->user_id,
            'status' => 'completed',
            'consultation_type' => 'follow_up',
            'date_filter' => 'all',
            'search' => 'Mario',
        ]))
        ->assertOk()
        ->assertSee('Mario Santos')
        ->assertSee('Nina Lopez')
        ->assertDontSee('Lara Cruz');
});

it('returns filtered physician consultation history HTML payload for ajax live search', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $patientOne = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $patientTwo = User::factory()->create([
        'first_name' => 'Lara',
        'last_name' => 'Cruz',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientOne->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientTwo->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', [
            'physician' => $physician->user_id,
            'search' => 'Mario',
            'status' => 'all',
            'consultation_type' => 'all',
            'date_filter' => 'all',
        ]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonStructure(['html'])
        ->assertJsonPath('html', function (string $html) {
            return str_contains($html, 'Mario Santos') && !str_contains($html, 'Lara Cruz');
        });
});

it('hides schedule follow-up action for general consultations that already have a follow-up', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $patient = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $generalConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need initial review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $generalConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Initial consultation complete.',
        'plan' => 'Observe for one week.',
        'recommendations' => 'Hydrate and rest.',
        'assigned_at' => now()->subDays(1),
        'started_at' => now()->subDays(1)->addMinutes(15),
        'completed_at' => now()->subHours(2),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => $sourceSession->id,
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Follow-up arranged',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertDontSee('data-consultation-id="' . $generalConsultation->request_id . '"');
});