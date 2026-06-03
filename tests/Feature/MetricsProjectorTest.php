<?php

declare(strict_types=1);

use Padosoft\Rebel\AdminApi\Metrics\MetricsProjector;
use Padosoft\Rebel\AdminApi\Models\MetricBucket;
use Padosoft\Rebel\Core\Clock\FakeClock;
use Psr\Clock\ClockInterface;

it('projects events into hourly buckets and is idempotent', function (): void {
    app()->instance(ClockInterface::class, new FakeClock(new DateTimeImmutable('2026-01-01 10:30:00')));

    recordEvent('login.succeeded');
    recordEvent('login.succeeded');
    recordEvent('login.failed');
    recordEvent('channel.verification.started', 'sms');

    $projector = app(MetricsProjector::class);
    $window = [new DateTimeImmutable('2026-01-01 10:00:00'), new DateTimeImmutable('2026-01-01 11:00:00')];

    expect($projector->project(...$window))->toBe(3); // (succeeded), (failed), (verification.started/sms)
    expect(MetricBucket::query()->where('event_type', 'login.succeeded')->value('count'))->toBe(2);

    // Re-projecting the same window must not duplicate buckets (idempotent upsert).
    $projector->project(...$window);
    expect(MetricBucket::query()->count())->toBe(3)
        ->and(MetricBucket::query()->where('event_type', 'login.succeeded')->value('count'))->toBe(2);
});

it('serves the security overview KPIs from the event log', function (): void {
    app()->instance(ClockInterface::class, new FakeClock(new DateTimeImmutable('2026-01-01 10:30:00')));

    recordEvent('login.succeeded');
    recordEvent('login.succeeded');
    recordEvent('login.failed');

    actingAsAdmin();

    $this->getJson('/rebel/admin/api/v1/security/overview?days=1')
        ->assertOk()
        ->assertJsonPath('kpis.login_requests.value', 3);
});
