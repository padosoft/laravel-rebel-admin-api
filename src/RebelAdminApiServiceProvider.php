<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi;

use Padosoft\Rebel\AdminApi\Console\ProjectMetricsCommand;
use Padosoft\Rebel\AdminApi\Metrics\MetricsProjector;
use Padosoft\Rebel\AdminApi\Risk\RiskRuleEvaluator;
use Padosoft\Rebel\AdminApi\Support\AdminAudit;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Control-plane JSON API for Laravel Rebel: permission-gated, tenant-scoped read
 * models over the audit log and hourly metric buckets, the funnels/channels/providers
 * read models, the device & session trust actions, persisted risk rules, anomaly cases,
 * the AI copilot, the compliance snapshot — plus the metrics projector.
 */
final class RebelAdminApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rebel-admin-api')
            ->hasConfigFile('rebel-admin-api')
            ->hasMigration('create_rebel_metric_buckets_table')
            ->hasMigration('create_rebel_risk_rules_table')
            ->hasMigration('create_rebel_admin_settings_table')
            ->hasCommand(ProjectMetricsCommand::class)
            ->hasRoute('api');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MetricsProjector::class);
        $this->app->singleton(RiskRuleEvaluator::class);
        $this->app->singleton(AdminAudit::class);
    }
}
