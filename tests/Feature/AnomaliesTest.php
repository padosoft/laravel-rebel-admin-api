<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('rejects unauthenticated anomaly requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/anomalies')->assertStatus(401);
});

it('lists anomaly cases with cursor meta', function (): void {
    makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'sms_pumping')
        ->assertJsonStructure(['meta' => ['next_cursor', 'has_more']]);
});

it('filters anomalies by status', function (): void {
    makeAnomaly('sms_pumping', 'open');
    makeAnomaly('otp_bombing', 'closed');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies?status=open')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'sms_pumping');
});

it('lists signals in the summary payload (the panel drawer reads the list)', function (): void {
    makeAnomaly('otp_bombing', 'open');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies')
        ->assertOk()
        ->assertJsonPath('data.0.signals.prefix', '+229');
});

it('delegation anomaly cases round-trip signals and carry suspend-agent actions', function (): void {
    $id = makeAnomaly('delegation_scope_probing', 'open', signals: [
        'agent_id' => 'agt_probe',
        'events' => 12,
        'refusal_reasons' => ['delegation_grant_missing' => 9, 'empty_scope_intersection' => 3],
        'auto_suspended' => true,
    ]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies?type=delegation_scope_probing')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.signals.agent_id', 'agt_probe');

    $this->getJson('/rebel/admin/api/v1/anomalies/'.$id)
        ->assertOk()
        ->assertJsonPath('signals.refusal_reasons.delegation_grant_missing', 9)
        ->assertJsonPath('signals.auto_suspended', true)
        ->assertJsonPath('suggested_actions.0.key', 'suspend_agent')
        ->assertJsonPath('suggested_actions.0.destructive', true);
});

it('shows an anomaly case with signals and suggested actions', function (): void {
    $id = makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies/'.$id)
        ->assertOk()
        ->assertJsonPath('type', 'sms_pumping')
        ->assertJsonPath('signals.prefix', '+229')
        ->assertJsonPath('suggested_actions.0.key', 'block_prefix');
});

it('acknowledges a case and audits it', function (): void {
    $id = makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/anomalies/'.$id.'/actions', ['action' => 'acknowledge'])
        ->assertOk()
        ->assertJsonPath('action', 'acknowledge');

    expect(DB::table('rebel_anomaly_cases')->where('id', $id)->value('status'))->toBe('acknowledged');
    expect(DB::table('rebel_auth_events')->where('event_type', 'admin.anomaly.acknowledge')->exists())->toBeTrue();
});

it('requires confirmation for a destructive mitigation', function (): void {
    $id = makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/anomalies/'.$id.'/actions', ['action' => 'mitigate'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'confirmation_required');

    $this->postJson('/rebel/admin/api/v1/anomalies/'.$id.'/actions', ['action' => 'mitigate', 'confirm' => true])
        ->assertOk();
});

it('returns 404 for an unknown case', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/anomalies/missing')->assertStatus(404);
});
