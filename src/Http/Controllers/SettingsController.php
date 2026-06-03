<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Padosoft\Rebel\AdminApi\Models\AdminSetting;
use Padosoft\Rebel\AdminApi\Support\AdminAudit;

/**
 * Generic, tenant-scoped key/value settings for the panel (channel/provider preferences,
 * thresholds, …). `index` lists all settings; `update` upserts one key's value. Values are
 * JSON so the panel may persist scalars, lists or objects without a schema change. Writes
 * are audited.
 */
final class SettingsController
{
    public function __construct(private readonly AdminAudit $audit) {}

    /** GET {prefix}/settings */
    public function index(Request $request): JsonResponse
    {
        $query = AdminSetting::query()->withoutGlobalScopes()->orderBy('key');

        $tenant = $this->tenant($request);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        $settings = [];
        foreach ($query->get() as $setting) {
            $settings[$setting->key] = $setting->value;
        }

        return response()->json(['settings' => $settings]);
    }

    /** PUT {prefix}/settings/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $tenant = $this->tenant($request);

        /** @var AdminSetting $setting */
        $setting = AdminSetting::query()->withoutGlobalScopes()->firstOrNew([
            'tenant_id' => $tenant,
            'key' => $key,
        ]);

        $setting->tenant_id = $tenant;
        $setting->key = $key;
        $setting->value = $request->input('value');
        $setting->save();

        $this->audit->record('setting.updated', Auth::user(), $tenant, ['key' => $key]);

        return response()->json(['key' => $key, 'value' => $setting->value]);
    }

    private function tenant(Request $request): ?string
    {
        $tenant = $request->string('tenant')->toString();

        return $tenant === '' ? null : $tenant;
    }
}
