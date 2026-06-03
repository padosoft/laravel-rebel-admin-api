<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\Rebel\AdminApi\Models\MetricBucket;
use Padosoft\Rebel\Core\Models\RebelAuthEvent;

/**
 * GET {prefix}/health — a quick liveness + freshness probe for the control plane.
 *
 * The admin read models look ACROSS tenants by default (the control plane is operated
 * by a super-admin); pass `?tenant=<id>` to scope to one tenant. The tenant global
 * scope is bypassed so behaviour does not depend on an ambient CurrentTenant.
 */
final class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->string('tenant')->toString();

        $events = RebelAuthEvent::query()->withoutGlobalScopes();
        $buckets = MetricBucket::query()->withoutGlobalScopes();

        if ($tenant !== '') {
            $events->where('tenant_id', $tenant);
            $buckets->where('tenant_id', $tenant);
        }

        return response()->json([
            'status' => 'ok',
            'events_total' => (clone $events)->count(),
            'buckets_total' => (clone $buckets)->count(),
            'last_event_at' => (clone $events)->max('created_at'),
        ]);
    }
}
