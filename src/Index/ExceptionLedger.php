<?php

namespace BoysFromTheFactory\Groundhog\Index;

use BoysFromTheFactory\Groundhog\Models\Recurrence;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes and reads `groundhog_exclusions`: the original starts a series must not generate.
 * A row with `exception_id` is an exception link; a row without one is a cancellation.
 * This class is the only writer of the table.
 *
 * @internal
 */
final class ExceptionLedger
{
    /**
     * Links a newly stored exception to the occurrence it replaces.
     *
     * A unique (recurrence, original start) violation propagates on purpose: it rolls back the
     * surrounding transaction, so a second exception for the same occurrence leaves no orphan
     * row (FR-025).
     *
     * @param  Model  $model  any instance of the recurring model; supplies type and connection
     * @param  int|string  $seriesKey  primary key of the series
     * @param  int|string  $exceptionKey  primary key of the stored exception
     */
    public static function recordException(Model $model, int|string $seriesKey, CarbonInterface $originalStart, int|string $exceptionKey): void
    {
        self::insert($model, $seriesKey, $originalStart, $exceptionKey);
    }

    /**
     * Cancels one occurrence without storing a record for it (FR-019).
     *
     * @param  Model  $model  any instance of the recurring model; supplies type and connection
     * @param  int|string  $seriesKey  primary key of the series
     */
    public static function cancel(Model $model, int|string $seriesKey, CarbonInterface $originalStart): void
    {
        self::insert($model, $seriesKey, $originalStart, null);
    }

    /**
     * After an exception is hard-deleted its link becomes a cancellation, so the occurrence
     * stays cancelled instead of reverting to the series' values (FR-020).
     */
    public static function releaseException(Model $exception): void
    {
        $key = $exception->getKey();

        if (! (is_int($key) || is_string($key))) {
            return;
        }

        $exception->getConnection()->table('groundhog_exclusions')
            ->where('exception_id', $key)
            ->whereIn('recurrence_id', fn ($recurrences) => $recurrences
                ->from('groundhog_recurrences')
                ->select('id')
                ->where('recurrable_type', $exception->getMorphClass()))
            ->update(['exception_id' => null, 'updated_at' => $exception->freshTimestampString()]);
    }

    /**
     * Deletes every exclusion of a series whose rule or start changed: its exceptions become
     * plain records and its cancellations no longer apply to the new occurrences (FR-022).
     */
    public static function resetExclusions(Recurrence $recurrence): void
    {
        $recurrence->getConnection()->table('groundhog_exclusions')->where('recurrence_id', $recurrence->getKey())->delete();
    }

    /**
     * Primary keys of the stored exceptions of a series, including soft-deleted ones.
     *
     * @return list<int|string>
     */
    public static function exceptionKeysOf(Recurrence $recurrence): array
    {
        $keys = [];

        foreach ($recurrence->getConnection()->table('groundhog_exclusions')->where('recurrence_id', $recurrence->getKey())->whereNotNull('exception_id')->pluck('exception_id') as $key) {
            if (is_int($key) || is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Series key and original start of every given model that is an exception, keyed by the
     * model's primary key; models that are not exceptions are absent. One query.
     *
     * @param  iterable<Model>  $models  instances of one recurring model
     * @return array<array-key, array{series_key: int|string, original_starts_at: string}>
     */
    public static function exceptionLinksFor(iterable $models): array
    {
        $prototype = null;
        $keys = [];

        foreach ($models as $model) {
            $prototype ??= $model;
            $key = $model->getKey();

            if (is_int($key) || is_string($key)) {
                $keys[] = $key;
            }
        }

        if ($prototype === null || $keys === []) {
            return [];
        }

        $links = [];

        $rows = $prototype->getConnection()->table('groundhog_exclusions as gh_xe')
            ->join('groundhog_recurrences as gh_re', 'gh_re.id', '=', 'gh_xe.recurrence_id')
            ->where('gh_re.recurrable_type', $prototype->getMorphClass())
            ->whereIn('gh_xe.exception_id', $keys)
            ->get(['gh_xe.exception_id', 'gh_xe.original_starts_at', 'gh_re.recurrable_id']);

        foreach ($rows as $row) {
            $exceptionId = $row->exception_id ?? null;
            $seriesKey = $row->recurrable_id ?? null;
            $original = $row->original_starts_at ?? null;

            if ((is_int($exceptionId) || is_string($exceptionId)) && (is_int($seriesKey) || is_string($seriesKey)) && is_string($original)) {
                $links[$exceptionId] = ['series_key' => $seriesKey, 'original_starts_at' => $original];
            }
        }

        return $links;
    }

    private static function insert(Model $model, int|string $seriesKey, CarbonInterface $originalStart, int|string|null $exceptionKey): void
    {
        $recurrenceId = Recurrence::on($model->getConnectionName())
            ->where('recurrable_type', $model->getMorphClass())
            ->where('recurrable_id', $seriesKey)
            ->valueOrFail('id');

        $now = $model->freshTimestampString();

        $model->getConnection()->table('groundhog_exclusions')->insert([
            'recurrence_id' => $recurrenceId,
            // Same representation as the generated occurrence start it suppresses, so the anti-join matches exactly.
            'original_starts_at' => $model->fromDateTime($originalStart->setTimezone(RecurrenceColumns::applicationTimezone())),
            'exception_id' => $exceptionKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
