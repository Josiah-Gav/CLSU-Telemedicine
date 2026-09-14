<?php

use App\Models\User;

/**
 * Phase 2 (IA/UX): the patient dashboard used to lead with general service
 * information (intake availability, this week's hours) and said nothing at
 * all when a patient had no consultation in progress — a dead end for a
 * first-time user. The patient's own situation (their current consultation,
 * or an explicit "nothing in progress, here's what to do" state) now comes
 * first; general/secondary information (hours) moved to the bottom.
 */
it('puts the patient\'s own situation before general service information in the page', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $response = $this->actingAs($patient)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder([
        "You don't have a consultation in progress.",
        "This Week's Consultation Hours",
    ], false);
});

it('shows a clear next action with no dead end when the patient has no consultation in progress', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $response = $this->actingAs($patient)->get(route('dashboard'));

    $response->assertOk();
    // Default escaping (no `false` flag): Blade's {{ }} HTML-escapes the
    // apostrophe to &#039;, so the raw literal string never appears in the
    // response — assertSee's default $escape=true applies the same e()
    // transform to this expectation before comparing, matching what
    // assertSeeInOrder does implicitly via html_entity_decode() above.
    $response->assertSee("You don't have a consultation in progress.");
    $response->assertSee('Request a Consultation', false);
    $response->assertSee(route('newconsultation'), false);
});

it('links to the consultation history page from the dashboard', function () {
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $response = $this->actingAs($patient)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(route('consultations.history'), false);
});
