<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Padosoft\Rebel\AdminApi\Metrics\MetricsProjector;
use Padosoft\Rebel\Core\Concerns\BelongsToTenant;

/**
 * An hourly aggregate of `rebel_auth_events`, grouped by event type and channel.
 * Populated by {@see MetricsProjector} so the admin
 * read models can answer counts cheaply without scanning the raw event log.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property CarbonImmutable $bucket
 * @property string $event_type
 * @property string|null $channel
 * @property int $count
 */
final class MetricBucket extends Model
{
    use BelongsToTenant;

    protected $table = 'rebel_metric_buckets';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'bucket', 'event_type', 'channel', 'count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket' => 'immutable_datetime',
            'count' => 'integer',
        ];
    }
}
