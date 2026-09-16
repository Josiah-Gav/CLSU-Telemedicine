<?php

use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
| attachments:move-to-private, phase 2 — consultation_requests.file_attachments.
|
| These rows differ from message_attachments in a way that matters: the stored
| value is an asset('storage/...') URL or a bare "/storage/..." path, not a
| disk-relative one. The prefix is meaningful only for the public disk, so the
| entry itself has to be rewritten as the bytes move, where phase 1 can leave
| its rows alone.
|
| The exposure being closed: a patient's intake photo sitting under
| storage/app/public is served straight off the filesystem by the web server at
| /storage/consultation-attachments/..., before any PHP — and therefore any
| authorization — runs.
*/

function migratableConsultation(array $entries): Consultation
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    return Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Rash', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'submitted_at' => now(),
        'file_attachments' => $entries,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
});

it('moves a public consultation attachment to the private disk and rewrites the entry', function () {
    Storage::disk('public')->put('consultation-attachments/scan.jpg', 'patient-photo-bytes');
    $consultation = migratableConsultation(['http://localhost/storage/consultation-attachments/scan.jpg']);

    test()->artisan('attachments:move-to-private')->assertSuccessful();

    // Bytes moved intact.
    Storage::disk('message_attachments')->assertExists('consultation-attachments/scan.jpg');
    expect(Storage::disk('message_attachments')->get('consultation-attachments/scan.jpg'))
        ->toBe('patient-photo-bytes');

    // The exposed copy is gone, which is what actually closes the hole.
    Storage::disk('public')->assertMissing('consultation-attachments/scan.jpg');

    // And the row holds a private-disk-relative path, not a URL.
    expect($consultation->fresh()->file_attachments)->toBe(['consultation-attachments/scan.jpg']);
});

it('handles a bare /storage path entry as well as a full asset url', function () {
    Storage::disk('public')->put('consultation-attachments/bare.png', 'bytes');
    $consultation = migratableConsultation(['/storage/consultation-attachments/bare.png']);

    test()->artisan('attachments:move-to-private')->assertSuccessful();

    expect($consultation->fresh()->file_attachments)->toBe(['consultation-attachments/bare.png']);
    Storage::disk('public')->assertMissing('consultation-attachments/bare.png');
});

it('leaves cloudinary references and legacy remote urls untouched', function () {
    $entries = [
        'cloudinary:image:png:telemed_consultations/ab12',
        'https://res.cloudinary.com/demo/image/upload/v1/telemed_consultations/legacy.png',
    ];
    $consultation = migratableConsultation($entries);

    test()->artisan('attachments:move-to-private')->assertSuccessful();

    expect($consultation->fresh()->file_attachments)->toBe($entries);
});

it('migrates only the local entry in a mixed row', function () {
    Storage::disk('public')->put('consultation-attachments/local.jpg', 'bytes');
    $consultation = migratableConsultation([
        'cloudinary:image:png:telemed_consultations/ab12',
        '/storage/consultation-attachments/local.jpg',
    ]);

    test()->artisan('attachments:move-to-private')->assertSuccessful();

    expect($consultation->fresh()->file_attachments)->toBe([
        'cloudinary:image:png:telemed_consultations/ab12',
        'consultation-attachments/local.jpg',
    ]);
});

it('reports a missing consultation attachment instead of silently rewriting it', function () {
    $consultation = migratableConsultation(['/storage/consultation-attachments/gone.jpg']);

    test()->artisan('attachments:move-to-private')
        ->expectsOutputToContain('source file not found on either disk')
        ->assertFailed();

    // Left exactly as it was, for a human to investigate.
    expect($consultation->fresh()->file_attachments)
        ->toBe(['/storage/consultation-attachments/gone.jpg']);
});

it('does not touch anything during a dry run', function () {
    Storage::disk('public')->put('consultation-attachments/dry.jpg', 'bytes');
    $consultation = migratableConsultation(['/storage/consultation-attachments/dry.jpg']);

    test()->artisan('attachments:move-to-private', ['--dry-run' => true])->assertSuccessful();

    Storage::disk('public')->assertExists('consultation-attachments/dry.jpg');
    Storage::disk('message_attachments')->assertMissing('consultation-attachments/dry.jpg');
    expect($consultation->fresh()->file_attachments)
        ->toBe(['/storage/consultation-attachments/dry.jpg']);
});

it('is safe to run twice', function () {
    Storage::disk('public')->put('consultation-attachments/twice.jpg', 'bytes');
    $consultation = migratableConsultation(['/storage/consultation-attachments/twice.jpg']);

    test()->artisan('attachments:move-to-private')->assertSuccessful();
    test()->artisan('attachments:move-to-private')->assertSuccessful();

    expect($consultation->fresh()->file_attachments)->toBe(['consultation-attachments/twice.jpg']);
    Storage::disk('message_attachments')->assertExists('consultation-attachments/twice.jpg');
});

it('serves a migrated attachment through the authorized route afterwards', function () {
    Storage::disk('public')->put('consultation-attachments/served.jpg', 'bytes');
    $consultation = migratableConsultation(['/storage/consultation-attachments/served.jpg']);

    test()->artisan('attachments:move-to-private')->assertSuccessful();

    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    test()->actingAs($nurse)->get(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'served.jpg',
    ]))->assertOk();
});

it('still serves an unmigrated legacy attachment through the route, without redirecting to it', function () {
    // Until the command is run in production, these rows still resolve — but
    // only from behind the controller, never by handing out the public URL.
    Storage::disk('public')->put('consultation-attachments/legacy.jpg', 'bytes');
    $consultation = migratableConsultation(['/storage/consultation-attachments/legacy.jpg']);

    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $response = test()->actingAs($nurse)->get(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'legacy.jpg',
    ]));

    $response->assertOk();
    expect($response->headers->get('Location'))->toBeNull();
});
