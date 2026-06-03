<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\Rebel\AdminApi\Http\Controllers\AiCopilotController;
use Padosoft\Rebel\AdminApi\Http\Controllers\AnomaliesController;
use Padosoft\Rebel\AdminApi\Http\Controllers\AuthEventsController;
use Padosoft\Rebel\AdminApi\Http\Controllers\ChannelsController;
use Padosoft\Rebel\AdminApi\Http\Controllers\ComplianceController;
use Padosoft\Rebel\AdminApi\Http\Controllers\FunnelController;
use Padosoft\Rebel\AdminApi\Http\Controllers\HealthController;
use Padosoft\Rebel\AdminApi\Http\Controllers\MeController;
use Padosoft\Rebel\AdminApi\Http\Controllers\OverviewController;
use Padosoft\Rebel\AdminApi\Http\Controllers\ProvidersController;
use Padosoft\Rebel\AdminApi\Http\Controllers\RiskRulesController;
use Padosoft\Rebel\AdminApi\Http\Controllers\SettingsController;
use Padosoft\Rebel\AdminApi\Http\Controllers\SubjectsController;
use Padosoft\Rebel\AdminApi\Http\Middleware\EnsureAdmin;

$prefix = config('rebel-admin-api.prefix', 'rebel/admin/api/v1');
$middleware = array_merge((array) config('rebel-admin-api.middleware', []), [EnsureAdmin::class]);

Route::prefix(is_string($prefix) ? $prefix : 'rebel/admin/api/v1')
    ->middleware($middleware)
    ->group(function (): void {
        // Identity & health.
        Route::get('me', MeController::class)->name('rebel-admin-api.me');
        Route::get('health', HealthController::class)->name('rebel-admin-api.health');

        // §3.1 Security overview.
        Route::get('security/overview', OverviewController::class)->name('rebel-admin-api.overview');

        // §3.2 Funnels.
        Route::get('otp/funnel', [FunnelController::class, 'otp'])->name('rebel-admin-api.otp-funnel');
        Route::get('step-up/funnel', [FunnelController::class, 'stepUp'])->name('rebel-admin-api.step-up-funnel');

        // §3.3 Channels & §3.4 providers.
        Route::get('channels/performance', ChannelsController::class)->name('rebel-admin-api.channels');
        Route::get('providers/health', ProvidersController::class)->name('rebel-admin-api.providers');

        // §3.5 Audit explorer.
        Route::get('auth-events', AuthEventsController::class)->name('rebel-admin-api.auth-events');
        Route::get('auth-events/{id}', [AuthEventsController::class, 'show'])->name('rebel-admin-api.auth-event');

        // §3.6 Device & session trust.
        Route::get('subjects', [SubjectsController::class, 'index'])->name('rebel-admin-api.subjects');
        Route::get('subjects/{subject}/devices', [SubjectsController::class, 'devices'])->name('rebel-admin-api.subject-devices');
        Route::get('subjects/{subject}/sessions', [SubjectsController::class, 'sessions'])->name('rebel-admin-api.subject-sessions');
        Route::post('subjects/{subject}/sessions/{id}/revoke', [SubjectsController::class, 'revokeSession'])->name('rebel-admin-api.session-revoke');
        Route::post('subjects/{subject}/logout-everywhere', [SubjectsController::class, 'logoutEverywhere'])->name('rebel-admin-api.logout-everywhere');
        Route::post('subjects/{subject}/devices/{id}/untrust', [SubjectsController::class, 'untrustDevice'])->name('rebel-admin-api.device-untrust');

        // §3.7 Risk rules.
        Route::get('risk-rules', [RiskRulesController::class, 'index'])->name('rebel-admin-api.risk-rules');
        Route::post('risk-rules', [RiskRulesController::class, 'store'])->name('rebel-admin-api.risk-rules-store');
        Route::post('risk-rules/simulate', [RiskRulesController::class, 'simulate'])->name('rebel-admin-api.risk-rules-simulate');

        // §3.8 Anomalies.
        Route::get('anomalies', [AnomaliesController::class, 'index'])->name('rebel-admin-api.anomalies');
        Route::get('anomalies/{case}', [AnomaliesController::class, 'show'])->name('rebel-admin-api.anomaly');
        Route::post('anomalies/{case}/actions', [AnomaliesController::class, 'actions'])->name('rebel-admin-api.anomaly-actions');

        // §3.9 AI security copilot.
        Route::post('ai/anomalies/{case}/explain', [AiCopilotController::class, 'explain'])->name('rebel-admin-api.ai-explain');
        Route::post('ai/policies/suggest', [AiCopilotController::class, 'suggest'])->name('rebel-admin-api.ai-suggest');

        // §3.10 Compliance.
        Route::get('compliance/overview', ComplianceController::class)->name('rebel-admin-api.compliance');

        // Generic panel settings.
        Route::get('settings', [SettingsController::class, 'index'])->name('rebel-admin-api.settings');
        Route::put('settings/{key}', [SettingsController::class, 'update'])->name('rebel-admin-api.settings-update');
    });
