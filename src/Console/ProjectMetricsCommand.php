<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Padosoft\Rebel\AdminApi\Metrics\MetricsProjector;
use Psr\Clock\ClockInterface;

/**
 * Rolls recent auth events up into hourly metric buckets. Schedule it hourly; the
 * default 2-hour window re-projects the current and previous hour so late-arriving
 * events are corrected (the upsert is idempotent).
 */
final class ProjectMetricsCommand extends Command
{
    protected $signature = 'rebel:project-metrics {--hours=2 : how many hours back to (re)project}';

    protected $description = 'Aggregate rebel_auth_events into hourly metric buckets.';

    public function handle(MetricsProjector $projector, ClockInterface $clock): int
    {
        $option = $this->option('hours');
        $hours = is_numeric($option) ? max(1, (int) $option) : 2;

        $now = CarbonImmutable::instance($clock->now());
        $from = $now->subHours($hours)->startOfHour();

        $written = $projector->project($from, $now);

        $this->info("Projected {$written} metric bucket(s) from {$from->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
