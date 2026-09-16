<?php

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * AttachmentController::show used to allow nurses and nobody else. The
 * physician consultation inbox modal now shows attachments as thumbnails, so
 * physicians need read access too — but only to what that inbox already puts
 * in front of them.
 *
 * PhysicianController::getConsultationInboxData applies no
 * assigned_physician_id filter, so the inbox is a *shared triage pool*: every
 * physician sees every reviewed/assigned/scheduled request, and a reviewed one
 * has no assigned physician at all. Scoping access to "assigned physician
 * only" would therefore 403 exactly the unclaimed rows physicians work from,
 * which is why the pool statuses grant access as well.
 *
 * Patients were blocked here originally because their own pages embedded the
 * raw storage/Cloudinary URL, so this route was not how they reached their
 * files. Making consultation attachments private removed those URLs, leaving
 * this route the only way in — so the owning patient is now allowed, scoped to
 * their own request. Any other patient is still refused.
 */
function attachmentStaff(string $role): User
{
    return User::factory()->create(['role' => $role, 'user_type' => 'staff']);
}

function attachmentConsultation(array $overrides = []): Consultation
{
    Storage::disk('public')->put('consultation_files/scan.png', 'fake-image-bytes');

    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'reviewed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
        'file_attachments' => ['/storage/consultation_files/scan.png'],
    ], $overrides));
}

function fetchAttachment(User $actor, Consultation $consultation)
{
    return test()->actingAs($actor)->get(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'scan.png',
    ]));
}

beforeEach(function () {
    Storage::fake('public');
});

it('lets a physician open an attachment on an unclaimed request in the shared triage pool', function () {
    $consultation = attachmentConsultation(['request_status' => 'reviewed']);

    fetchAttachment(attachmentStaff('physician'), $consultation)->assertOk();
});

it('lets a physician open an attachment on a request assigned to them', function () {
    $physician = attachmentStaff('physician');
    $consultation = attachmentConsultation([
        'request_status' => 'active',
        'assigned_physician_id' => $physician->user_id,
    ]);

    fetchAttachment($physician, $consultation)->assertOk();
});

it('blocks a physician from a request that has left the pool and belongs to someone else', function () {
    $otherPhysician = attachmentStaff('physician');
    $consultation = attachmentConsultation([
        'request_status' => 'completed',
        'assigned_physician_id' => $otherPhysician->user_id,
    ]);

    fetchAttachment(attachmentStaff('physician'), $consultation)->assertForbidden();
});

it('still lets any nurse open an attachment, unchanged', function () {
    $consultation = attachmentConsultation(['request_status' => 'completed']);

    fetchAttachment(attachmentStaff('nurse'), $consultation)->assertOk();
});

it('lets the owning patient open their own attachment', function () {
    $consultation = attachmentConsultation();
    $owner = User::find($consultation->patient_id);

    fetchAttachment($owner, $consultation)->assertOk();
});

it('blocks a patient from another patient\'s attachment', function () {
    $consultation = attachmentConsultation();
    $stranger = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    fetchAttachment($stranger, $consultation)->assertForbidden();
});

it('blocks a guest outright', function () {
    $consultation = attachmentConsultation();

    test()->get(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'scan.png',
    ]))->assertRedirect(route('login'));
});
