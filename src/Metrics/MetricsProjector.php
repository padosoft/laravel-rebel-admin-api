<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Metrics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Rolls `rebel_auth_events` up into hourly `rebel_metric_buckets`, grouped by tenant,
 * event type and channel. It reads via the query builder (bypassing the tenant global
 * scope) so a single pass projects ALL tenants, and the upsert is idempotent — running
 * it again over an overlapping window simply corrects the counts.
 */
final class MetricsProjector
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Project events with `created_at` in [$from, $to) into hourly buckets.
     * Returns the number of distinct buckets written.
     */
    public function project(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $connection = $this->db->connection();

        // Stream the rows with a lazy cursor: only one row is held in memory at a time,
        // while the aggregate map stays small (hours × event types × channels × tenants).
        $rows = $connection->table('rebel_auth_events')
            ->select('tenant_id', 'event_type', 'channel', 'created_at')
            ->where('created_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $to->format('Y-m-d H:i:s'))
            ->cursor();

        /** @var array<string, array{tenant_id: ?string, bucket: string, event_type: string, channel: ?string, count: int}> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $data = (array) $row;

            $eventType = $this->stringOrNull($data['event_type'] ?? null);
            $createdAt = $this->stringOrNull($data['created_at'] ?? null);

            if ($eventType === null || $createdAt === null) {
                continue;
            }

            // Robust hour truncation (handles 'Y-m-d H:i:s', ISO 'T', and microseconds).
            $hour = CarbonImmutable::parse($createdAt)->startOfHour()->format('Y-m-d H:i:s');
            $tenantId = $this->stringOrNull($data['tenant_id'] ?? null);
            $channel = $this->stringOrNull($data['channel'] ?? null);

            $key = ($tenantId ?? '~').'|'.$hour.'|'.$eventType.'|'.($channel ?? '~');

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['tenant_id' => $tenantId, 'bucket' => $hour, 'event_type' => $eventType, 'channel' => $channel, 'count' => 0];
            }

            $buckets[$key]['count']++;
        }

        foreach ($buckets as $bucket) {
            $connection->table('rebel_metric_buckets')->updateOrInsert(
                [
                    'tenant_id' => $bucket['tenant_id'],
                    'bucket' => $bucket['bucket'],
                    'event_type' => $bucket['event_type'],
                    'channel' => $bucket['channel'],
                ],
                ['count' => $bucket['count']],
            );
        }

        return count($buckets);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
