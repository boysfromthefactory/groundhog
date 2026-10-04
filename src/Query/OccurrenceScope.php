<?php

namespace BoysFromTheFactory\Groundhog\Query;

use BoysFromTheFactory\Groundhog\Index\OccurrenceIndex;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Replaces a recurring model's table with a derived table of the same name in which every
 * series row is replaced by its occurrences (research R4).
 *
 * Because the derived table is aliased as the model's own table, every ordinary Eloquent read
 * (constraints, joins, whereHas, ordering, aggregates, pagination counts) runs unchanged in SQL
 * over the expanded rows (FR-007, FR-009). Virtual rows carry a null key plus the identity
 * attributes `groundhog_series_key` and `groundhog_original_starts_at`.
 *
 * @internal
 *
 * @implements Scope<Model>
 */
final class OccurrenceScope implements Scope
{
    public const SERIES_KEY = 'groundhog_series_key';

    public const ORIGINAL_START = 'groundhog_original_starts_at';

    /**
     * Table columns per connection and table; read once per process, like Eloquent's own
     * guardable-column check.
     *
     * @var array<string, list<string>>
     */
    private static array $columns = [];

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $query = $builder->getQuery();

        // Only a plain table reference can be swapped; an expression means the FROM was
        // already replaced or deliberately customised.
        if (! is_string($query->from)) {
            return;
        }

        $alias = self::aliasOf($query->from);

        // Virtual occurrences have no key, so a query that pins the key (find, whereKey, a
        // belongs-to relation, destroy, whereHas from another model) can only mean stored
        // rows; expanding would hide the series row it asks for (FR-015).
        if (self::pinsPrimaryKey($query, $alias, $model->getKeyName())) {
            $builder->withoutGlobalScope(self::class);

            return;
        }

        $columns = RecurrenceColumns::of($model);
        $window = TimeWindow::fromQuery($query, $columns->start, $columns->end, $alias);

        $cap = $window->upperStart() === null
            ? ($window->horizonBase() ?? CarbonImmutable::now())->add(OccurrenceIndex::horizon())
            : null;

        // Index rows are needed for every start up to and including the upper bound.
        OccurrenceIndex::ensureMaterialized($model, $window->upperStart()?->addSecond() ?? $cap ?? CarbonImmutable::now());

        $query->fromSub($this->derivedTable($model, $columns, $window, $cap), $alias);

        $this->breakOrderingTies($query, $alias, $model->getKeyName());
        $this->keepIdentityColumns($query, $alias);
    }

    /**
     * Whether a top-level, AND-ed clause equates the primary key with a value, a list or a
     * column. Clauses under OR prove nothing, so they leave the query expanded.
     */
    private static function pinsPrimaryKey(QueryBuilder $query, string $alias, string $keyName): bool
    {
        $wheres = $query->wheres;

        foreach (array_slice($wheres, 1) as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }
        }

        $isKey = fn (mixed $column): bool => $column === $keyName || $column === $alias.'.'.$keyName;

        foreach ($wheres as $where) {
            $pinned = match ($where['type'] ?? null) {
                'Basic' => ($where['operator'] ?? null) === '=' && $isKey($where['column'] ?? null),
                'In', 'InRaw' => $isKey($where['column'] ?? null),
                'Column' => ($where['operator'] ?? null) === '=' && ($isKey($where['first'] ?? null) || $isKey($where['second'] ?? null)),
                default => false,
            };

            if ($pinned) {
                return true;
            }
        }

        return false;
    }

    /**
     * Occurrences of different series can tie on every requested ordering; without a total
     * order the database may return them in a different order on each page (FR-016). Grouped,
     * distinct and union queries are left alone: databases reject ORDER BY columns outside the
     * grouped or selected set.
     */
    private function breakOrderingTies(QueryBuilder $query, string $alias, string $keyName): void
    {
        if (empty($query->orders) || ! empty($query->groups) || $query->distinct !== false || ! empty($query->unions)) {
            return;
        }

        $query->orderBy($alias.'.'.self::SERIES_KEY)
            ->orderBy($alias.'.'.self::ORIGINAL_START)
            ->orderBy($alias.'.'.$keyName);
    }

    /**
     * A partial select would otherwise drop the identity attributes, leaving virtual rows that
     * can no longer be saved or deleted as occurrences.
     */
    private function keepIdentityColumns(QueryBuilder $query, string $alias): void
    {
        if (empty($query->columns) || ! empty($query->groups) || $query->distinct !== false) {
            return;
        }

        foreach ($query->columns as $column) {
            if (is_string($column) && ($column === '*' || str_ends_with($column, '.*'))) {
                return;
            }
        }

        $query->addSelect([$alias.'.'.self::SERIES_KEY, $alias.'.'.self::ORIGINAL_START]);
    }

    private function derivedTable(Model $model, RecurrenceColumns $columns, TimeWindow $window, ?CarbonImmutable $cap): QueryBuilder
    {
        $keyName = $model->getKeyName();
        $deletedAt = self::deletedAtColumnOf($model);
        $select = [];

        foreach (self::columnsOf($model) as $column) {
            $select[] = match ($column) {
                $keyName => new IdentifierSql('case when %s is null then %s end as %s', ['gh_o.recurrence_id', 'gh_m.'.$column, $column]),
                $columns->start => new IdentifierSql('coalesce(%s, %s) as %s', ['gh_o.starts_at', 'gh_m.'.$column, $column]),
                $columns->end => new IdentifierSql('coalesce(%s, %s) as %s', ['gh_o.ends_at', 'gh_m.'.$column, $column]),
                // An exception of a trashed series is hidden with it and returns on restore (FR-023).
                $deletedAt => new IdentifierSql('coalesce(%s, %s) as %s', ['gh_m.'.$column, 'gh_s.'.$column, $column]),
                default => 'gh_m.'.$column,
            };
        }

        $select[] = new IdentifierSql('case when %s is not null then %s else %s end as %s', ['gh_o.recurrence_id', 'gh_r.recurrable_id', 'gh_xl.series_key', self::SERIES_KEY]);
        $select[] = new IdentifierSql('coalesce(%s, %s) as %s', ['gh_o.starts_at', 'gh_xl.original_starts_at', self::ORIGINAL_START]);

        // The type filter sits inside the subquery: a plain join on exception_id would duplicate
        // a row whenever exceptions of two model types share a key value.
        $exceptionLinks = $model->getConnection()->query()
            ->from('groundhog_exclusions as gh_xe')
            ->join('groundhog_recurrences as gh_re', 'gh_re.id', '=', 'gh_xe.recurrence_id')
            ->where('gh_re.recurrable_type', '=', $model->getMorphClass())
            ->whereNotNull('gh_xe.exception_id')
            ->select(['gh_xe.exception_id', 'gh_xe.original_starts_at', 'gh_re.recurrable_id as series_key']);

        // Joins that depend only on the stored row come first, so they run once per record
        // rather than once per occurrence (left joins keep their written order).
        $derived = $model->getConnection()->query()
            ->from($model->getTable().' as gh_m')
            ->select($select)
            ->leftJoinSub($exceptionLinks, 'gh_xl', 'gh_xl.exception_id', '=', 'gh_m.'.$keyName);

        if ($deletedAt !== null) {
            $derived->leftJoin($model->getTable().' as gh_s', 'gh_s.'.$keyName, '=', 'gh_xl.series_key');
        }

        $derived
            ->leftJoin('groundhog_recurrences as gh_r', function (JoinClause $join) use ($model, $keyName) {
                $join->where('gh_r.recurrable_type', '=', $model->getMorphClass())
                    ->on('gh_r.recurrable_id', '=', 'gh_m.'.$keyName);
            })
            ->leftJoin('groundhog_occurrences as gh_o', function (JoinClause $join) use ($model, $window) {
                $join->on('gh_o.recurrence_id', '=', 'gh_r.id');

                // The user's own predicates imply these bounds; repeating them on the join lets
                // databases without derived-table predicate push-down use the index.
                if ($window->lowerStart() !== null) {
                    $join->where('gh_o.starts_at', '>=', $model->fromDateTime($window->lowerStart()));
                }

                if ($window->upperStart() !== null) {
                    $join->where('gh_o.starts_at', '<=', $model->fromDateTime($window->upperStart()));
                }
            })
            ->leftJoin('groundhog_exclusions as gh_xo', function (JoinClause $join) {
                $join->on('gh_xo.recurrence_id', '=', 'gh_o.recurrence_id')
                    ->on('gh_xo.original_starts_at', '=', 'gh_o.starts_at');
            });

        return $derived->where(function (QueryBuilder $rows) use ($model, $cap) {
            $rows->whereNull('gh_r.id')->orWhere(function (QueryBuilder $occurrences) use ($model, $cap) {
                $occurrences->whereNotNull('gh_o.recurrence_id')->whereNull('gh_xo.recurrence_id');

                // The horizon is a predicate, not a property of what happens to be indexed, so
                // results never depend on which queries ran before (determinism).
                if ($cap !== null) {
                    $occurrences->where(function (QueryBuilder $capped) use ($model, $cap) {
                        $capped->whereRaw(new IdentifierSql('not %s', ['gh_r.is_infinite']))
                            ->orWhere('gh_o.starts_at', '<', $model->fromDateTime($cap));
                    });
                }
            });
        });
    }

    /**
     * Deleted-at column of a soft-deletable model, else null.
     */
    private static function deletedAtColumnOf(Model $model): ?string
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return null;
        }

        $column = $model->getDeletedAtColumn();

        return is_string($column) ? $column : null;
    }

    /**
     * @return list<string>
     */
    private static function columnsOf(Model $model): array
    {
        $key = $model->getConnectionName().'|'.$model->getTable();

        return self::$columns[$key] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
    }

    /**
     * `meetings` or `meetings as m` → the name the rest of the query refers to.
     */
    private static function aliasOf(string $from): string
    {
        $parts = preg_split('/\s+as\s+/i', trim($from)) ?: [$from];

        return trim($parts[count($parts) - 1]);
    }
}
