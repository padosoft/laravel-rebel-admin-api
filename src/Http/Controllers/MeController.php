<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET {prefix}/me — the current admin's identity plus the permission set the panel uses to
 * show/hide actions. Permissions are derived from the configured abilities: the standard set
 * is granted when the user passes the base `rebel-admin` ability (the panel still re-checks
 * server-side; this is purely to drive the UI).
 */
final class MeController
{
    /** The action abilities the panel understands. */
    private const ABILITIES = [
        'rebel-admin.view',
        'rebel-admin.sessions.revoke',
        'rebel-admin.devices.untrust',
        'rebel-admin.risk-rules.write',
        'rebel-admin.anomalies.act',
        'rebel-admin.ai.use',
    ];

    public function __construct(private readonly Gate $gate) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $id = $user?->getAuthIdentifier();

        $permissions = [];
        foreach (self::ABILITIES as $ability) {
            // If a host defines a finer-grained ability, honour it; otherwise the base
            // rebel-admin gate (already enforced by EnsureAdmin) grants the standard set.
            $allowed = $user !== null
                && ($this->gate->forUser($user)->allows($ability) || $this->gate->forUser($user)->allows('rebel-admin'));
            if ($allowed) {
                $permissions[] = $ability;
            }
        }

        return response()->json([
            'id' => is_scalar($id) ? (string) $id : null,
            'permissions' => $permissions,
        ]);
    }
}
