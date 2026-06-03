<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Skeleton iniziale di padosoft/laravel-rebel-admin-api. Implementazione in arrivo.
 */
final class RebelAdminApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-rebel-admin-api');
    }
}
