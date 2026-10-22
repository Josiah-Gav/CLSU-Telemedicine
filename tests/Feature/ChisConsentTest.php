<?php

use App\Models\User;
use Illuminate\Support\Carbon;

it('lets a patient give and withdraw consent', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    expect($patient->hasChisConsent())->toBeFalse();

    $this->actingAs($patient)->post(route('profile.chis-consent.store'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'chis-consent-given');
    expect($patient->refresh()->hasChisConsent())->toBeTrue();

    $this->actingAs($patient)->delete(route('profile.chis-consent.destroy'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'chis-consent-withdrawn');
    expect($patient->refresh()->hasChisConsent())->toBeFalse();
});

it('keeps the original timestamp when consent is given twice', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    Carbon::setTestNow('2026-10-01 09:00:00');
    $this->actingAs($patient)->post(route('profile.chis-consent.store'));

    Carbon::setTestNow('2026-10-20 15:00:00');
    $this->actingAs($patient)->post(route('profile.chis-consent.store'));
    Carbon::setTestNow();

    expect($patient->refresh()->chis_consent_at->toDateTimeString())->toBe('2026-10-01 09:00:00');
});

it('forbids consent changes for non-patients', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->post(route('profile.chis-consent.store'))->assertForbidden();
    $this->actingAs($user)->delete(route('profile.chis-consent.destroy'))->assertForbidden();

    expect($user->refresh()->chis_consent_at)->toBeNull();
})->with(['nurse', 'physician', 'admin']);

it('redirects guests to login', function () {
    $this->post(route('profile.chis-consent.store'))->assertRedirect(route('login'));
    $this->delete(route('profile.chis-consent.destroy'))->assertRedirect(route('login'));
});

it('shows the consent section to patients only, with the current state', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $this->actingAs($patient)->get('/profile')
        ->assertSee('Share records with CHIS')
        ->assertSee('Sharing is off')
        ->assertSee('What is never shared')
        ->assertSee('summaries already sent remain in CHIS');

    $patient->forceFill(['chis_consent_at' => '2026-10-01 09:00:00'])->save();

    $this->actingAs($patient)->get('/profile')
        ->assertSee('Sharing is on')
        ->assertSee('October 1, 2026');

    foreach (['nurse', 'physician', 'admin'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get('/profile')
            ->assertOk()
            ->assertDontSee('Share records with CHIS');
    }
});
