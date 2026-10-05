<?php

namespace BoysFromTheFactory\Groundhog\Query;

use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use BoysFromTheFactory\Groundhog\Rules\OccurrenceGenerator;
use BoysFromTheFactory\Groundhog\Rules\RuleFactory;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use RRule\RRule;

/**
 * The occurrences one query can match, generated from the stored rules each time the query is
 * built and passed to the database as a single JSON parameter, so nothing derived from a rule is
 * ever stored (FR-002). One parameter avoids the bind-parameter limits a VALUES list would hit.
 *
 * The rows are the rules' raw expansion; the caller applies exclusions.
 *
 * @internal
 */
final class OccurrenceRows
{
    /**
     * A subquery with columns `recurrence_id`, `starts_at` and `ends_at` holding every
     * occurrence of the model's series whose start lies inside the window: from its lower start
     * bound (raised per series by a lower end bound minus the series' duration) up to and
     * including its upper start bound, else up to `$cap` for infinite rules.
     *
     * @param  Model  $model  any instance of the recurring model; supplies type and connection
     * @param  CarbonImmutable|null  $cap  exclusive start bound for infinite rules; null when the window has an upper start bound
     *
     * @throws OccurrenceLimitExceeded when one series would generate more than the per-series limit
     * @throws RecurrenceNotSupported when the connection's database is not supported
     */
    public static function forQuery(Model $model, TimeWindow $window, ?CarbonImmutable $cap): QueryBuilder
    {
        $rows = [];

        foreach (self::seriesOf($model) as ['recurrence_id' => $recurrenceId, 'rule' => $rule, 'series' => $series]) {
            array_push($rows, ...self::rowsOf($recurrenceId, RuleFactory::fromStored($rule), $series, $window, $cap));
        }

        return self::tableOf($model, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * Every stored series of the model's type with its recurrence id and rule text, trashed
     * series included (the soft-delete scope filters their occurrences later).
     *
     * @return list<array{recurrence_id: int|string, rule: string, series: Model}>
     */
    private static function seriesOf(Model $model): array
    {
        $table = $model->getTable();

        // A join rather than a key list: a long list of string keys would exceed SQLite's
        // bind-parameter limit.
        $records = $model->getConnection()->table($table)
            ->join('groundhog_recurrences as gh_r', function ($join) use ($model, $table) {
                $join->where('gh_r.recurrable_type', '=', $model->getMorphClass())
                    ->on('gh_r.recurrable_id', '=', $table.'.'.$model->getKeyName());
            })
            ->select([$table.'.*', 'gh_r.id as groundhog_recurrence_id', 'gh_r.rule as groundhog_rule'])
            ->get();

        $series = [];

        foreach ($records as $record) {
            $attributes = (array) $record;
            $recurrenceId = $attributes['groundhog_recurrence_id'] ?? null;
            $rule = $attributes['groundhog_rule'] ?? null;
            unset($attributes['groundhog_recurrence_id'], $attributes['groundhog_rule']);

            if ((is_int($recurrenceId) || is_string($recurrenceId)) && is_string($rule)) {
                $series[] = [
                    'recurrence_id' => $recurrenceId,
                    'rule' => $rule,
                    'series' => $model->newInstance([], true)->setRawAttributes($attributes, true),
                ];
            }
        }

        return $series;
    }

    /**
     * @param  Model  $series  the stored series record
     * @return list<array{int|string, string, string|null}>
     *
     * @throws OccurrenceLimitExceeded
     */
    private static function rowsOf(int|string $recurrenceId, RRule $rule, Model $series, TimeWindow $window, ?CarbonImmutable $cap): array
    {
        $duration = RecurrenceColumns::of($series)->durationOf($series);

        // Dates are second-precision, so one second past the inclusive upper bound is exclusive.
        $until = $window->upperStart()?->addSecond() ?? ($rule->isInfinite() ? $cap : null);
        $starts = OccurrenceGenerator::startsBetween($series, $rule, $window->lowerStartFor($duration), $until);

        // Formatted as Eloquent stores date-times (model date format, app time zone), so the
        // caller's comparisons and the exclusion join compare like for like.
        $timezone = new DateTimeZone(RecurrenceColumns::applicationTimezone());
        $format = $series->getDateFormat();

        return array_map(function (DateTimeImmutable $start) use ($recurrenceId, $timezone, $format, $duration) {
            $local = $start->setTimezone($timezone);

            return [
                $recurrenceId,
                $local->format($format),
                $duration === null ? null : $local->setTimestamp($local->getTimestamp() + $duration)->format($format),
            ];
        }, $starts);
    }

    /**
     * Unpacks the JSON array of `[recurrence_id, starts_at, ends_at]` rows with the database's
     * own JSON table function; no portable SQL exists for this.
     *
     * @throws RecurrenceNotSupported
     */
    private static function tableOf(Model $model, string $json): QueryBuilder
    {
        $connection = $model->getConnection();
        $query = $connection->query();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => $query->fromRaw('json_each(?) as gh_jt', [$json])->select([
                new Expression("json_extract(gh_jt.value, '$[0]') as recurrence_id"),
                new Expression("json_extract(gh_jt.value, '$[1]') as starts_at"),
                new Expression("json_extract(gh_jt.value, '$[2]') as ends_at"),
            ]),
            'mysql', 'mariadb' => $query->fromRaw(
                "json_table(?, '$[*]' columns (recurrence_id bigint path '$[0]', starts_at datetime path '$[1]', ends_at datetime path '$[2]')) as gh_jt",
                [$json],
            )->select(['gh_jt.recurrence_id', 'gh_jt.starts_at', 'gh_jt.ends_at']),
            'pgsql' => $query->fromRaw('json_array_elements(cast(? as json)) as gh_jt(item)', [$json])->select([
                new Expression('cast(gh_jt.item->>0 as bigint) as recurrence_id'),
                new Expression('cast(gh_jt.item->>1 as timestamp) as starts_at'),
                new Expression('cast(gh_jt.item->>2 as timestamp) as ends_at'),
            ]),
            default => throw RecurrenceNotSupported::forDatabaseDriver($driver),
        };
    }
}
