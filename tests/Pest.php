<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Padosoft\Rebel\AdminApi\Tests\TestCase;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Contracts\AuditLogger;

uses(TestCase::class)->in(__DIR__);

/** Record an auth event through the core audit logger (shared test helper). */
function recordEvent(string $type, ?string $channel = null): void
{
    app(AuditLogger::class)->record(new AuditEvent(type: $type, channel: $channel));
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
