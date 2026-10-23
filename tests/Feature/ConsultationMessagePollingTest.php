<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\User;
use App\Services\MedicalFileStorage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;

/*
| Covers the incremental message poll the messaging page depends on:
|
| - index() honours ?after_id= (by message_id, never created_at) and still
|   returns the full history without it.
| - last_read_own_message_id carries read receipts that an incremental fetch
|   would otherwise never re-deliver.
| - store() never makes a message visible before its attachments exist, or an
|   incremental poll could fetch it without them and never look again.
| - the pagehide exit signal (navigator.sendBeacon) reaches typing/offline as a
|   plain form POST carrying _token instead of a header, with CSRF enforced.
*/

function messagePollingScenario(): array
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
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    return compact('patient', 'physician', 'session');
}

function pollingMessage(ConsultationSession $session, User $sender, string $body): Message
{
    return Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $sender->user_id,
        'message' => $body,
    ]);
}

it('returns only messages newer than after_id, in id order', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagePollingScenario();

    $first = pollingMessage($session, $patient, 'First');
    $second = pollingMessage($session, $physician, 'Second');
    $third = pollingMessage($session, $patient, 'Third');

    $ids = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', ['session' => $session, 'after_id' => $first->message_id]))
        ->assertOk()
        ->json('messages.*.message_id');

    expect($ids)->toBe([$second->message_id, $third->message_id]);
});

it('returns nothing new at the latest id but still reports consultation_status', function () {
    ['patient' => $patient, 'session' => $session] = messagePollingScenario();

    $latest = pollingMessage($session, $patient, 'Only message');

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', ['session' => $session, 'after_id' => $latest->message_id]))
        ->assertOk()
        ->assertJsonPath('messages', [])
        ->assertJsonPath('consultation_status', 'active');
});

it('returns the full history when after_id is omitted', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagePollingScenario();

    $messages = [
        pollingMessage($session, $patient, 'First'),
        pollingMessage($session, $physician, 'Second'),
        pollingMessage($session, $patient, 'Third'),
    ];

    $ids = $this->actingAs($physician)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->json('messages.*.message_id');

    expect($ids)->toBe(array_map(fn (Message $m) => $m->message_id, $messages));
});

it('reports last_read_own_message_id as null until the peer reads, then the highest read id', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagePollingScenario();

    pollingMessage($session, $patient, 'Patient one');
    $patientLatest = pollingMessage($session, $patient, 'Patient two');
    $physicianMessage = pollingMessage($session, $physician, 'Physician unread by patient');

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->assertJsonPath('last_read_own_message_id', null);

    // The physician opens the chat: markRead() marks every patient message.
    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.read', $session))
        ->assertOk();

    // A higher id exists (the physician's own message), but it is not the
    // patient's and the patient has not read it, so it must not count.
    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', ['session' => $session, 'after_id' => $physicianMessage->message_id]))
        ->assertOk()
        ->assertJsonPath('last_read_own_message_id', $patientLatest->message_id);

    // The physician's own messages are still unread by the patient.
    $this->actingAs($physician)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->assertJsonPath('last_read_own_message_id', null);
});

it('refuses a non-participant with or without after_id', function () {
    ['patient' => $patient, 'session' => $session] = messagePollingScenario();
    pollingMessage($session, $patient, 'Private');

    $outsider = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    $this->actingAs($outsider)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->getJson(route('consultations.messaging.index', ['session' => $session, 'after_id' => 0]))
        ->assertForbidden();
});

it('rejects an after_id that is not a non-negative integer', function (string $afterId) {
    ['patient' => $patient, 'session' => $session] = messagePollingScenario();

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.index', ['session' => $session, 'after_id' => $afterId]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('after_id');
})->with(['-1', 'abc', '1.5']);

it('uploads attachments before the message row exists, then writes both together', function () {
    ['physician' => $physician, 'session' => $session] = messagePollingScenario();

    // If the row were committed first, a peer poll during a slow upload could
    // fetch the message with no attachments and move its cursor past it.
    $messagesDuringUpload = null;
    $this->mock(MedicalFileStorage::class, function ($mock) use (&$messagesDuringUpload) {
        $mock->shouldReceive('store')->once()->andReturnUsing(function () use (&$messagesDuringUpload) {
            $messagesDuringUpload = Message::count();

            return 'message-attachments/scan.pdf';
        });
    });

    $created = $this->actingAs($physician)
        ->post(route('consultations.messaging.store', $session), [
            'message' => 'Here is the scan.',
            'attachments' => [UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf')],
        ])
        ->assertOk()
        ->json('created_message');

    expect($messagesDuringUpload)->toBe(0)
        ->and(Message::count())->toBe(1)
        ->and($created['attachments'])->toHaveCount(1)
        ->and($created['attachments'][0]['file_name'])->toBe('scan.pdf');
});

/*
| The exit signal. sendBeacon cannot set headers, so these POSTs carry the
| CSRF token as a _token form field. The framework skips CSRF under unit
| tests; binding this instance makes every request below go through a real
| check, as in PhysicianIntakePlannedEndTest.
*/

function enforceCsrfForPolling(): void
{
    app()->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
}

it('accepts a beacon-style typing POST with _token and a string is_typing', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = messagePollingScenario();
    enforceCsrfForPolling();

    $peerSeesTyping = fn () => $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->json('peer.is_typing');

    // A form body, no CSRF header, not JSON: what a FormData beacon arrives as.
    $this->actingAs($physician)
        ->withSession(['_token' => 'beacon-token'])
        ->post(route('consultations.messaging.typing', $session), ['_token' => 'beacon-token', 'is_typing' => '1'])
        ->assertOk();
    expect($peerSeesTyping())->toBeTrue();

    $this->actingAs($physician)
        ->withSession(['_token' => 'beacon-token'])
        ->post(route('consultations.messaging.typing', $session), ['_token' => 'beacon-token', 'is_typing' => '0'])
        ->assertOk();
    expect($peerSeesTyping())->toBeFalse();
});

it('accepts a beacon-style offline POST with _token', function () {
    ['physician' => $physician, 'session' => $session] = messagePollingScenario();
    enforceCsrfForPolling();

    $this->actingAs($physician)
        ->withSession(['_token' => 'beacon-token'])
        ->post(route('consultations.messaging.offline', $session), ['_token' => 'beacon-token'])
        ->assertOk();

    expect($physician->fresh()->online_status)->toBe('offline');
});

it('keeps typing and offline CSRF-protected', function (string $routeName, array $fields) {
    ['physician' => $physician, 'session' => $session] = messagePollingScenario();
    enforceCsrfForPolling();

    $this->actingAs($physician)
        ->withSession(['_token' => 'beacon-token'])
        ->post(route($routeName, $session), $fields)
        ->assertStatus(419);
})->with([
    'typing' => ['consultations.messaging.typing', ['is_typing' => '0']],
    'offline' => ['consultations.messaging.offline', []],
]);
