<?php

declare(strict_types=1);

use Padosoft\Rebel\Core\Assurance\Aal;

it('rejects unauthenticated funnel requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/otp/funnel')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/step-up/funnel')->assertStatus(401);
});

it('computes the OTP funnel stages', function (): void {
    recordEvent('email_otp.requested', 'email');
    recordEvent('email_otp.sent', 'email');
    recordEvent('email_otp.sent', 'email');
    recordEvent('email_otp.verified', 'email');
    recordEvent('login.succeeded');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/otp/funnel?days=1')
        ->assertOk()
        ->assertJsonPath('stages.0.key', 'start')
        ->assertJsonPath('stages.0.count', 1)
        ->assertJsonPath('stages.1.count', 2)
        ->assertJsonPath('stages.3.count', 1)
        ->assertJsonPath('stages.4.count', 1);
});

it('breaks the step-up funnel down per purpose', function (): void {
    recordEvent('step_up.required', null, ['purpose' => 'checkout']);
    recordEvent('step_up.required', null, ['purpose' => 'checkout']);
    recordEvent('step_up.verified', null, ['purpose' => 'checkout', 'aal' => Aal::Aal2]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/step-up/funnel?days=1')
        ->assertOk()
        ->assertJsonPath('by_purpose.0.purpose', 'checkout')
        ->assertJsonPath('by_purpose.0.required', 2)
        ->assertJsonPath('by_purpose.0.verified', 1)
        ->assertJsonPath('by_purpose.0.rate', 0.5)
        ->assertJsonPath('by_purpose.0.avg_assurance', 'aal2');
});

it('returns an empty step-up funnel with no data', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/step-up/funnel?days=1')
        ->assertOk()
        ->assertJsonPath('by_purpose', []);
});
