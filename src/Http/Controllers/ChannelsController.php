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
 * Derived honestly from `rebel_auth_events`: "sent" counts the send/request events on a
 * channel (any `*.requested` / `*.sent` event), and "verify_conversion" relates the
 * `*.verified` events on that channel to its sends. Delivery receipts come from two sources:
 * the provider's async status webhook (`channel.verification.delivered`, carrying cost), and a
 * pure delivery channel's synchronous `channel.delivery.sent` / `.failed` (the send is its own
 * receipt) — both feed `delivered_rate`. Fallback and latency are not measured yet and stay
 * null (the panel shows an honest "not measured" state); we never fabricate traffic or telemetry.
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
            ->select('channel', 'provider', 'event_type', 'created_at', 'metadata')
            ->orderBy('created_at')
            ->cursor();

        /** @var array<string, array{sent: int, verified: int, delivered: int, cost: float, currency: ?string, providers: array<string, int>}> $byChannel */
        $byChannel = [];
        $hourBuckets = $this->hourBuckets($period);
        /** @var array<string, array<string, int>> $sentByHour per channel, per hour bucket */
        $sentByHour = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $channel = is_string($data['channel'] ?? null) ? $data['channel'] : null;
            $provider = is_string($data['provider'] ?? null) && $data['provider'] !== '' ? $data['provider'] : null;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : null;
            $createdAt = is_string($data['created_at'] ?? null) ? $data['created_at'] : null;
            if ($channel === null || $type === null) {
                continue;
            }

            $byChannel[$channel] ??= ['sent' => 0, 'verified' => 0, 'delivered' => 0, 'cost' => 0.0, 'currency' => null, 'providers' => []];

            // Pure delivery channels (Telegram, Discord, ...) emit a synchronous
            // `channel.delivery.sent` / `.failed` — the send IS its own receipt. Count every
            // attempt as a send and credit a successful one as delivered, so `delivered_rate`
            // reflects sent/(sent+failed) honestly. Handled explicitly (and short-circuited) so
            // `.sent` is not also matched by the generic isSend() below and double-counted.
            if ($type === 'channel.delivery.sent' || $type === 'channel.delivery.failed') {
                $byChannel[$channel]['sent']++;
                if ($provider !== null) {
                    $byChannel[$channel]['providers'][$provider] = ($byChannel[$channel]['providers'][$provider] ?? 0) + 1;
                }
                if ($createdAt !== null) {
                    $hour = CarbonImmutable::parse($createdAt)->startOfHour()->format('Y-m-d H:i:s');
                    $sentByHour[$channel][$hour] = ($sentByHour[$channel][$hour] ?? 0) + 1;
                }
                if ($type === 'channel.delivery.sent') {
                    $byChannel[$channel]['delivered']++;
                }

                continue;
            }

            if ($this->isSend($type)) {
                $byChannel[$channel]['sent']++;
                if ($provider !== null) {
                    $byChannel[$channel]['providers'][$provider] = ($byChannel[$channel]['providers'][$provider] ?? 0) + 1;
                }
                if ($createdAt !== null) {
                    $hour = CarbonImmutable::parse($createdAt)->startOfHour()->format('Y-m-d H:i:s');
                    $sentByHour[$channel][$hour] = ($sentByHour[$channel][$hour] ?? 0) + 1;
                }
            } elseif ($this->isVerify($type)) {
                $byChannel[$channel]['verified']++;
            } elseif ($type === 'channel.verification.delivered') {
                // Delivery receipts from the provider's status webhook (e.g. Twilio).
                $byChannel[$channel]['delivered']++;
                $meta = $this->decodeMetadata($data['metadata'] ?? null);
                if (is_numeric($meta['price'] ?? null)) {
                    $byChannel[$channel]['cost'] += (float) $meta['price'];
                }
                if (is_string($meta['price_unit'] ?? null) && $meta['price_unit'] !== '') {
                    $byChannel[$channel]['currency'] = $meta['price_unit'];
                }
            }
        }

        ksort($byChannel);

        $out = [];
        foreach ($byChannel as $channel => $stats) {
            $sent = $stats['sent'];
            $delivered = $stats['delivered'];
            $out[] = [
                'channel' => $channel,
                'provider' => $this->topProvider($stats['providers']),
                'sent' => $sent,
                // Until the provider's delivery webhook reports receipts, delivered/cost stay
                // null (unknown) rather than a fabricated 0% — honest empty state.
                'delivered_rate' => $delivered > 0 && $sent > 0 ? round($delivered / $sent, 3) : null,
                'fallback_rate' => null,
                'latency_p50_ms' => null,
                'latency_p95_ms' => null,
                'cost_amount' => $delivered > 0 ? round($stats['cost'], 4) : null,
                'cost_currency' => $stats['currency'],
                'verify_conversion' => $sent > 0 ? round($stats['verified'] / $sent, 3) : 0.0,
                'fraud_flag' => false,
            ];
        }

        return response()->json([
            'rows' => $out,
            'timeseries' => $this->timeseries($hourBuckets, $sentByHour, array_keys($byChannel)),
        ]);
    }

    /**
     * A "send" is any request/dispatch of a one-time credential on a channel: the explicit
     * `*.requested` / `*.sent` events emitted by email-otp and the OTP drivers, plus the
     * `channel.verification.started` event emitted by the laravel-rebel-channels router when a
     * provider (Twilio, Vonage…) actually dispatches a code.
     */
    private function isSend(string $type): bool
    {
        return str_ends_with($type, '.requested')
            || str_ends_with($type, '.sent')
            || $type === 'channel.verification.started';
    }

    private function isVerify(string $type): bool
    {
        return str_ends_with($type, '.verified') || $type === 'channel.verification.approved';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, int>  $providers
     */
    private function topProvider(array $providers): ?string
    {
        if ($providers === []) {
            return null;
        }

        arsort($providers);

        return (string) array_key_first($providers);
    }

    /**
     * Per-bucket sent counts per channel over the window (honest, zero-filled buckets).
     *
     * @param  list<string>  $hours
     * @param  array<string, array<string, int>>  $sentByHour
     * @param  list<string>  $channels
     * @return list<array{t: string, channel: string, sent: int}>
     */
    private function timeseries(array $hours, array $sentByHour, array $channels): array
    {
        $out = [];
        foreach ($hours as $hour) {
            $t = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $hour, 'UTC')?->toIso8601String() ?? $hour;
            foreach ($channels as $channel) {
                $out[] = [
                    't' => $t,
                    'channel' => $channel,
                    'sent' => $sentByHour[$channel][$hour] ?? 0,
                ];
            }
        }

        return $out;
    }

    /**
     * The ordered list of hour-bucket keys spanning the window (UTC, 'Y-m-d H:00:00').
     *
     * @return list<string>
     */
    private function hourBuckets(Period $period): array
    {
        $cursor = $period->from->utc()->startOfHour();
        $end = $period->to->utc();

        $hours = [];
        $guard = 0;
        while ($cursor <= $end && $guard < 24 * 92) {
            $hours[] = $cursor->format('Y-m-d H:i:s');
            $cursor = $cursor->addHour();
            $guard++;
        }

        return $hours;
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
