<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Padosoft\Rebel\AdminApi\Http\Concerns\ResolvesTenant;
use Padosoft\Rebel\AdminApi\Support\Period;
use Psr\Clock\ClockInterface;

/**
 * GET {prefix}/providers/health — per-provider health rows for §3.4.
 *
 * A small read model over the providers seen in `rebel_auth_events`: a provider is healthy
 * by default, and "recent_errors" surfaces any failure events normalised by provider. Uptime
 * is reported as 100% until a failure is observed (no synthetic incident data is invented).
 */
final class ProvidersController
{
    use ResolvesTenant;

    public function __construct(private readonly ClockInterface $clock) {}

    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $period = Period::fromRequest($request, $now);
        $tenant = $this->tenant($request);

        $rows = $this->baseQuery($period, $tenant)
            ->whereNotNull('provider')
            ->groupBy('provider', 'event_type')
            ->selectRaw('provider, event_type, COUNT(*) as total')
            ->get();

        /** @var array<string, array{total: int, failed: int}> $byProvider */
        $byProvider = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $provider = is_string($data['provider'] ?? null) ? $data['provider'] : null;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : '';
            $total = $data['total'] ?? 0;
            $count = is_numeric($total) ? (int) $total : 0;
            if ($provider === null) {
                continue;
            }

            $byProvider[$provider] ??= ['total' => 0, 'failed' => 0];
            $byProvider[$provider]['total'] += $count;
            if (str_contains($type, 'failed')) {
                $byProvider[$provider]['failed'] += $count;
            }
        }

        $providers = [];
        foreach ($byProvider as $key => $stats) {
            $errorRate = $stats['total'] > 0 ? round($stats['failed'] / $stats['total'], 4) : 0.0;
            $providers[] = [
                'key' => $key,
                'status' => $this->status($errorRate),
                'uptime_pct' => round((1 - $errorRate) * 100, 2),
                'error_rate' => $errorRate,
                'latency_p95_ms' => null,
                'checked_at' => $now->toIso8601String(),
                'recent_errors' => $stats['failed'] > 0
                    ? [['code' => 'delivery_failed', 'message' => 'Delivery failed', 'count' => $stats['failed']]]
                    : [],
            ];
        }

        return response()->json(['providers' => $providers]);
    }

    private function status(float $errorRate): string
    {
        return match (true) {
            $errorRate >= 0.25 => 'down',
            $errorRate >= 0.05 => 'degraded',
            default => 'healthy',
        };
    }

    private function baseQuery(Period $period, ?string $tenant): Builder
    {
        $query = DB::table('rebel_auth_events')
            ->where('created_at', '>=', $period->from->format('Y-m-d H:i:s'))
            ->where('created_at', '<=', $period->to->format('Y-m-d H:i:s'));

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        return $query;
    }
}
