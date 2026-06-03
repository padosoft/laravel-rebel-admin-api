<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\Rebel\Core\Models\RebelAuthEvent;

/**
 * GET {prefix}/auth-events — a filterable, most-recent-first explorer over the audit
 * log. Returns only non-sensitive columns (identifiers are already HMAC'd at rest).
 *
 * Looks across tenants by default; pass `?tenant=<id>` to scope. Keyset pagination uses
 * a compound (created_at, id) cursor so rows sharing a timestamp are never skipped.
 */
final class AuthEventsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $query = RebelAuthEvent::query()
            ->withoutGlobalScopes()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $tenant = $request->string('tenant')->toString();
        if ($tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        foreach (['event_type' => 'type', 'guard' => 'guard', 'channel' => 'channel', 'provider' => 'provider'] as $column => $param) {
            $value = $request->string($param)->toString();
            if ($value !== '') {
                $query->where($column, $value);
            }
        }

        $before = $request->string('before')->toString();
        if ($before !== '') {
            try {
                $beforeAt = CarbonImmutable::parse($before)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return response()->json(['error' => 'invalid_before'], 422);
            }

            $beforeId = $request->string('before_id')->toString();
            $query->where(function (Builder $cursor) use ($beforeAt, $beforeId): void {
                $cursor->where('created_at', '<', $beforeAt);
                if ($beforeId !== '') {
                    $cursor->orWhere(function (Builder $tie) use ($beforeAt, $beforeId): void {
                        $tie->where('created_at', $beforeAt)->where('id', '<', $beforeId);
                    });
                }
            });
        }

        $events = $query->limit($perPage)->get([
            'id', 'event_type', 'guard', 'channel', 'provider', 'purpose',
            'aal', 'amr', 'risk_score', 'identifier_hmac', 'created_at',
        ]);

        $last = $events->last();

        return response()->json([
            'data' => $events,
            'per_page' => $perPage,
            'next_before' => $last?->getAttribute('created_at'),
            'next_before_id' => $last?->getAttribute('id'),
        ]);
    }
}
