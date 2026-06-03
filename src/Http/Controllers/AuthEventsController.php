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
 *
 * `show` returns a single sanitized event for the §3.5 detail drawer — metadata is
 * surfaced but any sensitive key (OTP, secret, token, code, …) is redacted, never returned.
 */
final class AuthEventsController
{
    /** Metadata keys that must never leave the API, even though they are not stored in clear. */
    private const REDACTED_KEYS = ['otp', 'code', 'secret', 'token', 'password', 'pin', 'challenge'];

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
            'aal', 'amr', 'risk_score', 'identifier_hmac', 'ip_hmac', 'user_agent_hash', 'country', 'created_at',
        ]);

        $last = $events->last();

        return response()->json([
            'data' => $events,
            'per_page' => $perPage,
            'next_before' => $last?->getAttribute('created_at'),
            'next_before_id' => $last?->getAttribute('id'),
        ]);
    }

    /** GET {prefix}/auth-events/{id} — the sanitized detail of one event. */
    public function show(Request $request, string $id): JsonResponse
    {
        $query = RebelAuthEvent::query()->withoutGlobalScopes()->whereKey($id);

        $tenant = $request->string('tenant')->toString();
        if ($tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        $event = $query->first();

        if ($event === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($event->getAttribute('metadata')) ? $event->getAttribute('metadata') : [];

        return response()->json([
            'data' => [
                'id' => $event->getAttribute('id'),
                'event_type' => $event->getAttribute('event_type'),
                'guard' => $event->getAttribute('guard'),
                'subject_type' => $event->getAttribute('subject_type'),
                'channel' => $event->getAttribute('channel'),
                'provider' => $event->getAttribute('provider'),
                'purpose' => $event->getAttribute('purpose'),
                'aal' => $event->getAttribute('aal'),
                'amr' => $event->getAttribute('amr'),
                'risk_score' => $event->getAttribute('risk_score'),
                'identifier_hmac' => $event->getAttribute('identifier_hmac'),
                'ip_hmac' => $event->getAttribute('ip_hmac'),
                'user_agent_hash' => $event->getAttribute('user_agent_hash'),
                'country' => $event->getAttribute('country'),
                'created_at' => $event->getAttribute('created_at'),
                'metadata' => $this->sanitize($metadata),
            ],
        ]);
    }

    /**
     * Redact any sensitive key (recursively) so a one-time secret never leaks through metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            $sensitive = false;
            foreach (self::REDACTED_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $sensitive = true;
                    break;
                }
            }

            if ($sensitive) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $clean[$key] = $this->sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
