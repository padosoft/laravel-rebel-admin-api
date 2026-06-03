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
 * Funnel read models for §3.2 of the panel spec, derived from `rebel_auth_events`.
 *
 * `otp` returns the passwordless-login funnel (start → sent → delivered → verified → login);
 * `stepUp` breaks the step-up funnel down per purpose (required → challenged → verified).
 */
final class FunnelController
{
    use ResolvesTenant;

    public function __construct(private readonly ClockInterface $clock) {}

    /** GET {prefix}/otp/funnel?channel=&guard= */
    public function otp(Request $request): JsonResponse
    {
        $period = Period::fromRequest($request, CarbonImmutable::instance($this->clock->now()));
        $tenant = $this->tenant($request);

        $counts = $this->countByType($period, $tenant, [
            'email_otp.requested', 'email_otp.sent', 'email_otp.verified', 'login.succeeded',
        ], $request);

        $requested = $counts['email_otp.requested'];
        $sent = $counts['email_otp.sent'];
        $verified = $counts['email_otp.verified'];
        $login = $counts['login.succeeded'];

        return response()->json([
            'stages' => [
                ['key' => 'start', 'label' => 'Start', 'count' => $requested],
                ['key' => 'sent', 'label' => 'Sent', 'count' => $sent],
                ['key' => 'delivered', 'label' => 'Delivered', 'count' => $sent],
                ['key' => 'verified', 'label' => 'Verified', 'count' => $verified],
                ['key' => 'login', 'label' => 'Login', 'count' => $login],
            ],
            'resend_rate' => $requested > 0 ? round(max(0, $sent - $requested) / $requested, 3) : 0.0,
        ]);
    }

    /** GET {prefix}/step-up/funnel?purpose= */
    public function stepUp(Request $request): JsonResponse
    {
        $period = Period::fromRequest($request, CarbonImmutable::instance($this->clock->now()));
        $tenant = $this->tenant($request);

        $purposeFilter = $request->string('purpose')->toString();

        $rows = $this->baseQuery($period, $tenant)
            ->whereIn('event_type', ['step_up.required', 'step_up.verified'])
            ->whereNotNull('purpose')
            ->when($purposeFilter !== '', fn (Builder $q) => $q->where('purpose', $purposeFilter))
            ->groupBy('purpose', 'event_type', 'aal')
            ->selectRaw('purpose, event_type, aal, COUNT(*) as total')
            ->get();

        /** @var array<string, array{required: int, verified: int, assurances: array<string, int>}> $byPurpose */
        $byPurpose = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $purpose = is_string($data['purpose'] ?? null) ? $data['purpose'] : null;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : null;
            $aal = is_string($data['aal'] ?? null) ? $data['aal'] : null;
            $total = $data['total'] ?? 0;
            $count = is_numeric($total) ? (int) $total : 0;
            if ($purpose === null || $type === null) {
                continue;
            }

            $byPurpose[$purpose] ??= ['required' => 0, 'verified' => 0, 'assurances' => []];
            if ($type === 'step_up.required') {
                $byPurpose[$purpose]['required'] += $count;
            } else {
                $byPurpose[$purpose]['verified'] += $count;
                if ($aal !== null) {
                    $byPurpose[$purpose]['assurances'][$aal] = ($byPurpose[$purpose]['assurances'][$aal] ?? 0) + $count;
                }
            }
        }

        $out = [];
        foreach ($byPurpose as $purpose => $stats) {
            $out[] = [
                'purpose' => $purpose,
                'required' => $stats['required'],
                'challenged' => $stats['required'],
                'verified' => $stats['verified'],
                'rate' => $stats['required'] > 0 ? round($stats['verified'] / $stats['required'], 3) : 0.0,
                'avg_assurance' => $this->dominantAssurance($stats['assurances']),
            ];
        }

        return response()->json(['by_purpose' => $out]);
    }

    /**
     * @param  list<string>  $types
     * @return array<string, int>
     */
    private function countByType(Period $period, ?string $tenant, array $types, Request $request): array
    {
        /** @var array<string, int> $totals */
        $totals = array_fill_keys($types, 0);

        $channel = $request->string('channel')->toString();
        $guard = $request->string('guard')->toString();

        $rows = $this->baseQuery($period, $tenant)
            ->whereIn('event_type', $types)
            ->when($channel !== '', fn (Builder $q) => $q->where('channel', $channel))
            ->when($guard !== '', fn (Builder $q) => $q->where('guard', $guard))
            ->groupBy('event_type')
            ->selectRaw('event_type, COUNT(*) as total')
            ->get();

        foreach ($rows as $row) {
            $data = (array) $row;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : null;
            $total = $data['total'] ?? 0;
            if ($type !== null && array_key_exists($type, $totals)) {
                $totals[$type] = is_numeric($total) ? (int) $total : 0;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $assurances
     */
    private function dominantAssurance(array $assurances): ?string
    {
        if ($assurances === []) {
            return null;
        }

        arsort($assurances);

        return (string) array_key_first($assurances);
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
