<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

/*
| The messaging page was redesigned (participant-first header, grouped message
| bubbles, integrated video status, messaging-style composer). The redesign was
| presentation-only, so these tests pin the wiring the Alpine component depends
| on — the bindings, the scroll container it looks up by id, and the read-only
| gate — which a future restyle could silently break.
|
| Following this repo's existing precedent for Blade+Alpine coverage (see the
| note in ConsultationVideoJoinUiTest.php): there is no browser test runner, so
| these assert on the server-rendered source.
*/

function messagingUiScenario(string $sessionStatus = 'active'): array
{
    $patient = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
        'first_name' => 'Rosa',
        'last_name' => 'Delacruz',
    ]);

    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
        'first_name' => 'Mario',
        'last_name' => 'Santos',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => $sessionStatus === 'completed' ? 'completed' : 'active',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => $sessionStatus,
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    return compact('patient', 'physician', 'session');
}

it('keeps every composer binding the send/attachment flow depends on', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // Send flow.
    expect($html)->toContain('@submit.prevent="sendMessage"')
        ->toContain('x-model="draft"')
        ->toContain('@input="handleDraftInput"')
        ->toContain('@blur="handleDraftBlur"')
        ->toContain('maxlength="2000"')
        ->toContain(':disabled="isSending"');

    // Attachment flow: the input is only visually hidden inside its label, so it
    // must still carry the ref and change handler the component reads.
    expect($html)->toContain('x-ref="attachments"')
        ->toContain('@change="handleAttachments"')
        ->toContain('multiple')
        ->toContain('@click="clearAttachmentSelection"');
});

it('lets a single pending file be removed and previews it as an image when applicable', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // Per-file removal, alongside the existing "clear all" button.
    expect($html)->toContain('@click="removeSelectedFile(idx)"')
        ->toContain('this.selectedFiles.splice(index, 1)')
        ->toContain('@click="clearAttachmentSelection"');

    // selectedFiles now holds {file, previewUrl} wrappers built in
    // handleAttachments(), and sendMessage() must post the wrapped File, not
    // the wrapper object itself.
    expect($html)->toContain("file.type.startsWith('image/') ? URL.createObjectURL(file) : null")
        ->toContain('@click="openAttachmentPreview(item.previewUrl, item.file.name)"')
        ->toContain("formData.append('attachments[]', item.file)");
});

it('previews a sent image attachment inline in the chat bubble via the shared popup', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // An image attachment renders as an inline <img> button that opens the
    // popup with its real download URL; a video attachment opens the same
    // popup instead of navigating the tab away; any other attachment keeps
    // the existing download-row link.
    expect($html)->toContain('x-if="attachmentIsImage(file)"')
        ->toContain('@click="openAttachmentPreview(file.download_url, file.file_name)"')
        ->toContain(':src="file.download_url"')
        ->toContain('x-if="attachmentIsVideo(file)"')
        ->toContain('@click="openAttachmentPreview(file.download_url, file.file_name, true)"')
        ->toContain('x-if="!attachmentIsImage(file) && !attachmentIsVideo(file)"');
});

it('opens a video attachment in the shared popup instead of navigating to it', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // The popup plays the video in place of the image when previewIsVideo is
    // set, and always offers both a download link and an "open in new tab"
    // link — clicking a video attachment must never just navigate the tab
    // away to download it.
    expect($html)->toContain('previewIsVideo: false')
        ->toContain('openAttachmentPreview(url, name, isVideo = false)')
        ->toContain('this.previewIsVideo = isVideo')
        ->toContain('x-if="previewIsVideo"')
        ->toContain(':src="previewFile" controls')
        ->toContain('<a :href="previewFile" download')
        ->toContain('<a :href="previewFile" target="_blank"');
});

it('keeps the scroll container id the auto-scroll looks up', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    // scrollToBottom() resolves $('#messagesContainer'); renaming it would leave
    // the conversation stuck at the top on every new message.
    $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee('id="messagesContainer"', false);
});

it('wires the prescription preview popup and its image-only thumbnail gates', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // The popup itself: shown on previewFile, closable, and reused for both the
    // pending-selection thumbnail and the already-saved prescription thumbnail.
    expect($html)->toContain('x-show="previewFile"')
        ->toContain('@click="closeAttachmentPreview()"')
        ->toContain(':src="previewFile"');

    // Pending selection: only an image gets a preview URL, and only when one
    // exists does the thumbnail button render.
    expect($html)->toContain("this.selectedPrescriptionFile.type.startsWith('image/')")
        ->toContain('URL.createObjectURL(this.selectedPrescriptionFile)')
        ->toContain('x-if="selectedPrescriptionPreviewUrl"')
        ->toContain('@click="openAttachmentPreview(selectedPrescriptionPreviewUrl, selectedPrescriptionName)"');

    // Already-saved prescription: gated on file extension since no mime_type is
    // available for it, unlike message attachments, and additionally on the
    // assessment tab having been opened — x-show hides that tab but does not
    // stop the browser from fetching an <img> inside it.
    expect($html)->toContain('x-if="assessmentTabOpened && isImageFilename(clinical.prescription.file_name)"')
        ->toContain('@click="openAttachmentPreview(clinical.prescription.download_url, clinical.prescription.file_name)"');

    // Every place that clears or replaces the pending file must revoke its
    // object URL so selecting several images in a row can't leak memory.
    expect(substr_count($html, 'this.revokeSelectedPrescriptionPreview();'))->toBeGreaterThanOrEqual(2);
});

it('lets the physician cancel a selected prescription file before saving', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('@click="clearSelectedPrescription"');

    // clearSelectedPrescription() only clears the pending selection — it must
    // never set removePrescriptionOnSave, which is removePrescription()'s job
    // for an already-saved prescription.
    $fnStart = strpos($html, 'clearSelectedPrescription() {');
    $fnEnd = strpos($html, 'removePrescription() {');
    expect($fnStart)->not->toBeFalse()->and($fnEnd)->toBeGreaterThan($fnStart);

    $fnBody = substr($html, $fnStart, $fnEnd - $fnStart);
    expect($fnBody)->toContain('this.selectedPrescriptionFile = null')
        ->toContain('this.selectedPrescriptionName = \'\'')
        ->toContain('this.$refs.prescription.value = \'\'')
        ->not->toContain('removePrescriptionOnSave');
});

it('renders all three consultation tabs', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario();

    $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee("activeTab = 'messages'", false)
        ->assertSee("activeTab = 'details'", false)
        ->assertSee("activeTab = 'assessment'", false);
});

it('heads the page with the other participant, not the viewer', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagingUiScenario();

    // A physician is talking to the patient...
    $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee('Rosa Delacruz')
        ->assertSee('Patient');

    // ...and the patient is talking to the physician.
    $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->assertSee('Mario Santos')
        ->assertSee('Attending physician');
});

it('hides the mobile floating action button on the messaging page for both participants', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagingUiScenario();

    // The FAB would float directly over the message composer, which sits in
    // the same bottom-right corner — see layouts/navigation.blade.php.
    $physicianHtml = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    $patientHtml = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    expect($physicianHtml)->not->toContain('aria-label="Active Consultations"');
    expect($patientHtml)->not->toContain('aria-label="Active Consultation"');

    // Sanity check: the physician's FAB does render elsewhere (e.g. the
    // dashboard), so this isn't just a role that never gets one.
    $physicianDashboardHtml = $this->actingAs($physician)
        ->get(route('physician.dashboard', ['physician' => $physician->user_id]))
        ->assertOk()
        ->getContent();

    expect($physicianDashboardHtml)->toContain('aria-label="Active Consultations"');
});

it('replaces the composer with a read-only notice once the consultation is completed', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario('completed');

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('This consultation has been completed. Messaging is now read-only.')
        ->not->toContain('@submit.prevent="sendMessage"');
});

it('shows the patient a TAM evaluation prompt linking to the Google Form once the consultation is completed', function () {
    ['patient' => $patient, 'session' => $session] = messagingUiScenario('completed');

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Consultation Completed')
        ->toContain('Answer Evaluation Form')
        ->toContain('href="https://forms.gle/UFHjvbFmUnXHdeMCA"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('maybeShowTamPrompt')
        ->toContain('dismissTamPrompt');
});

it('distinguishes dismissing the TAM modal from actually clicking through to the evaluation form', function () {
    ['patient' => $patient, 'session' => $session] = messagingUiScenario('completed');

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // "Maybe Later" and the close (x) button must only suppress the modal
    // popping up again — they must NOT mark the evaluation as handled, since
    // that flag is what hides the dashboard's persistent reminder card.
    expect($html)->toContain('@click="dismissTamPrompt()"');

    // Only clicking through to the actual Google Form marks it handled.
    expect($html)->toContain('@click="markTamEvaluationHandled()"')
        ->toContain("localStorage.setItem('tam_evaluation_handled_' + this.sessionId, '1')");
});

it('does not show the TAM evaluation prompt to a patient while the consultation is still active', function () {
    ['patient' => $patient, 'session' => $session] = messagingUiScenario('active');

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // The prompt's own markup is always present in the DOM for a patient
    // (matching this file's existing preview-modal pattern: gated by Alpine
    // x-show/x-cloak, not conditional Blade rendering). The status guard that
    // keeps it hidden while active lives client-side in maybeShowTamPrompt(),
    // so — per this file's no-browser-runner precedent — the server-rendered
    // assertion is on the status value that guard reads, not on the markup.
    expect($html)->toContain("consultationStatus: 'active'")
        ->toContain('showTamPrompt: false');
});

it('carries the session consultation_status through the existing message poll endpoint', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagingUiScenario('active');

    // Before completion, the poll endpoint the patient's page already hits
    // every 3s reports 'active'.
    $before = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->json();
    expect($before['consultation_status'])->toBe('active');

    // The physician completes the consultation out-of-band (as if via their
    // own "Complete consultation" action) — the patient never reloads.
    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.complete', $session))
        ->assertOk();

    // The same poll endpoint, hit again without any page reload, must now
    // report the transition so the patient's existing poller can pick it up.
    $after = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->json();
    expect($after['consultation_status'])->toBe('completed');
});

it('wires fetchMessages() to update consultationStatus and watches it to trigger the TAM prompt', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario('active');

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // fetchMessages() already polls on the existing interval; it must now
    // also sync consultationStatus from that same response, and a $watch
    // must react to the transition by calling the existing TAM logic — no
    // new setInterval, no page reload.
    expect($html)->toContain('this.consultationStatus = data.consultation_status')
        ->toContain("this.\$watch('consultationStatus'")
        ->toContain('this.maybeShowTamPrompt()');
});

it('does not render the patient TAM evaluation prompt for the physician viewing a completed consultation', function () {
    ['physician' => $physician, 'session' => $session] = messagingUiScenario('completed');

    $html = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Consultation Completed')
        ->not->toContain('Answer Evaluation Form');
});
