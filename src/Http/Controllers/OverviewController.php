<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\Rebel\AdminApi\Http\Concerns\ResolvesTenant;
use Padosoft\Rebel\AdminApi\Support\Period;
use Psr\Clock\ClockInterface;

/**
 * GET {prefix}/security/overview — the security KPI snapshot for §3.1 of the panel spec.
 *
 * Each KPI carries its value, the delta vs the immediately-preceding window of equal length,
 * and an hourly sparkline; a per-bucket timeseries and the open anomaly + provider strips
 * complete the dashboard. Everything is derived from `rebel_auth_events` (DB-aggregated, the
 * raw log is never loaded into PHP) and is tenant-explicit.
 */
final class OverviewController
{
    use ResolvesTenant;

    public function __construct(private readonly ClockInterface $clock) {}

    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $period = Period::fromRequest($request, $now);
        $previous = $period->previous();
        $tenant = $this->tenant($request);

        $current = $this->counts($period, $tenant);
        $prior = $this->counts($previous, $tenant);
        $spark = $this->sparklines($period, $tenant);

        $loginRequests = $current['login.succeeded'] + $current['login.failed'];
        $priorLogins = $prior['login.succeeded'] + $prior['login.failed'];

        return response()->json([
            'period' => $period->label,
            'generated_at' => $now->toIso8601String(),
            'kpis' => [
                'login_requests' => $this->kpi($loginRequests, $priorLogins, $spark['logins']),
                'otp_sent' => $this->kpi($current['email_otp.sent'], $prior['email_otp.sent'], $spark['otp_sent']),
                'otp_verified' => $this->kpi(
                    $current['email_otp.verified'],
                    $prior['email_otp.verified'],
                    $spark['otp_verified'],
                    $this->rate($current['email_otp.verified'], $current['email_otp.sent']),
                ),
                'step_up_required' => $this->kpi($current['step_up.required'], $prior['step_up.required'], $spark['step_up_required']),
                'step_up_verified' => $this->kpi(
                    $current['step_up.verified'],
                    $prior['step_up.verified'],
                    $spark['step_up_verified'],
                    $this->rate($current['step_up.verified'], $current['step_up.required']),
                ),
                'high_risk_events' => $this->kpi($current['risk.anomaly.detected'], $prior['risk.anomaly.detected'], $spark['high_risk']),
            ],
            'timeseries' => $this->timeseries($period, $tenant),
            'open_anomalies' => $this->openAnomalies($tenant),
            'providers' => $this->providers($period, $tenant),
        ]);
    }

    /**
     * @param  list<int>  $sparkline
     * @return array{value: int, delta_pct: float, rate?: float, sparkline: list<int>}
     */
    private function kpi(int $value, int $prior, array $sparkline, ?float $rate = null): array
    {
        $kpi = [
            'value' => $value,
            'delta_pct' => $this->deltaPct($value, $prior),
            'sparkline' => $sparkline,
        ];

        if ($rate !== null) {
            $kpi['rate'] = $rate;
        }

        return $kpi;
    }

    /**
     * Total events per type for a window. Returns a fixed shape so callers can index safely.
     *
     * @return array<string, int>
     */
    private function counts(Period $period, ?string $tenant): array
    {
        $keys = [
            'login.succeeded', 'login.failed', 'email_otp.sent', 'email_otp.verified',
            'step_up.required', 'step_up.verified', 'risk.anomaly.detected',
        ];

        /** @var array<string, int> $totals */
        $totals = array_fill_keys($keys, 0);

        $rows = $this->baseQuery($period, $tenant)
            ->whereIn('event_type', $keys)
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
     * Hourly sparklines per KPI for the window, as a list of bucket counts.
     *
     * @return array{logins: list<int>, otp_sent: list<int>, otp_verified: list<int>, step_up_required: list<int>, step_up_verified: list<int>, high_risk: list<int>}
     */
    private function sparklines(Period $period, ?string $tenant): array
    {
        $hours = $this->hourBuckets($period);

        $map = [
            'logins' => ['login.succeeded', 'login.failed'],
            'otp_sent' => ['email_otp.sent'],
            'otp_verified' => ['email_otp.verified'],
            'step_up_required' => ['step_up.required'],
            'step_up_verified' => ['step_up.verified'],
            'high_risk' => ['risk.anomaly.detected'],
        ];

        $hourly = $this->hourlyCounts($period, $tenant);

        /** @var array{logins: list<int>, otp_sent: list<int>, otp_verified: list<int>, step_up_required: list<int>, step_up_verified: list<int>, high_risk: list<int>} $out */
        $out = [];
        foreach ($map as $kpi => $types) {
            $series = [];
            foreach ($hours as $hour) {
                $sum = 0;
                foreach ($types as $type) {
                    $sum += $hourly[$hour][$type] ?? 0;
                }
                $series[] = $sum;
            }
            $out[$kpi] = $series;
        }

        return $out;
    }

    /**
     * @return list<array{t: string, logins: int, otp_sent: int, otp_verified: int, high_risk: int}>
     */
    private function timeseries(Period $period, ?string $tenant): array
    {
        $hours = $this->hourBuckets($period);
        $hourly = $this->hourlyCounts($period, $tenant);

        $out = [];
        foreach ($hours as $hour) {
            $bucket = $hourly[$hour] ?? [];
            $out[] = [
                't' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $hour, 'UTC')?->toIso8601String() ?? $hour,
                'logins' => ($bucket['login.succeeded'] ?? 0) + ($bucket['login.failed'] ?? 0),
                'otp_sent' => $bucket['email_otp.sent'] ?? 0,
                'otp_verified' => $bucket['email_otp.verified'] ?? 0,
                'high_risk' => $bucket['risk.anomaly.detected'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * One DB pass returning per-hour, per-type counts keyed by hour string.
     *
     * @return array<string, array<string, int>>
     */
    private function hourlyCounts(Period $period, ?string $tenant): array
    {
        // Truncate created_at to the hour in PHP (not SQL) so the projection is portable
        // across sqlite/MySQL/Postgres. Only the (created_at, event_type) columns are pulled,
        // and the aggregate map stays small (hours × event types).
        $rows = $this->baseQuery($period, $tenant)
            ->select('created_at', 'event_type')
            ->orderBy('created_at')
            ->cursor();

        /** @var array<string, array<string, int>> $out */
        $out = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $createdAt = is_string($data['created_at'] ?? null) ? $data['created_at'] : null;
            $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : null;
            if ($createdAt === null || $type === null) {
                continue;
            }

            $hour = CarbonImmutable::parse($createdAt)->startOfHour()->format('Y-m-d H:i:s');
            $out[$hour][$type] = ($out[$hour][$type] ?? 0) + 1;
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

    /**
     * @return array<int, array{id: string, type: string, severity: string, opened_at: string|null}>
     */
    private function openAnomalies(?string $tenant): array
    {
        if (! Schema::hasTable('rebel_anomaly_cases')) {
            return [];
        }

        $query = DB::table('rebel_anomaly_cases')
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->limit(5);

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        return $query->get()->map(function (object $row): array {
            $data = (array) $row;

            return [
                'id' => is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
                'type' => is_string($data['type'] ?? null) ? $data['type'] : '',
                'severity' => is_string($data['severity'] ?? null) ? $data['severity'] : '',
                'opened_at' => is_string($data['opened_at'] ?? null) ? $data['opened_at'] : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{key: string, status: string}>
     */
    private function providers(Period $period, ?string $tenant): array
    {
        $rows = $this->baseQuery($period, $tenant)
            ->whereNotNull('provider')
            ->distinct()
            ->pluck('provider');

        $providers = [];
        foreach ($rows as $provider) {
            if (is_string($provider) && $provider !== '') {
                $providers[] = ['key' => $provider, 'status' => 'healthy'];
            }
        }

        return $providers;
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

    private function deltaPct(int $value, int $prior): float
    {
        if ($prior === 0) {
            return $value === 0 ? 0.0 : 100.0;
        }

        return round((($value - $prior) / $prior) * 100, 1);
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 3);
    }
}
