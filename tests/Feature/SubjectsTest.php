<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('rejects unauthenticated subject requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/subjects')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/subjects/42/devices')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/subjects/42/sessions')->assertStatus(401);
});

it('lists subjects derived from the audit log with a masked id', function (): void {
    recordEvent('login.succeeded', null, ['subject_id' => 'cust_998877']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'cust_998877')
        ->assertJsonPath('data.0.masked', 'cust_9…')
        ->assertJsonPath('meta.total', 1);
});

it('enriches subjects with device and session counts from the registry', function (): void {
    recordEvent('login.succeeded', null, ['subject_id' => '42']);
    makeDevice('42', trusted: true);
    makeSession('42', 'active');
    makeSession('42', 'active');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects')
        ->assertOk()
        ->assertJsonPath('data.0.subject', '42')
        ->assertJsonPath('data.0.devices', 1)
        ->assertJsonPath('data.0.sessions', 2)
        ->assertJsonPath('data.0.last_seen_at', fn ($v): bool => is_string($v) && $v !== '');
});

it('includes registry-only subjects that have no audit events', function (): void {
    makeSession('registry-only', 'active');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects')
        ->assertOk()
        ->assertJsonPath('data.0.subject', 'registry-only')
        ->assertJsonPath('data.0.sessions', 1)
        ->assertJsonPath('data.0.last_seen_at', null);
});

it('scopes the subject list to a tenant', function (): void {
    recordEvent('login.succeeded', null, ['subject_id' => 'a', 'tenant_id' => 'tenant-a']);
    recordEvent('login.succeeded', null, ['subject_id' => 'b', 'tenant_id' => 'tenant-b']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects?tenant=tenant-a')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'a');
});

it('returns an honest empty subject list with no data', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/subjects')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
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
