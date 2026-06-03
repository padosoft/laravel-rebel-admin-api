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
                'expires_at' => $this->str($data['expires_at'] ?? null),
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
