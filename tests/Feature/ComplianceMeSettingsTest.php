<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\Rebel\Core\Assurance\Aal;

it('rejects unauthenticated compliance/me/settings requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/compliance/overview')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/me')->assertStatus(401);
    $this->getJson('/rebel/admin/api/v1/settings')->assertStatus(401);
});

it('reports the NIST AAL distribution and GDPR retention tiers', function (): void {
    recordEvent('login.succeeded', null, ['aal' => Aal::Aal1]);
    recordEvent('login.succeeded', null, ['aal' => Aal::Aal2]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/compliance/overview?days=1')
        ->assertOk()
        ->assertJsonPath('nist.aal_distribution.aal1', 0.5)
        ->assertJsonPath('nist.aal_distribution.aal2', 0.5)
        ->assertJsonStructure(['psd2' => ['sca_events', 'dynamic_linked', 'exemptions'], 'gdpr' => ['retention_tiers', 'pending_erasures']]);
});

it('counts PSD2 SCA events from verified step-up challenges', function (): void {
    DB::table('rebel_step_up_challenges')->insert([
        'id' => (string) Str::ulid(),
        'subject_type' => 'App\\Models\\Customer', 'subject_id' => '1',
        'purpose' => 'checkout', 'required_assurance' => 'aal2', 'selected_driver' => 'email_otp',
        'binding_hash' => str_repeat('a', 64), 'status' => 'verified',
        'expires_at' => now()->addHour(), 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/compliance/overview?days=1')
        ->assertOk()
        ->assertJsonPath('psd2.sca_events', 1)
        ->assertJsonPath('psd2.dynamic_linked', 1);
});

it('returns the current admin identity and permissions', function (): void {
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/me')
        ->assertOk()
        ->assertJsonPath('id', '1')
        ->assertJsonStructure(['permissions']);
});

it('grants the standard permission set when the user passes rebel-admin', function (): void {
    actingAsAdmin();

    $response = $this->getJson('/rebel/admin/api/v1/me')->assertOk();
    expect($response->json('permissions'))->toContain('rebel-admin.view');
});

it('stores and lists a generic setting', function (): void {
    actingAsAdmin();

    $this->putJson('/rebel/admin/api/v1/settings/sms_threshold', ['value' => ['min' => 0.5]])
        ->assertOk()
        ->assertJsonPath('key', 'sms_threshold')
        ->assertJsonPath('value.min', 0.5);

    $this->getJson('/rebel/admin/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('settings.sms_threshold.min', 0.5);
});
