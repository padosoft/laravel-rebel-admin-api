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
        ->assertJsonPath('rows.0.delivered_rate', null)
        ->assertJsonPath('rows.0.fallback_rate', null)
        ->assertJsonPath('rows.0.cost_amount', null)
        ->assertJsonPath('rows.0.latency_p95_ms', null);
});

it('counts *.requested events as sends and reports real verify conversion', function (): void {
    // A real OTP flow: two emails requested, one verified → sent=2, conversion=0.5.
    recordEvent('email_otp.requested', 'email');
    recordEvent('email_otp.requested', 'email');
    recordEvent('email_otp.verified', 'email');
    actingAsAdmin();

    $response = $this->getJson('/rebel/admin/api/v1/channels/performance?days=1')
        ->assertOk()
        ->assertJsonPath('rows.0.channel', 'email')
        ->assertJsonPath('rows.0.sent', 2)
        ->assertJsonPath('rows.0.verify_conversion', 0.5);

    // Timeseries carries real per-bucket sent counts for the channel.
    $totalSent = collect($response->json('timeseries'))
        ->where('channel', 'email')
        ->sum('sent');
    expect($totalSent)->toBe(2);
});

it('picks the most common provider per channel and aggregates SMS sends', function (): void {
    recordEvent('sms_otp.sent', 'sms', ['provider' => 'twilio']);
    recordEvent('sms_otp.sent', 'sms', ['provider' => 'twilio']);
    recordEvent('sms_otp.sent', 'sms', ['provider' => 'vonage']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/channels/performance?days=1')
        ->assertOk()
        ->assertJsonPath('rows.0.channel', 'sms')
        ->assertJsonPath('rows.0.sent', 3)
        ->assertJsonPath('rows.0.provider', 'twilio');
});

it('scopes channel performance to a tenant', function (): void {
    recordEvent('email_otp.sent', 'email', ['tenant_id' => 'tenant-a']);
    recordEvent('email_otp.sent', 'email', ['tenant_id' => 'tenant-b']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/channels/performance?days=1&tenant=tenant-a')
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.sent', 1);
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
