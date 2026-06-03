<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\Rebel\AdminApi\Models\MetricBucket;
use Psr\Clock\ClockInterface;

/**
 * GET {prefix}/security/overview?days=7 — totals per event type over a period, read
 * cheaply from the hourly metric buckets.
 */
final class OverviewController
{
    public function __construct(private readonly ClockInterface $clock) {}

    public function __invoke(Request $request): JsonResponse
    {
        $days = max(1, min(90, $request->integer('days', 7)));
        $since = CarbonImmutable::instance($this->clock->now())->subDays($days)->startOfDay();

        $query = MetricBucket::query()
            ->withoutGlobalScopes()
            ->where('bucket', '>=', $since->format('Y-m-d H:i:s'));

        $tenant = $request->string('tenant')->toString();
        if ($tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        // Aggregate in the database (not in PHP) so the period can be wide without
        // loading every bucket row into memory.
        /** @var array<string, int> $totals */
        $totals = $query
            ->groupBy('event_type')
            ->selectRaw('event_type, SUM(count) as total')
            ->pluck('total', 'event_type')
            ->map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)
            ->all();

        return response()->json([
            'since' => $since->toIso8601String(),
            'days' => $days,
            'totals' => $totals,
        ]);
    }
}
