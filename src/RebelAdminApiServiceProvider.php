<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi;

use Padosoft\Rebel\AdminApi\Console\ProjectMetricsCommand;
use Padosoft\Rebel\AdminApi\Metrics\MetricsProjector;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Control-plane JSON API for Laravel Rebel: permission-gated, tenant-scoped read
 * models over the audit log and hourly metric buckets, plus the metrics projector.
 */
final class RebelAdminApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rebel-admin-api')
            ->hasConfigFile('rebel-admin-api')
            ->hasMigration('create_rebel_metric_buckets_table')
            ->hasCommand(ProjectMetricsCommand::class)
            ->hasRoute('api');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MetricsProjector::class);
    }
}
