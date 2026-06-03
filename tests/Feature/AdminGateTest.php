<?php

declare(strict_types=1);

it('rejects unauthenticated requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/health')
        ->assertStatus(401)
        ->assertJsonPath('error', 'unauthenticated');
});

it('serves health to an authenticated admin', function (): void {
    recordEvent('login.succeeded');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('events_total', 1);
});

it('is fail-closed: an authenticated user without the ability is forbidden', function (): void {
    // The default ability is 'rebel-admin'; with no Gate defined it denies.
    $this->actingAs(adminUser());

    $this->getJson('/rebel/admin/api/v1/health')
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');
});
