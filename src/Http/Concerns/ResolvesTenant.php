<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Shared tenant resolution for the admin read models. The control plane looks ACROSS
 * tenants by default (it is operated by a super-admin) and bypasses the ambient
 * CurrentTenant global scope; passing `?tenant=<id>` scopes a request deterministically.
 */
trait ResolvesTenant
{
    /** The explicit tenant filter for this request, or null to look across tenants. */
    protected function tenant(Request $request): ?string
    {
        $tenant = $request->string('tenant')->toString();

        return $tenant === '' ? null : $tenant;
    }
}
