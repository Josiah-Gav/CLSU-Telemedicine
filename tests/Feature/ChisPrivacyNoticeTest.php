<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('has no consent routes any more', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    expect(Route::has('profile.chis-consent.store'))->toBeFalse()
        ->and(Route::has('profile.chis-consent.destroy'))->toBeFalse();

    $this->actingAs($patient)->post('/profile/chis-consent')->assertNotFound();
    $this->actingAs($patient)->delete('/profile/chis-consent')->assertNotFound();
    expect($patient->refresh()->chis_consent_at)->toBeNull();
});

it('shows the privacy notice to patients, without any consent button', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $this->actingAs($patient)->get('/profile')
        ->assertOk()
        ->assertSee('How your records are shared with CHIS')
        ->assertSee('Section 13')
        ->assertSee('medical treatment')
        ->assertSee('What is never sent')
        ->assertSee('Data Protection Officer')
        ->assertSee('Consultations completed earlier may also be sent')
        ->assertSee('every view is logged')
        ->assertDontSee('I consent')
        ->assertDontSee('Withdraw consent')
        ->assertDontSee('Sharing is off');
});

it('shows the privacy notice to patients only', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))->get('/profile')
        ->assertOk()
        ->assertDontSee('How your records are shared with CHIS');
})->with(['nurse', 'physician', 'admin']);

it('tells the patient about CHIS where they submit a consultation request', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $this->actingAs($patient)->get(route('newconsultation'))
        ->assertOk()
        ->assertSee("A summary of this consultation will be recorded in CHIS, CLSU's Comprehensive Health Information System.", false)
        ->assertSee('#chis-privacy-notice-heading', false);
});

it('describes the CHIS sharing in the public privacy policy', function () {
    $this->get(route('privacy.policy'))
        ->assertOk()
        ->assertSee('Sharing Consultation Summaries with CHIS')
        ->assertSee('Section 13 of the Data Privacy Act')
        ->assertSee('What is never sent')
        ->assertSee('Data Protection Officer');
});
