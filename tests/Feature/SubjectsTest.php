<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('rejects unauthenticated subject requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/subjects/42/devices')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/subjects/42/sessions')->assertStatus(401);
});

it('lists a subject devices with truncated fingerprint', function (): void {
    makeDevice('42', trusted: true);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects/42/devices')
        ->assertOk()
        ->assertJsonCount(1, 'devices')
        ->assertJsonPath('devices.0.trusted', true);
});

it('lists a subject sessions', function (): void {
    makeSession('42', 'active');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects/42/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'sessions')
        ->assertJsonPath('sessions.0.status', 'active');
});

it('revokes one session and audits it', function (): void {
    $id = makeSession('42', 'active');
    actingAsAdmin();

    $this->postJson("/rebel/admin/api/v1/subjects/42/sessions/{$id}/revoke")
        ->assertOk()
        ->assertJsonPath('revoked', true);

    expect(DB::table('rebel_sessions')->where('id', $id)->value('status'))->toBe('revoked');
    expect(DB::table('rebel_auth_events')->where('event_type', 'admin.session.revoked')->exists())->toBeTrue();
});

it('logs a subject out everywhere', function (): void {
    makeSession('42', 'active');
    makeSession('42', 'active');
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/subjects/42/logout-everywhere')
        ->assertOk()
        ->assertJsonPath('revoked', 2);
});

it('untrusts a device', function (): void {
    $id = makeDevice('42', trusted: true);
    actingAsAdmin();

    $this->postJson("/rebel/admin/api/v1/subjects/42/devices/{$id}/untrust")
        ->assertOk()
        ->assertJsonPath('untrusted', true);

    expect((bool) DB::table('rebel_devices')->where('id', $id)->value('trusted'))->toBeFalse();
});

it('returns 404 revoking an unknown session', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/subjects/42/sessions/missing/revoke')
        ->assertStatus(404);
});

it('scopes devices to a tenant', function (): void {
    makeDevice('42', trusted: true, tenant: 'tenant-a');
    makeDevice('42', trusted: true, tenant: 'tenant-b');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects/42/devices?tenant=tenant-a')
        ->assertOk()
        ->assertJsonCount(1, 'devices');
});
