<?php

declare(strict_types=1);

it('rejects unauthenticated AI requests with 401', function (): void {
    $this->postJson('/rebel/admin/api/v1/ai/policies/suggest', [])->assertStatus(401);
});

it('explains an anomaly with a deterministic fallback when no AI is bound', function (): void {
    $id = makeAnomaly('sms_pumping', 'open');
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/ai/anomalies/'.$id.'/explain')
        ->assertOk()
        ->assertJsonPath('confidence', 'low')
        ->assertJsonPath('sources.0', 'rule-engine')
        ->assertJsonStructure(['explanation']);
});

it('returns 404 explaining an unknown case', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/ai/anomalies/missing/explain')->assertStatus(404);
});

it('suggests a draft policy that is never auto-applied', function (): void {
    actingAsAdmin();

    $this->postJson('/rebel/admin/api/v1/ai/policies/suggest', ['signals' => ['risk_score' => 80]])
        ->assertOk()
        ->assertJsonPath('draft_rule.status', 'draft')
        ->assertJsonStructure(['draft_rule' => ['key', 'signal', 'operator', 'value', 'action'], 'rationale']);
});
