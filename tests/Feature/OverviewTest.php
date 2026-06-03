<?php

declare(strict_types=1);

use Padosoft\Rebel\Core\Assurance\Aal;

it('rejects unauthenticated overview requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/security/overview')
        ->assertStatus(401)
        ->assertJsonPath('error', 'unauthenticated');
});

it('returns the full KPI shape for an admin', function (): void {
    recordEvent('login.succeeded');
    recordEvent('login.failed');
    recordEvent('email_otp.sent', 'email');
    recordEvent('email_otp.verified', 'email');
    recordEvent('step_up.required');
    recordEvent('step_up.verified', null, ['aal' => Aal::Aal2]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/security/overview?days=1')
        ->assertOk()
        ->assertJsonStructure([
            'period', 'generated_at',
            'kpis' => [
                'login_requests' => ['value', 'delta_pct', 'sparkline'],
                'otp_sent' => ['value', 'delta_pct', 'sparkline'],
                'otp_verified' => ['value', 'rate', 'delta_pct', 'sparkline'],
                'step_up_required' => ['value', 'delta_pct', 'sparkline'],
                'step_up_verified' => ['value', 'rate', 'sparkline'],
                'high_risk_events' => ['value', 'delta_pct', 'sparkline'],
            ],
            'timeseries', 'open_anomalies', 'providers',
        ])
        ->assertJsonPath('kpis.login_requests.value', 2)
        ->assertJsonPath('kpis.otp_sent.value', 1)
        ->assertJsonPath('kpis.otp_verified.value', 1);
});

it('is empty-state safe with no events', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/security/overview?days=1')
        ->assertOk()
        ->assertJsonPath('kpis.login_requests.value', 0)
        ->assertJsonPath('open_anomalies', []);
});

it('lists open anomalies in the overview when present', function (): void {
    makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/security/overview?days=1')
        ->assertOk()
        ->assertJsonPath('open_anomalies.0.type', 'sms_pumping');
});

it('scopes the overview to a tenant', function (): void {
    recordEvent('login.succeeded', null, ['tenant_id' => 'tenant-a']);
    recordEvent('login.succeeded', null, ['tenant_id' => 'tenant-b']);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/security/overview?days=1&tenant=tenant-a')
        ->assertOk()
        ->assertJsonPath('kpis.login_requests.value', 1);
});
