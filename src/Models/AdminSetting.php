<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Padosoft\Rebel\Core\Concerns\BelongsToTenant;

/**
 * A generic tenant-scoped key/value setting for the admin panel (channel/provider
 * preferences, thresholds, …). The value is stored as JSON so callers may persist
 * scalars, lists or objects without a schema change.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AdminSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'rebel_admin_settings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'key', 'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
