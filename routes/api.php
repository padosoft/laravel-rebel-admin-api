<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\Rebel\AdminApi\Http\Controllers\AuthEventsController;
use Padosoft\Rebel\AdminApi\Http\Controllers\HealthController;
use Padosoft\Rebel\AdminApi\Http\Controllers\OverviewController;
use Padosoft\Rebel\AdminApi\Http\Middleware\EnsureAdmin;

$prefix = config('rebel-admin-api.prefix', 'rebel/admin/api/v1');
$middleware = array_merge((array) config('rebel-admin-api.middleware', []), [EnsureAdmin::class]);

Route::prefix(is_string($prefix) ? $prefix : 'rebel/admin/api/v1')
    ->middleware($middleware)
    ->group(function (): void {
        Route::get('health', HealthController::class)->name('rebel-admin-api.health');
        Route::get('security/overview', OverviewController::class)->name('rebel-admin-api.overview');
        Route::get('auth-events', AuthEventsController::class)->name('rebel-admin-api.auth-events');
    });
