<?php

it('describes the system as it is now on the privacy policy page', function () {
    $this->get(route('privacy.policy'))
        ->assertOk()
        ->assertSee('October 13, 2026')
        ->assertSee('role-played')
        ->assertSee('simulator')
        ->assertSee('Data Protection Officer')
        ->assertSee('Sharing Consultation Summaries with CHIS')
        ->assertSeeInOrder(['Data Retention', 'Railway database', 'Cloudinary', 'SendGrid'])
        ->assertDontSee('current five-day')
        ->assertDontSee('actual use')
        ->assertDontSee('09126153860')
        ->assertDontSee('withdraw from CHIS')
        ->assertDontSee('opt out');
});

it('names the third-party providers the code uses', function () {
    $this->get(route('privacy.policy'))
        ->assertSee('Railway')
        ->assertSee('SendGrid')
        ->assertSee('Cloudinary')
        ->assertSee('8x8 Jitsi as a Service');
});

it('shows the registration notice as an acknowledgement, not a consent', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('October 13, 2026')
        ->assertSee('CHIS is a simulator operated by the researchers')
        ->assertSee('I have read and understood the Privacy Policy.')
        ->assertDontSee('5-day live testing period')
        ->assertDontSee('agree to the collection and processing')
        ->assertDontSee('actual use');
});
