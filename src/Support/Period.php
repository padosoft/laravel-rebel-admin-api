<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The time window a read model answers over. Resolved from the shared query parameters
 * (`from`, `to`, `granularity`, or the legacy `days`) and clamped to sane bounds. Also
 * exposes the immediately-preceding window of equal length so KPIs can report deltas.
 */
final readonly class Period
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $granularity,
        public string $label,
    ) {}

    /**
     * Build a period from the request. Accepts an explicit `from`/`to` (ISO-8601) or a
     * `days` shorthand; defaults to the last 24 hours.
     */
    public static function fromRequest(Request $request, CarbonImmutable $now): self
    {
        $granularity = $request->string('granularity')->toString();
        if (! in_array($granularity, ['minute', 'hour', 'day'], true)) {
            $granularity = 'hour';
        }

        $to = self::parse($request->string('to')->toString()) ?? $now;

        $from = self::parse($request->string('from')->toString());
        if ($from === null) {
            $days = $request->has('days') ? max(1, min(90, $request->integer('days'))) : 1;
            $from = $to->subDays($days);
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to, $granularity, self::deriveLabel($from, $to));
    }

    /** The window of equal length ending where this one starts (for delta-vs-previous). */
    public function previous(): self
    {
        $length = $this->to->getTimestamp() - $this->from->getTimestamp();
        $prevTo = $this->from;
        $prevFrom = $this->from->subSeconds(max(1, $length));

        return new self($prevFrom, $prevTo, $this->granularity, $this->label);
    }

    private static function parse(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function deriveLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $hours = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 3600);

        return match (true) {
            $hours <= 24 => '24h',
            $hours <= 24 * 7 => '7d',
            $hours <= 24 * 30 => '30d',
            default => 'custom',
        };
    }
}
