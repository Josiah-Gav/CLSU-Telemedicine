<?php

use App\Models\User;

/*
| The common-reason checkboxes on the new-consultation form (step 2).
|
| They are a convenience for filling the existing online_reason textarea and
| nothing more: they carry no name attribute, so they are never submitted and
| never stored, and online_reason stays the single value the backend receives.
| ConsultationController::store()'s validation is therefore untouched by this
| feature — the rules covered in ConsultationIntakeGateTest and
| ConsultationSymptomOnsetDateTest still apply unchanged.
|
| The toggle behaviour itself is Alpine/client-side, so a PHP feature test
| cannot drive it. These tests guard the two things PHP can see: that the
| section renders on both routes that serve this form, and that the checkboxes
| stay submission-free and wired to the toggle rather than to a form field.
*/

function commonReasonsPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

it('renders every common reason on both routes that serve the form', function (string $route) {
    $response = $this->actingAs(commonReasonsPatient())
        ->get(route($route))
        ->assertOk();

    $response->assertSee('Common reasons for online consultation');

    foreach ([
        'No available transportation',
        'Flooding or severe weather',
        "It's late at night",
        'Unable to travel to the infirmary',
        'Difficulty moving or walking',
        'Bedridden',
        'No available companion',
        'Currently off campus',
        'Class or work commitments',
        'Infirmary temporarily unavailable',
    ] as $reason) {
        $response->assertSee($reason, false);
    }
})->with(['newconsultation', 'consultations.create']);

it('keeps the reason textarea as the only submitted reason value', function () {
    $response = $this->actingAs(commonReasonsPatient())
        ->get(route('newconsultation'))
        ->assertOk();

    // The checkboxes toggle Alpine state; they are deliberately nameless so a
    // submission carries the composed textarea value and nothing else.
    $response->assertSee('@change="toggleCommonReason(reason)"', false)
        ->assertSee('name="online_reason"', false)
        ->assertSee('x-model="onlineReason"', false);

    expect(substr_count($response->getContent(), 'name="online_reason"'))->toBe(1);
    expect($response->getContent())->not->toContain('name="common_reason');
});

it('leaves the reason textarea editable and required', function () {
    // The patient's own wording has priority over anything the checkboxes
    // insert, so the field must never become readonly or disabled, and the
    // existing required rule must survive the new section above it.
    $content = $this->actingAs(commonReasonsPatient())
        ->get(route('newconsultation'))
        ->assertOk()
        ->getContent();

    $textarea = substr($content, strpos($content, '<textarea id="online_reason"'), 400);

    expect($textarea)->toContain('required')
        ->and($textarea)->not->toContain('readonly')
        ->and($textarea)->not->toContain('disabled');
});
