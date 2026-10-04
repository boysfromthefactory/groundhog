<?php

namespace BoysFromTheFactory\Groundhog\Index;

use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use BoysFromTheFactory\Groundhog\Models\Recurrence;
use BoysFromTheFactory\Groundhog\Rules\RuleFactory;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RRule\RRule;

/**
 * Maintains `groundhog_occurrences`, the derived index of each rule's raw expansion.
 *
 * Invariant: a recurrence's rows equal its rule's occurrences in [DTSTART, materialized_until)
 * (all of them for a finite rule). Exclusions are never applied here; queries anti-join them,
 * so the index stays a pure function of rule, start and duration and concurrent writers cannot
 * resurrect an excluded start (research R3, R6).
 *
 * @internal
 */
final class OccurrenceIndex
{
    private const INSERT_CHUNK = 500;

    /**
     * Fills the index after a rule is saved: an infinite rule up to
     * max(now + horizon, previously materialised bound), a finite rule completely.
     *
     * @param  Model  $series  the stored series record
     *
     * @throws OccurrenceLimitExceeded when the pass would exceed groundhog.max_occurrences_per_series
     */
    public static function materialize(Model $series, Recurrence $recurrence, RRule $rule): void
    {
        if ($rule->isFinite()) {
            self::insert($series, $recurrence, self::generate($series, $rule, null, null));
            $recurrence->materialized_until = null;
            $recurrence->save();

            return;
        }

        $until = CarbonImmutable::now()->add(self::horizon());

        if ($recurrence->materialized_until !== null && $recurrence->materialized_until->greaterThan($until)) {
            $until = $recurrence->materialized_until;
        }

        self::insert($series, $recurrence, self::generate($series, $rule, null, $until));
        $recurrence->materialized_until = $until;
        $recurrence->save();
    }

    /**
     * Replaces the index after the rule, the series start or its duration changed.
     *
     * @param  Model  $series  the stored series record
     *
     * @throws OccurrenceLimitExceeded
     */
    public static function rebuild(Model $series, Recurrence $recurrence, RRule $rule): void
    {
        $series->getConnection()->table('groundhog_occurrences')->where('recurrence_id', $recurrence->getKey())->delete();

        self::materialize($series, $recurrence, $rule);
    }

    /**
     * Extends every infinite rule of the model's type whose index ends before `$until`, so a
     * query can see all occurrences starting before it.
     *
     * Each recurrence is extended in its own transaction holding a row lock, and the bound is
     * re-read under the lock, so concurrent readers extend each range once.
     *
     * @param  Model  $model  any instance of the recurring model; supplies type and connection
     * @param  CarbonInterface  $until  exclusive start bound the index must cover
     *
     * @throws OccurrenceLimitExceeded when `$until` lies beyond now + groundhog.max_materialization_ahead and
     *                                 some rule would have to be extended, or one pass exceeds the per-series limit
     */
    public static function ensureMaterialized(Model $model, CarbonInterface $until): void
    {
        $lagging = Recurrence::on($model->getConnectionName())
            ->where('recurrable_type', $model->getMorphClass())
            ->where('is_infinite', true)
            ->where('materialized_until', '<', $model->fromDateTime($until))
            ->pluck('recurrable_id', 'id');

        if ($lagging->isEmpty()) {
            return;
        }

        $ceiling = CarbonImmutable::now()->add(self::interval('groundhog.max_materialization_ahead'));

        if ($until->greaterThan($ceiling)) {
            throw OccurrenceLimitExceeded::beyondMaterializationCeiling($model->getMorphClass(), $until, $ceiling);
        }

        $seriesByKey = $model->newQueryWithoutScopes()->whereKey($lagging->values()->all())->get()->keyBy($model->getKeyName());

        foreach ($lagging as $recurrenceId => $seriesKey) {
            $series = is_int($seriesKey) || is_string($seriesKey) ? $seriesByKey->get($seriesKey) : null;

            if ($series === null) {
                continue;
            }

            $model->getConnection()->transaction(function () use ($model, $recurrenceId, $series, $until) {
                $recurrence = Recurrence::on($model->getConnectionName())->lockForUpdate()->find($recurrenceId);

                if ($recurrence === null || $recurrence->materialized_until === null || $recurrence->materialized_until->greaterThanOrEqualTo($until)) {
                    return;
                }

                $rule = RuleFactory::fromStored($recurrence->rule);
                self::insert($series, $recurrence, self::generate($series, $rule, $recurrence->materialized_until, $until));
                $recurrence->materialized_until = CarbonImmutable::instance($until);
                $recurrence->save();
            });
        }
    }

    /**
     * Occurrence starts in [$from, $until), or all of them when `$until` is null.
     *
     * @param  Model  $series  the stored series record, named in limit errors
     * @return list<CarbonImmutable>
     *
     * @throws OccurrenceLimitExceeded
     */
    private static function generate(Model $series, RRule $rule, ?CarbonInterface $from, ?CarbonInterface $until): array
    {
        $limit = self::limit();

        // Asking for one more than the limit is how an over-long pass is detected without
        // materialising it.
        $occurrences = $until === null
            ? $rule->getOccurrences($limit + 1)
            : $rule->getOccurrencesBetween($from, $until, $limit + 1);

        $starts = [];

        foreach ($occurrences as $occurrence) {
            $start = CarbonImmutable::instance($occurrence);

            if ($until !== null && $start->greaterThanOrEqualTo($until)) {
                continue;
            }

            $starts[] = $start;
        }

        if (count($starts) > $limit) {
            throw OccurrenceLimitExceeded::forSeries($series, $limit, $from, $until);
        }

        return $starts;
    }

    /**
     * @param  list<CarbonImmutable>  $starts
     */
    private static function insert(Model $series, Recurrence $recurrence, array $starts): void
    {
        $timezone = RecurrenceColumns::applicationTimezone();
        $columns = RecurrenceColumns::of($series);
        $duration = $columns->durationOf($series);
        $hasEnd = $columns->end !== null;

        $rows = array_map(function (CarbonImmutable $start) use ($series, $recurrence, $timezone, $duration, $hasEnd) {
            // Stored with the model's own date format in the app time zone, exactly as Eloquent
            // stores the start column, so equality joins and range filters compare like for like.
            $local = $start->setTimezone($timezone);

            return [
                'recurrence_id' => $recurrence->getKey(),
                'starts_at' => $series->fromDateTime($local),
                'ends_at' => $hasEnd && $duration !== null ? $series->fromDateTime($local->addSeconds($duration)) : null,
            ];
        }, $starts);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $series->getConnection()->table('groundhog_occurrences')->insertOrIgnore($chunk);
        }
    }

    /**
     * Length of the FR-008 expansion horizon (`groundhog.horizon`).
     *
     * @throws InvalidArgumentException when the configured value is not an ISO-8601 duration
     */
    public static function horizon(): CarbonInterval
    {
        return self::interval('groundhog.horizon');
    }

    private static function limit(): int
    {
        $limit = config('groundhog.max_occurrences_per_series');

        if (! is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException('Configuration value groundhog.max_occurrences_per_series must be a positive integer.');
        }

        return $limit;
    }

    private static function interval(string $key): CarbonInterval
    {
        $value = config($key);
        $interval = is_string($value) ? CarbonInterval::make($value) : null;

        if ($interval === null) {
            throw new InvalidArgumentException("Configuration value {$key} must be an ISO-8601 duration such as P1Y.");
        }

        return $interval;
    }
}
