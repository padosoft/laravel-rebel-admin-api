<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\Rebel\AdminApi\Support\AdminAudit;
use Psr\Clock\ClockInterface;

/**
 * Anomaly case read models and actions for §3.8, over `rebel_anomaly_cases` (ai-guard).
 *
 * `index`/`show` list and detail cases; `actions` applies an operator decision —
 * acknowledge / close update the case status, while a destructive "mitigate" requires an
 * explicit `confirm` flag and is audited. Every action is recorded in the audit trail.
 * When ai-guard is not installed the endpoints return an honest empty state / 404.
 */
final class AnomaliesController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AdminAudit $audit,
    ) {}

    /** GET {prefix}/anomalies?type=&severity=&status=&cursor= */
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('rebel_anomaly_cases')) {
            return response()->json(['data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false]]);
        }

        $perPage = max(1, min(100, $request->integer('limit', 25)));

        $query = DB::table('rebel_anomaly_cases')->orderByDesc('opened_at')->orderByDesc('id');

        foreach (['type' => 'type', 'severity' => 'severity', 'status' => 'status'] as $param => $column) {
            $value = $request->string($param)->toString();
            if ($value !== '') {
                $query->where($column, $value);
            }
        }

        $this->scopeTenant($query, $request);

        $cursor = $request->string('cursor')->toString();
        if ($cursor !== '') {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        $data = $rows->map(fn (object $row): array => $this->summary((array) $row))->values()->all();
        $last = $rows->last();
        $nextCursor = $hasMore && $last !== null ? $this->str(((array) $last)['id'] ?? null) : null;

        return response()->json([
            'data' => $data,
            'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
        ]);
    }

    /** GET {prefix}/anomalies/{case} */
    public function show(Request $request, string $case): JsonResponse
    {
        if (! Schema::hasTable('rebel_anomaly_cases')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $query = DB::table('rebel_anomaly_cases')->where('id', $case);
        $this->scopeTenant($query, $request);

        $row = $query->first();
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = (array) $row;
        $signals = $this->decodeSignals($data['signals'] ?? null);

        return response()->json([
            'id' => $this->str($data['id'] ?? null),
            'type' => $this->str($data['type'] ?? null),
            'severity' => $this->str($data['severity'] ?? null),
            'status' => $this->str($data['status'] ?? null),
            'events_count' => is_numeric($data['events_count'] ?? null) ? (int) $data['events_count'] : 0,
            'opened_at' => $this->str($data['opened_at'] ?? null),
            'signals' => $signals,
            'timeline' => [],
            'suggested_actions' => $this->suggestedActions($this->str($data['type'] ?? null)),
        ]);
    }

    /** POST {prefix}/anomalies/{case}/actions — acknowledge / close / mitigate. */
    public function actions(Request $request, string $case): JsonResponse
    {
        if (! Schema::hasTable('rebel_anomaly_cases')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $action = $request->string('action')->toString();
        $tenant = $this->tenant($request);

        $query = DB::table('rebel_anomaly_cases')->where('id', $case);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        if ((clone $query)->doesntExist()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $now = CarbonImmutable::instance($this->clock->now())->format('Y-m-d H:i:s');

        switch ($action) {
            case 'acknowledge':
                $query->update(['status' => 'acknowledged', 'updated_at' => $now]);
                break;

            case 'close':
                $query->update(['status' => 'closed', 'updated_at' => $now]);
                break;

            case 'mitigate':
                // Destructive: require an explicit confirmation before any mitigation is recorded.
                if ($request->boolean('confirm') !== true) {
                    return response()->json(['error' => 'confirmation_required'], 422);
                }
                $query->update(['status' => 'acknowledged', 'updated_at' => $now]);
                break;

            default:
                return response()->json(['error' => 'invalid_action'], 422);
        }

        $this->audit->record('anomaly.'.$action, Auth::user(), $tenant, [
            'case_id' => $case,
            'action' => $action,
        ]);

        return response()->json(['ok' => true, 'action' => $action]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{id: string|null, type: string|null, severity: string|null, status: string|null, events_count: int, opened_at: string|null}
     */
    private function summary(array $data): array
    {
        return [
            'id' => $this->str($data['id'] ?? null),
            'type' => $this->str($data['type'] ?? null),
            'severity' => $this->str($data['severity'] ?? null),
            'status' => $this->str($data['status'] ?? null),
            'events_count' => is_numeric($data['events_count'] ?? null) ? (int) $data['events_count'] : 0,
            'opened_at' => $this->str($data['opened_at'] ?? null),
        ];
    }

    /**
     * @return list<array{key: string, label: string, destructive: bool}>
     */
    private function suggestedActions(?string $type): array
    {
        return match ($type) {
            'sms_pumping' => [['key' => 'block_prefix', 'label' => 'Block originating prefix', 'destructive' => true]],
            'otp_bombing' => [['key' => 'rate_limit', 'label' => 'Tighten OTP rate limit', 'destructive' => false]],
            'credential_stuffing' => [['key' => 'force_step_up', 'label' => 'Force step-up for the guard', 'destructive' => false]],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSignals(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    private function scopeTenant(Builder $query, Request $request): void
    {
        $tenant = $this->tenant($request);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }
    }

    private function tenant(Request $request): ?string
    {
        $tenant = $request->string('tenant')->toString();

        return $tenant === '' ? null : $tenant;
    }

    private function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
