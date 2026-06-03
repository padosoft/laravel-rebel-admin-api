<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Padosoft\Rebel\AdminApi\Tests\TestCase;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Contracts\AuditLogger;

uses(TestCase::class)->in(__DIR__);

/**
 * Record an auth event through the core audit logger (shared test helper).
 *
 * @param  array<string, mixed>  $extra
 */
function recordEvent(string $type, ?string $channel = null, array $extra = []): void
{
    app(AuditLogger::class)->record(new AuditEvent(
        type: $type,
        guard: isset($extra['guard']) && is_string($extra['guard']) ? $extra['guard'] : null,
        subjectType: isset($extra['subject_type']) && is_string($extra['subject_type']) ? $extra['subject_type'] : null,
        subjectId: isset($extra['subject_id']) && is_string($extra['subject_id']) ? $extra['subject_id'] : null,
        tenantId: isset($extra['tenant_id']) && is_string($extra['tenant_id']) ? $extra['tenant_id'] : null,
        channel: $channel,
        provider: isset($extra['provider']) && is_string($extra['provider']) ? $extra['provider'] : null,
        purpose: isset($extra['purpose']) && is_string($extra['purpose']) ? $extra['purpose'] : null,
        aal: $extra['aal'] ?? null,
        amr: isset($extra['amr']) && is_array($extra['amr']) ? array_values($extra['amr']) : null,
        metadata: isset($extra['metadata']) && is_array($extra['metadata']) ? $extra['metadata'] : [],
    ));
}

/** Insert a session row directly (mirrors laravel-rebel-sessions). */
function makeSession(string $subjectId, string $status = 'active', ?string $tenant = null): string
{
    $id = (string) Str::uuid();
    DB::table('rebel_sessions')->insert([
        'id' => $id,
        'tenant_id' => $tenant,
        'subject_type' => 'App\\Models\\Customer',
        'subject_id' => $subjectId,
        'type' => 'refresh',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Insert a trusted device row directly (mirrors laravel-rebel-sessions). */
function makeDevice(string $subjectId, bool $trusted = true, ?string $tenant = null): string
{
    $id = (string) Str::uuid();
    DB::table('rebel_devices')->insert([
        'id' => $id,
        'tenant_id' => $tenant,
        'subject_type' => 'App\\Models\\Customer',
        'subject_id' => $subjectId,
        'fingerprint_hash' => hash('sha256', $id),
        'trusted' => $trusted,
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Insert an anomaly case row directly (mirrors laravel-rebel-ai-guard). */
function makeAnomaly(string $type = 'sms_pumping', string $status = 'open', ?string $tenant = null): string
{
    $id = (string) Str::ulid();
    DB::table('rebel_anomaly_cases')->insert([
        'id' => $id,
        'tenant_id' => $tenant,
        'type' => $type,
        'severity' => 'high',
        'status' => $status,
        'dedupe_key' => $type.':'.$id,
        'signals' => json_encode(['prefix' => '+229', 'velocity' => 'x40']),
        'events_count' => 42,
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** A throwaway admin user for the gated routes. */
function adminUser(): GenericUser
{
    return new GenericUser(['id' => 1]);
}

/** Authenticate as an admin who passes the (fail-closed) `rebel-admin` ability. */
function actingAsAdmin(): void
{
    Gate::define('rebel-admin', fn (): bool => true);
    test()->actingAs(adminUser());
}
