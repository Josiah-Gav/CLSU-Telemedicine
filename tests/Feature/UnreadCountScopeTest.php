<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\User;

/*
| GET /consultation-sessions/unread-counts must only ever describe
| consultations the caller is a party to.
|
| The regression this guards: the role conditions were applied inside a
| where() closure, so a role that matched neither branch contributed no
| condition at all and the query fell through to every active session in the
| system. A nurse or admin polling this endpoint received a per-session unread
| count for conversations they are not in and cannot open — disclosing that the
| session exists and how much traffic it carries.
*/

function unreadScenario(): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $request = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'submitted_at' => now(),
    ]);

    $session = ConsultationSession::create([
        'request_id' => $request->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    // One unread message in each direction.
    Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'How are you feeling?',
    ]);
    Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $patient->user_id,
        'message' => 'Still a headache.',
    ]);

    return compact('patient', 'physician', 'session');
}

function fetchUnreadCounts(User $actor)
{
    return test()->actingAs($actor)->getJson(route('consultations.messaging.unread_counts'));
}

it('gives the patient the unread count for their own consultation', function () {
    ['patient' => $patient, 'session' => $session] = unreadScenario();

    $response = fetchUnreadCounts($patient)->assertOk();

    expect($response->json('total_unread'))->toBe(1)
        ->and($response->json('counts.'.$session->id))->toBe(1);
});

it('gives the assigned physician the unread count for their own consultation', function () {
    ['physician' => $physician, 'session' => $session] = unreadScenario();

    $response = fetchUnreadCounts($physician)->assertOk();

    expect($response->json('total_unread'))->toBe(1)
        ->and($response->json('counts.'.$session->id))->toBe(1);
});

it('gives a patient nothing for a consultation that is not theirs', function () {
    unreadScenario();
    $stranger = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $response = fetchUnreadCounts($stranger)->assertOk();

    expect($response->json('counts'))->toBeEmpty()
        ->and($response->json('total_unread'))->toBe(0);
});

it('gives a physician nothing for a consultation assigned to someone else', function () {
    unreadScenario();
    $otherPhysician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $response = fetchUnreadCounts($otherPhysician)->assertOk();

    expect($response->json('counts'))->toBeEmpty()
        ->and($response->json('total_unread'))->toBe(0);
});

it('gives a nurse no unrelated consultation counts', function () {
    ['session' => $session] = unreadScenario();
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $response = fetchUnreadCounts($nurse)->assertOk();

    expect($response->json('counts'))->toBeEmpty()
        ->and($response->json('total_unread'))->toBe(0)
        // Specifically: the session id must not appear at all. Before the fix
        // it was present with a live count.
        ->and($response->json('counts.'.$session->id))->toBeNull();
});

it('gives an admin no unrelated consultation counts', function () {
    ['session' => $session] = unreadScenario();
    $admin = User::factory()->create(['role' => 'admin', 'user_type' => 'staff']);

    $response = fetchUnreadCounts($admin)->assertOk();

    expect($response->json('counts'))->toBeEmpty()
        ->and($response->json('total_unread'))->toBe(0)
        ->and($response->json('counts.'.$session->id))->toBeNull();
});

it('refuses a guest', function () {
    unreadScenario();

    test()->getJson(route('consultations.messaging.unread_counts'))->assertUnauthorized();
});
