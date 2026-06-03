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
 * GET {prefix}/compliance/overview — the NIST/PSD2/GDPR evidence snapshot for §3.10.
 *
 * NIST: the AAL distribution across auth events in the window. PSD2: SCA / dynamic-linking
 * counts derived from `rebel_step_up_challenges` (when step-up is installed). GDPR: a
 * retention-tier + erasure summary (read from config where available). Everything is
 * tenant-explicit and degrades to honest zeros when a source table is absent.
 */
final class ComplianceController
{
    use ResolvesTenant;

    public function __construct(private readonly ClockInterface $clock) {}

    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $period = Period::fromRequest($request, $now);
        $tenant = $this->tenant($request);

        return response()->json([
            'nist' => ['aal_distribution' => $this->aalDistribution($period, $tenant)],
            'amr' => $this->amrDistribution($period, $tenant),
            'psd2' => $this->psd2($period, $tenant),
            'gdpr' => $this->gdpr($now),
        ]);
    }

    /**
     * The distribution of authentication-method references (AMR) across events in the window.
     * The `amr` column is a JSON array per event (e.g. ["otp","email"]); we flatten every
     * array, count each factor, and normalize to fractions summing to ~1. Real data only —
     * an empty object when no event carries AMR.
     *
     * @return array<string, float>
     */
    private function amrDistribution(Period $period, ?string $tenant): array
    {
        $rows = $this->events($period, $tenant)
            ->whereNotNull('amr')
            ->select('amr')
            ->cursor();

        /** @var array<string, int> $counts */
        $counts = [];
        $sum = 0;
        foreach ($rows as $row) {
            $raw = ((array) $row)['amr'] ?? null;
            foreach ($this->decodeAmr($raw) as $factor) {
                $counts[$factor] = ($counts[$factor] ?? 0) + 1;
                $sum++;
            }
        }

        if ($sum === 0) {
            return [];
        }

        $distribution = [];
        foreach ($counts as $factor => $count) {
            $distribution[$factor] = round($count / $sum, 3);
        }

        arsort($distribution);

        return $distribution;
    }

    /**
     * Decode one event's AMR column into a list of factor strings. The column is stored as a
     * JSON array; tolerate both the decoded array (some drivers cast it) and the raw string.
     *
     * @return list<string>
     */
    private function decodeAmr(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $factors = [];
        foreach ($decoded as $factor) {
            if (is_string($factor) && $factor !== '') {
                $factors[] = $factor;
            }
        }

        return $factors;
    }

    /**
     * @return array<string, float>
     */
    private function aalDistribution(Period $period, ?string $tenant): array
    {
        $rows = $this->events($period, $tenant)
            ->whereNotNull('aal')
            ->groupBy('aal')
            ->selectRaw('aal, COUNT(*) as total')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];
        $sum = 0;
        foreach ($rows as $row) {
            $data = (array) $row;
            $aal = is_string($data['aal'] ?? null) ? $data['aal'] : null;
            $total = $data['total'] ?? 0;
            $count = is_numeric($total) ? (int) $total : 0;
            if ($aal !== null) {
                $counts[$aal] = $count;
                $sum += $count;
            }
        }

        if ($sum === 0) {
            return ['aal1' => 0.0, 'aal2' => 0.0, 'aal3' => 0.0];
        }

        $distribution = ['aal1' => 0.0, 'aal2' => 0.0, 'aal3' => 0.0];
        foreach ($counts as $aal => $count) {
            $distribution[$aal] = round($count / $sum, 3);
        }

        return $distribution;
    }

    /**
     * @return array{sca_events: int, dynamic_linked: int, exemptions: array<string, int>}
     */
    private function psd2(Period $period, ?string $tenant): array
    {
        if (! Schema::hasTable('rebel_step_up_challenges')) {
            return ['sca_events' => 0, 'dynamic_linked' => 0, 'exemptions' => []];
        }

        $query = DB::table('rebel_step_up_challenges')
            ->where('status', 'verified')
            ->where('verified_at', '>=', $period->from->format('Y-m-d H:i:s'))
            ->where('verified_at', '<=', $period->to->format('Y-m-d H:i:s'));

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        $scaEvents = (clone $query)->count();
        $dynamicLinked = (clone $query)->whereNotNull('binding_hash')->count();

        return [
            'sca_events' => $scaEvents,
            'dynamic_linked' => $dynamicLinked,
            'exemptions' => [],
        ];
    }

    /**
     * @return array{retention_tiers: list<array{name: string, days: int}>, pending_erasures: int, last_prune_at: string|null}
     */
    private function gdpr(CarbonImmutable $now): array
    {
        $configured = config('rebel-admin-api.retention_tiers');
        $tiers = [];
        if (is_array($configured)) {
            foreach ($configured as $tier) {
                if (is_array($tier) && isset($tier['name'], $tier['days']) && is_scalar($tier['name']) && is_numeric($tier['days'])) {
                    $tiers[] = ['name' => (string) $tier['name'], 'days' => (int) $tier['days']];
                }
            }
        }

        return [
            'retention_tiers' => $tiers,
            'pending_erasures' => 0,
            'last_prune_at' => null,
        ];
    }

    private function events(Period $period, ?string $tenant): Builder
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
