<?php

use App\Models\Consultation;
use App\Models\User;

/*
| Nurse triage — POST /consultations/{consultation}/approve and /reject.
|
| Both routes sit in the auth+verified group in routes/web.php and carry no
| {nurse} route parameter, so they are not covered by NurseController's
| authorizeNurse(). ConsultationOwnershipService, which both delegate to, is
| deliberately role-agnostic: it validates workflow state and assignment and
| never asks who is calling.
|
| The consequence, before this suite existed, was that any authenticated and
| verified user of any role could reject another patient's pending consultation
| request outright, or "approve" it — writing their own user_id into
| assigned_nurse_id, choosing the priority level, and fanning a notification out
| to every physician. These tests pin the role boundary at the controller.
*/

function triageConsultation(array $overrides = []): Consultation
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'submitted_at' => now(),
    ], $overrides));
}

function triageUser(string $role): User
{
    return User::factory()->create([
        'role' => $role,
        'user_type' => $role === 'patient' ? 'student' : 'staff',
    ]);
}

function postApprove(User $actor, Consultation $consultation)
{
    return test()->actingAs($actor)->postJson(
        route('consultations.approve', ['consultation' => $consultation->request_id]),
        ['priority_level' => 'Normal']
    );
}

function postReject(User $actor, Consultation $consultation)
{
    return test()->actingAs($actor)->postJson(
        route('consultations.reject', ['consultation' => $consultation->request_id]),
        ['rejection_reason' => 'Not suitable for an online consultation.']
    );
}

/*
|--------------------------------------------------------------------------
| Approval
|--------------------------------------------------------------------------
*/

it('refuses approval to every role except nurse', function (string $role) {
    $consultation = triageConsultation();

    postApprove(triageUser($role), $consultation)->assertForbidden();

    // The refusal must be total: no status move, and no assignment written.
    $fresh = $consultation->fresh();
    expect($fresh->request_status)->toBe('pending')
        ->and($fresh->assigned_nurse_id)->toBeNull();
})->with(['patient', 'physician', 'admin']);

it('refuses approval from the consultation owner themselves', function () {
    $consultation = triageConsultation();
    $owner = User::find($consultation->patient_id);

    postApprove($owner, $consultation)->assertForbidden();

    expect($consultation->fresh()->request_status)->toBe('pending');
});

it('lets a nurse approve a pending consultation', function () {
    $consultation = triageConsultation();
    $nurse = triageUser('nurse');

    postApprove($nurse, $consultation)->assertOk()->assertJson(['success' => true]);

    $fresh = $consultation->fresh();
    expect($fresh->request_status)->toBe('reviewed')
        ->and((int) $fresh->assigned_nurse_id)->toBe((int) $nurse->user_id)
        ->and($fresh->priority_level)->toBe('Normal');
});

it('still enforces workflow state for a nurse', function () {
    // The role check is added in front of the state machine, not in place of it.
    $consultation = triageConsultation(['request_status' => 'completed']);

    postApprove(triageUser('nurse'), $consultation)->assertStatus(422);

    expect($consultation->fresh()->request_status)->toBe('completed');
});

it('refuses approval to a guest', function () {
    $consultation = triageConsultation();

    test()->postJson(
        route('consultations.approve', ['consultation' => $consultation->request_id]),
        ['priority_level' => 'Normal']
    )->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Rejection
|--------------------------------------------------------------------------
*/

it('refuses rejection to every role except nurse', function (string $role) {
    $consultation = triageConsultation();

    postReject(triageUser($role), $consultation)->assertForbidden();

    $fresh = $consultation->fresh();
    expect($fresh->request_status)->toBe('pending')
        ->and($fresh->rejection_reason)->toBeNull();
})->with(['patient', 'physician', 'admin']);

it('refuses rejection from the consultation owner themselves', function () {
    // A patient withdrawing their own request has consultations.cancel for it;
    // rejection is a clinical triage decision and is not theirs to make.
    $consultation = triageConsultation();
    $owner = User::find($consultation->patient_id);

    postReject($owner, $consultation)->assertForbidden();

    expect($consultation->fresh()->request_status)->toBe('pending');
});

it('lets a nurse reject a pending consultation and records who did it', function () {
    $consultation = triageConsultation();
    $nurse = triageUser('nurse');

    postReject($nurse, $consultation)->assertOk()->assertJson(['success' => true]);

    $fresh = $consultation->fresh();
    expect($fresh->request_status)->toBe('rejected')
        ->and($fresh->rejection_reason)->toContain('Not suitable')
        // The actor is now carried through the service boundary, so a rejected
        // request has an attributable reviewer instead of a null nurse.
        ->and((int) $fresh->assigned_nurse_id)->toBe((int) $nurse->user_id);
});

it('refuses rejection of a request another nurse already claimed', function () {
    $claimingNurse = triageUser('nurse');
    $consultation = triageConsultation(['assigned_nurse_id' => $claimingNurse->user_id]);

    postReject(triageUser('nurse'), $consultation)->assertStatus(422);

    expect($consultation->fresh()->request_status)->toBe('pending');
});

it('refuses rejection to a guest', function () {
    $consultation = triageConsultation();

    test()->postJson(
        route('consultations.reject', ['consultation' => $consultation->request_id]),
        ['rejection_reason' => 'Nope.']
    )->assertUnauthorized();
});
