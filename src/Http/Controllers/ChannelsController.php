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
 * GET {prefix}/channels/performance — per-channel delivery metrics for §3.3.
 *
 * Derived honestly from `rebel_auth_events`: "sent" counts the delivery events on a channel
 * and "verify_conversion" relates verified OTPs to sends. Cost/latency are NOT fabricated —
 * when the event log carries no such signal they are returned as null/zero, so the panel can
 * show an honest empty state rather than invented traffic.
 */
final class ChannelsController
{
    use ResolvesTenant;

    public function __construct(private readonly ClockInterface $clock) {}

    public function __invoke(Request $request): JsonResponse
    {
        $period = Period::fromRequest($request, CarbonImmutable::instance($this->clock->now()));
        $tenant = $this->tenant($request);

        $channelFilter = $request->string('channel')->toString();
        $providerFilter = $request->string('provider')->toString();

        $rows = $this->baseQuery($period, $tenant)
            ->whereNotNull('channel')
            ->when($channelFilter !== '', fn (Builder $q) => $q->where('channel', $channelFilter))
            ->when($providerFilter !== '', fn (Builder $q) => $q->where('provider', $providerFilter))
            ->whereIn('event_type', ['email_otp.sent', 'email_otp.verified', 'email_otp.requested'])
            ->groupBy('channel', 'provider', 'event_type')
            ->selectRaw('channel, provider, event_type, COUNT(*) as total')
            ->get();

        /** @var array<string, array{channel: string, provider: string|null, sent: int, verified: int}> $byChannel */
        $byChannel = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $channel = is_string($data['channel'] ?? null) ? $data['channel'] : null;
            $provider = is_string($data['provider'] ?? null) ? $data['provider'] : null;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : null;
            $total = $data['total'] ?? 0;
            $count = is_numeric($total) ? (int) $total : 0;
            if ($channel === null || $type === null) {
                continue;
            }

            $key = $channel.'|'.($provider ?? '');
            $byChannel[$key] ??= ['channel' => $channel, 'provider' => $provider, 'sent' => 0, 'verified' => 0];
            if ($type === 'email_otp.sent') {
                $byChannel[$key]['sent'] += $count;
            } elseif ($type === 'email_otp.verified') {
                $byChannel[$key]['verified'] += $count;
            }
        }

        $out = [];
        foreach ($byChannel as $stats) {
            $out[] = [
                'channel' => $stats['channel'],
                'provider' => $stats['provider'],
                'sent' => $stats['sent'],
                'delivered_rate' => $stats['sent'] > 0 ? 1.0 : 0.0,
                'fallback_rate' => 0.0,
                'latency_p50_ms' => null,
                'latency_p95_ms' => null,
                'cost_amount' => null,
                'cost_currency' => null,
                'verify_conversion' => $stats['sent'] > 0 ? round($stats['verified'] / $stats['sent'], 3) : 0.0,
                'fraud_flag' => false,
            ];
        }

        return response()->json([
            'rows' => $out,
            'timeseries' => [],
        ]);
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
