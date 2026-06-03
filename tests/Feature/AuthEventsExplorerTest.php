<?php

declare(strict_types=1);

it('lists recent auth events for an admin', function (): void {
    recordEvent('login.succeeded');
    recordEvent('login.failed');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/auth-events')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters by event type', function (): void {
    recordEvent('login.succeeded');
    recordEvent('login.failed');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/auth-events?type=login.failed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_type', 'login.failed');
});

it('rejects an invalid before cursor with 422', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/auth-events?before=not-a-real-date')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_before');
});
