<?php

declare(strict_types=1);

it('rejects unauthenticated channel/provider requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/channels/performance')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/providers/health')->assertStatus(401);
});

it('returns per-channel performance rows without fabricating cost/latency', function (): void {
    recordEvent('email_otp.sent', 'sms');
    recordEvent('email_otp.sent', 'sms');
    recordEvent('email_otp.verified', 'sms');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/channels/performance?days=1')
        ->assertOk()
        ->assertJsonPath('rows.0.channel', 'sms')
        ->assertJsonPath('rows.0.sent', 2)
        ->assertJsonPath('rows.0.verify_conversion', 0.5)
        ->assertJsonPath('rows.0.cost_amount', null)
        ->assertJsonPath('rows.0.latency_p95_ms', null);
});

it('returns an honest empty channels structure with no data', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/channels/performance?days=1')
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonPath('timeseries', []);
});

it('reports providers as healthy by default', function (): void {
    recordEvent('email_otp.sent', 'sms', ['provider' => 'twilio']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/providers/health?days=1')
        ->assertOk()
        ->assertJsonPath('providers.0.key', 'twilio')
        ->assertJsonPath('providers.0.status', 'healthy');
});
