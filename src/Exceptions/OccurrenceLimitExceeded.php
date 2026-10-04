<?php

namespace BoysFromTheFactory\Groundhog\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown instead of exhausting time, memory or storage when a rule or query would generate
 * more occurrences than the configured limits allow (SC-005, FR-008).
 */
final class OccurrenceLimitExceeded extends RuntimeException
{
    /**
     * One generation pass for one series would exceed `groundhog.max_occurrences_per_series`.
     */
    public static function forSeries(Model $series, int $limit, ?CarbonInterface $from, ?CarbonInterface $until): self
    {
        $key = $series->getKey();

        return new self(sprintf(
            'Recurrence of %s [%s] generates more than %d occurrences between %s and %s; raise groundhog.max_occurrences_per_series or narrow the rule.',
            $series::class,
            is_scalar($key) ? (string) $key : 'new',
            $limit,
            $from?->toIso8601String() ?? 'its start',
            $until?->toIso8601String() ?? 'its end',
        ));
    }

    /**
     * A read would extend the occurrence index beyond `now + groundhog.max_materialization_ahead`.
     */
    public static function beyondMaterializationCeiling(string $morphClass, CarbonInterface $until, CarbonInterface $ceiling): self
    {
        return new self(sprintf(
            'Querying %s occurrences up to %s requires generating beyond the materialisation ceiling %s; constrain the query or raise groundhog.max_materialization_ahead.',
            $morphClass,
            $until->toIso8601String(),
            $ceiling->toIso8601String(),
        ));
    }
}
