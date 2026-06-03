<?php

declare(strict_types=1);

use Padosoft\Rebel\AdminApi\Models\RiskRule;

it('rejects unauthenticated risk-rule requests with 401', function (): void {
    $this->getJson('/rebel/admin/api/v1/risk-rules')->assertStatus(401);
    $this->postJson('/rebel/admin/api/v1/risk-rules/simulate', [])->assertStatus(401);
});

it('persists a rule as a draft by default', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/risk-rules', [
        'key' => 'high_value',
        'signal' => 'amount',
        'operator' => '>',
        'value' => 1000,
        'action' => 'require_step_up',
        'required_assurance' => 'aal2',
        'phishing_resistant' => true,
    ])
        ->assertStatus(201)
        ->assertJsonPath('rule.key', 'high_value')
        ->assertJsonPath('rule.status', 'draft')
        ->assertJsonPath('rule.value', 1000);

    expect(RiskRule::query()->withoutGlobalScopes()->where('key', 'high_value')->value('status'))->toBe('draft');
});

it('lists persisted rules', function (): void {
    RiskRule::query()->create([
        'key' => 'r1', 'signal' => 'amount', 'operator' => '>', 'value' => '500',
        'action' => 'require_step_up', 'required_assurance' => 'aal2', 'status' => 'active',
    ]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/risk-rules')
        ->assertOk()
        ->assertJsonPath('rules.0.key', 'r1')
        ->assertJsonPath('rules.0.value', 500);
});

it('validates a bad rule payload with 422', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/risk-rules', ['key' => 'x', 'operator' => 'bogus'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');
});

it('simulates active rules over signals (read-only)', function (): void {
    RiskRule::query()->create([
        'key' => 'high_value', 'signal' => 'amount', 'operator' => '>', 'value' => '1000',
        'action' => 'require_step_up', 'required_assurance' => 'aal2', 'phishing_resistant' => true, 'status' => 'active',
    ]);
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/risk-rules/simulate', [
        'signals' => ['amount' => 1500, 'new_device' => true],
    ])
        ->assertOk()
        ->assertJsonPath('decision', 'require_step_up')
        ->assertJsonPath('required_assurance', 'aal2')
        ->assertJsonPath('require_phishing_resistant', true)
        ->assertJsonPath('matched_rules.0', 'high_value');
});

it('simulate allows when no rule matches', function (): void {
    RiskRule::query()->create([
        'key' => 'high_value', 'signal' => 'amount', 'operator' => '>', 'value' => '1000',
        'action' => 'require_step_up', 'required_assurance' => 'aal2', 'status' => 'active',
    ]);
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/risk-rules/simulate', ['signals' => ['amount' => 10]])
        ->assertOk()
        ->assertJsonPath('decision', 'allow')
        ->assertJsonPath('matched_rules', []);
});

it('rejects an invalid simulate payload with 422', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/risk-rules/simulate', ['signals' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_signals');
});

it('scopes rules to a tenant', function (): void {
    RiskRule::query()->withoutGlobalScopes()->create([
        'tenant_id' => 'tenant-a', 'key' => 'a', 'signal' => 'amount', 'operator' => '>',
        'value' => '1', 'action' => 'block', 'status' => 'active',
    ]);
    RiskRule::query()->withoutGlobalScopes()->create([
        'tenant_id' => 'tenant-b', 'key' => 'b', 'signal' => 'amount', 'operator' => '>',
        'value' => '1', 'action' => 'block', 'status' => 'active',
    ]);
    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/risk-rules?tenant=tenant-a')
        ->assertOk()
        ->assertJsonCount(1, 'rules')
        ->assertJsonPath('rules.0.key', 'a');
});
