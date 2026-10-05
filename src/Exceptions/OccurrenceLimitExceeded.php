<?php

namespace BoysFromTheFactory\Groundhog\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown instead of exhausting time or memory when a rule or query would generate more
 * occurrences for one series than the configured limit allows (SC-005).
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
}
