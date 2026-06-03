<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Contracts\AuditLogger;

/**
 * Records control-plane actions (session revoke, device untrust, anomaly mitigation, …)
 * into the same audit trail the read models observe. Every mutating admin endpoint funnels
 * through here so operator actions are themselves attributable and reviewable — and never
 * carry secrets in their metadata.
 */
final readonly class AdminAudit
{
    public function __construct(private AuditLogger $logger) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(string $action, ?Authenticatable $actor, ?string $tenantId, array $metadata = []): void
    {
        $actorId = $actor?->getAuthIdentifier();

        $this->logger->record(new AuditEvent(
            type: 'admin.'.$action,
            guard: 'rebel-admin',
            tenantId: $tenantId,
            metadata: array_merge($metadata, [
                'actor_id' => is_scalar($actorId) ? (string) $actorId : null,
            ]),
        ));
    }
}
