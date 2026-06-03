<?php

declare(strict_types=1);

use Padosoft\Rebel\Core\Models\RebelAuthEvent;

it('returns a sanitized event detail and redacts secrets', function (): void {
    recordEvent('email_otp.verified', 'email', ['metadata' => ['otp' => '123456', 'ip_city' => 'Rome']]);
    actingAsAdmin();

    $id = RebelAuthEvent::query()->withoutGlobalScopes()->value('id');

    $this->getJson('/rebel/admin/api/v1/auth-events/'.$id)
        ->assertOk()
        ->assertJsonPath('data.event_type', 'email_otp.verified')
        ->assertJsonPath('data.metadata.otp', '[redacted]')
        ->assertJsonPath('data.metadata.ip_city', 'Rome');
});

it('exposes the captured country and user-agent hash in the detail', function (): void {
    recordEvent('login.succeeded', null, ['guard' => 'web', 'country' => 'IT']);
    actingAsAdmin();

    $id = RebelAuthEvent::query()->withoutGlobalScopes()->value('id');

    $this->getJson('/rebel/admin/api/v1/auth-events/'.$id)
        ->assertOk()
        ->assertJsonPath('data.country', 'IT')
        ->assertJsonPath('data.event_type', 'login.succeeded');
});

it('returns 404 for an unknown event', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/auth-events/missing')
        ->assertStatus(404)
        ->assertJsonPath('error', 'not_found');
});

it('rejects unauthenticated detail requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/auth-events/whatever')->assertStatus(401);
});
