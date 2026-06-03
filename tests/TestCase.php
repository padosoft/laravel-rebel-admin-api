<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\Rebel\AdminApi\RebelAdminApiServiceProvider;
use Padosoft\Rebel\Core\RebelCoreServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Only core + admin-api are booted. The sibling packages (sessions, ai-guard,
        // step-up) are exercised through their TABLES — their migrations are loaded below —
        // without registering their providers, which would pull optional bindings the
        // control plane never needs (e.g. email-otp's SubjectResolver).
        return [
            RebelCoreServiceProvider::class,
            RebelAdminApiServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('rebel-core.peppers', [1 => 'test-pepper']);
        $app['config']->set('rebel-core.pepper_current', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/padosoft/laravel-rebel-core/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/padosoft/laravel-rebel-sessions/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/padosoft/laravel-rebel-ai-guard/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/padosoft/laravel-rebel-step-up/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
