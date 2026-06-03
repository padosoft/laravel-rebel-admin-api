<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Padosoft\Rebel\Core\Concerns\BelongsToTenant;

/**
 * A persisted, tenant-scoped risk rule editable from the admin panel. Rules are created
 * as DRAFTs by default (never auto-applied); the step-up/risk engine decides whether to
 * consume active rules. The admin-api `risk-rules/simulate` endpoint evaluates these rows
 * read-only.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $key
 * @property string $signal
 * @property string $operator
 * @property string $value
 * @property string $action
 * @property string|null $required_assurance
 * @property bool $phishing_resistant
 * @property string $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RiskRule extends Model
{
    use BelongsToTenant;

    protected $table = 'rebel_risk_rules';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'key', 'signal', 'operator', 'value',
        'action', 'required_assurance', 'phishing_resistant', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phishing_resistant' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
