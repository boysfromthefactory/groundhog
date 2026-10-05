<?php

namespace BoysFromTheFactory\Groundhog\Rules;

use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RRule\RRule;

/**
 * Expands one series' rule into occurrence starts. Every pass is bounded by
 * `groundhog.max_occurrences_per_series`, so no rule or query can exhaust time or memory (SC-005).
 *
 * @internal
 */
final class OccurrenceGenerator
{
    /**
     * Rejects a rule on save when a finite rule in full, or an infinite rule from DTSTART to
     * now + horizon, exceeds the limit. Such a rule would fail most later reads, and a dense
     * infinite rule makes every read far from DTSTART iterate all occurrences in between.
     *
     * @param  Model  $series  the series record, named in limit errors
     *
     * @throws OccurrenceLimitExceeded
     */
    public static function assertWithinLimit(Model $series, RRule $rule): void
    {
        self::startsBetween($series, $rule, null, $rule->isFinite() ? null : CarbonImmutable::now()->add(self::horizon()));
    }

    /**
     * Occurrence starts in [$from, $until), from DTSTART when `$from` is null and to the
     * rule's end when `$until` is null (callers pass an `$until` for infinite rules). Starts
     * keep the rule's time zone; they are native date-times because Carbon instances cost
     * several times more per occurrence than the rule engine itself.
     *
     * @param  Model  $series  the series record, named in limit errors
     * @return list<DateTimeImmutable>
     *
     * @throws OccurrenceLimitExceeded when the pass would produce more than the limit
     */
    public static function startsBetween(Model $series, RRule $rule, ?CarbonInterface $from, ?CarbonInterface $until): array
    {
        $limit = self::limit();
        $starts = [];

        // Asking for one more than the limit detects an over-long pass without generating it.
        foreach ($rule->getOccurrencesBetween($from, $until, $limit + 1) as $occurrence) {
            // php-rrule's end bound is inclusive.
            if ($until !== null && $occurrence >= $until) {
                continue;
            }

            $starts[] = DateTimeImmutable::createFromInterface($occurrence);
        }

        if (count($starts) > $limit) {
            throw OccurrenceLimitExceeded::forSeries($series, $limit, $from, $until);
        }

        return $starts;
    }

    /**
     * Length of the FR-008 expansion horizon (`groundhog.horizon`).
     *
     * @throws InvalidArgumentException when the configured value is not an ISO-8601 duration
     */
    public static function horizon(): CarbonInterval
    {
        $value = config('groundhog.horizon');
        $interval = is_string($value) ? CarbonInterval::make($value) : null;

        if ($interval === null) {
            throw new InvalidArgumentException('Configuration value groundhog.horizon must be an ISO-8601 duration such as P1Y.');
        }

        return $interval;
    }

    private static function limit(): int
    {
        $limit = config('groundhog.max_occurrences_per_series');

        if (! is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException('Configuration value groundhog.max_occurrences_per_series must be a positive integer.');
        }

        return $limit;
    }
}
