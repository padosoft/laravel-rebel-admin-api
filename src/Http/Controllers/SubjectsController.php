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
 * Device & session trust read models and actions for §3.6.
 *
 * Reads `rebel_devices` / `rebel_sessions` (from laravel-rebel-sessions) for a subject and
 * exposes the operator actions — revoke a session, logout everywhere, untrust a device —
 * each of which mutates the registry directly (the admin acts ON a subject, not AS them) and
 * is recorded in the audit trail. When the sessions package is not installed the endpoints
 * return an honest empty state / 404, never an error.
 */
final class SubjectsController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AdminAudit $audit,
    ) {}

    /**
     * GET {prefix}/subjects — the searchable subject list for the §3.6 Device & Session panel.
     *
     * Subjects are derived from the audit log (distinct `subject_id` in `rebel_auth_events`)
     * and, when laravel-rebel-sessions is installed, enriched with live device/session counts.
     * The raw subject id is never returned verbatim — only a privacy-preserving masked form.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $hasDevices = Schema::hasTable('rebel_devices');
        $hasSessions = Schema::hasTable('rebel_sessions');

        // Last-seen per subject from the audit log (DB-aggregated, never loaded into PHP).
        $eventQuery = DB::table('rebel_auth_events')
            ->whereNotNull('subject_id')
            ->groupBy('subject_id')
            ->select('subject_id')
            ->selectRaw('MAX(created_at) as last_seen_at');
        if ($tenant !== null) {
            $eventQuery->where('tenant_id', $tenant);
        }

        /** @var array<string, array{subject: string, last_seen_at: string|null, devices: int, sessions: int}> $subjects */
        $subjects = [];
        foreach ($eventQuery->get() as $row) {
            $data = (array) $row;
            $subject = $this->str($data['subject_id'] ?? null);
            if ($subject === null || $subject === '') {
                continue;
            }
            $subjects[$subject] = [
                'subject' => $subject,
                'last_seen_at' => $this->str($data['last_seen_at'] ?? null),
                'devices' => 0,
                'sessions' => 0,
            ];
        }

        // Fold in any subjects that exist only in the sessions/devices registries, and attach
        // per-subject counts. Both tables are optional — guarded by Schema::hasTable above.
        if ($hasDevices) {
            $this->mergeCounts($subjects, 'rebel_devices', 'devices', $tenant);
        }
        if ($hasSessions) {
            $this->mergeCounts($subjects, 'rebel_sessions', 'sessions', $tenant);
        }

        // Most-recently-seen first; subjects without events (registry-only) sort last.
        uasort($subjects, fn (array $a, array $b): int => ($b['last_seen_at'] ?? '') <=> ($a['last_seen_at'] ?? ''));

        $total = count($subjects);
        $page = array_slice(array_values($subjects), 0, $perPage);

        $data = array_map(fn (array $s): array => [
            'subject' => $s['subject'],
            'masked' => $this->mask($s['subject']),
            'devices' => $s['devices'],
            'sessions' => $s['sessions'],
            'last_seen_at' => $this->iso($s['last_seen_at']),
        ], $page);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $total, 'per_page' => $perPage, 'returned' => count($data)],
        ]);
    }

    /**
     * Add per-subject row counts from an optional registry table, creating registry-only
     * subjects as needed so they still appear in the panel search.
     *
     * @param  array<string, array{subject: string, last_seen_at: string|null, devices: int, sessions: int}>  $subjects
     * @param  'devices'|'sessions'  $key
     */
    private function mergeCounts(array &$subjects, string $table, string $key, ?string $tenant): void
    {
        $query = DB::table($table)
            ->whereNotNull('subject_id')
            ->groupBy('subject_id')
            ->select('subject_id')
            ->selectRaw('COUNT(*) as total');
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        foreach ($query->get() as $row) {
            $data = (array) $row;
            $subject = $this->str($data['subject_id'] ?? null);
            if ($subject === null || $subject === '') {
                continue;
            }
            $total = $data['total'] ?? 0;
            $count = is_numeric($total) ? (int) $total : 0;

            $subjects[$subject] ??= ['subject' => $subject, 'last_seen_at' => null, 'devices' => 0, 'sessions' => 0];
            $subjects[$subject][$key] = $count;
        }
    }

    /**
     * A privacy-preserving masked form of the subject id — never expose the raw value. Keeps a
     * short, recognizable prefix so an operator can correlate without seeing the whole id.
     */
    private function mask(string $subject): string
    {
        return mb_strlen($subject) > 6 ? mb_substr($subject, 0, 6).'…' : $subject;
    }

    /** Normalize a stored timestamp to ISO-8601, tolerating the DB's 'Y-m-d H:i:s' form. */
    private function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return $value;
        }
    }

    /** GET {prefix}/subjects/{subject}/devices */
    public function devices(Request $request, string $subject): JsonResponse
    {
        if (! Schema::hasTable('rebel_devices')) {
            return response()->json(['devices' => []]);
        }

        $rows = $this->scopeSubject(DB::table('rebel_devices'), $request, $subject)
            ->orderByDesc('last_seen_at')
            ->get();

        $devices = $rows->map(function (object $row): array {
            $data = (array) $row;

            return [
                'id' => $this->str($data['id'] ?? null),
                'fingerprint' => $this->truncate($this->str($data['fingerprint_hash'] ?? null)),
                'trusted' => (bool) ($data['trusted'] ?? false),
                'trusted_until' => $this->str($data['trusted_until'] ?? null),
                'last_seen_at' => $this->str($data['last_seen_at'] ?? null),
            ];
        })->all();

        return response()->json(['devices' => $devices]);
    }

    /** GET {prefix}/subjects/{subject}/sessions */
    public function sessions(Request $request, string $subject): JsonResponse
    {
        if (! Schema::hasTable('rebel_sessions')) {
            return response()->json(['sessions' => []]);
        }

        $rows = $this->scopeSubject(DB::table('rebel_sessions'), $request, $subject)
            ->orderByDesc('created_at')
            ->get();

        $sessions = $rows->map(function (object $row): array {
            $data = (array) $row;

            return [
                'id' => $this->str($data['id'] ?? null),
                'type' => $this->str($data['type'] ?? null),
                'status' => $this->str($data['status'] ?? null),
                'device_id' => $this->str($data['device_id'] ?? null),
                'created_at' => $this->str($data['created_at'] ?? null),
                'expires_at' => $this->str($data['expires_at'] ?? null),
                'revoked_at' => $this->str($data['revoked_at'] ?? null),
            ];
        })->all();

        return response()->json(['sessions' => $sessions]);
    }

    /** POST {prefix}/subjects/{subject}/sessions/{id}/revoke */
    public function revokeSession(Request $request, string $subject, string $id): JsonResponse
    {
        if (! Schema::hasTable('rebel_sessions')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $now = $this->now();
        $affected = $this->scopeSubject(DB::table('rebel_sessions'), $request, $subject)
            ->where('id', $id)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now]);

        if ($affected === 0) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->audit->record('session.revoked', Auth::user(), $this->tenant($request), [
            'subject_id' => $subject,
            'session_id' => $id,
        ]);

        return response()->json(['revoked' => true]);
    }

    /** POST {prefix}/subjects/{subject}/logout-everywhere */
    public function logoutEverywhere(Request $request, string $subject): JsonResponse
    {
        if (! Schema::hasTable('rebel_sessions')) {
            return response()->json(['revoked' => 0]);
        }

        $now = $this->now();
        $revoked = $this->scopeSubject(DB::table('rebel_sessions'), $request, $subject)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now]);

        $this->audit->record('logout_everywhere', Auth::user(), $this->tenant($request), [
            'subject_id' => $subject,
            'revoked' => $revoked,
        ]);

        return response()->json(['revoked' => $revoked]);
    }

    /** POST {prefix}/subjects/{subject}/devices/{id}/untrust */
    public function untrustDevice(Request $request, string $subject, string $id): JsonResponse
    {
        if (! Schema::hasTable('rebel_devices')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $now = $this->now();
        $affected = $this->scopeSubject(DB::table('rebel_devices'), $request, $subject)
            ->where('id', $id)
            ->update(['trusted' => false, 'trusted_until' => null, 'updated_at' => $now]);

        if ($affected === 0) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->audit->record('device.untrusted', Auth::user(), $this->tenant($request), [
            'subject_id' => $subject,
            'device_id' => $id,
        ]);

        return response()->json(['untrusted' => true]);
    }

    /**
     * Scope a query to one subject. The {subject} route segment is the subject_id; an
     * explicit `?tenant=` further scopes the record. The subject_type is matched loosely
     * (any class) so the panel can address a subject by id without knowing its model.
     */
    private function scopeSubject(Builder $query, Request $request, string $subject): Builder
    {
        $query->where('subject_id', $subject);

        $tenant = $this->tenant($request);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        $subjectType = $request->string('subject_type')->toString();
        if ($subjectType !== '') {
            $query->where('subject_type', $subjectType);
        }

        return $query;
    }

    private function tenant(Request $request): ?string
    {
        $tenant = $request->string('tenant')->toString();

        return $tenant === '' ? null : $tenant;
    }

    private function now(): string
    {
        return CarbonImmutable::instance($this->clock->now())->format('Y-m-d H:i:s');
    }

    private function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > 12 ? mb_substr($value, 0, 12).'…' : $value;
    }
}
