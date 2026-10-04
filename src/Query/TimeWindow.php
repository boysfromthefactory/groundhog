<?php

namespace BoysFromTheFactory\Groundhog\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Start-time bounds implied by a query's ordinary where clauses (research R5).
 *
 * Derivation is conservative: only clauses that are provably AND-ed with the whole query
 * contribute, so a bound can widen the set of occurrences that get evaluated but never hide
 * one that satisfies the query. Inclusive and exclusive comparisons both yield an inclusive
 * bound for the same reason.
 *
 * @internal
 */
final class TimeWindow
{
    private function __construct(
        private readonly ?CarbonImmutable $lowerStart,
        private readonly ?CarbonImmutable $upperStart,
        private readonly ?CarbonImmutable $lowerEnd,
    ) {}

    /**
     * @param  string  $startColumn  unqualified start column
     * @param  string|null  $endColumn  unqualified end column, null when the model has none
     * @param  string  $table  table name used to recognise qualified columns
     */
    public static function fromQuery(Builder $query, string $startColumn, ?string $endColumn, string $table): self
    {
        $bounds = ['lowerStart' => null, 'upperStart' => null, 'lowerEnd' => null];

        self::collect($query->wheres, $startColumn, $endColumn, $table, $bounds);

        return new self($bounds['lowerStart'], $bounds['upperStart'], $bounds['lowerEnd']);
    }

    /** Earliest start any matching occurrence can have, if the query implies one. */
    public function lowerStart(): ?CarbonImmutable
    {
        return $this->lowerStart;
    }

    /** Latest start any matching occurrence can have, if the query implies one. */
    public function upperStart(): ?CarbonImmutable
    {
        return $this->upperStart;
    }

    /**
     * Instant the FR-008 horizon is measured from: the lower start bound, else a lower end
     * bound (a running occurrence ends after it, so its start lies within the horizon), else
     * null (the caller uses now).
     */
    public function horizonBase(): ?CarbonImmutable
    {
        return $this->lowerStart ?? $this->lowerEnd;
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     * @param  array{lowerStart: ?CarbonImmutable, upperStart: ?CarbonImmutable, lowerEnd: ?CarbonImmutable}  $bounds
     */
    private static function collect(array $wheres, string $startColumn, ?string $endColumn, string $table, array &$bounds): void
    {
        // A single OR anywhere in the list turns the list into a disjunction, so none of its
        // clauses bounds the whole query (the first clause's boolean carries no meaning).
        foreach (array_slice($wheres, 1) as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                return;
            }
        }

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;

            if ($type === 'Nested' && isset($where['query']) && $where['query'] instanceof Builder) {
                self::collect($where['query']->wheres, $startColumn, $endColumn, $table, $bounds);

                continue;
            }

            $role = self::roleOf($where['column'] ?? null, $startColumn, $endColumn, $table);

            if ($role === null) {
                continue;
            }

            if ($type === 'Basic') {
                self::applyComparison($role, is_string($where['operator'] ?? null) ? $where['operator'] : '', self::parse($where['value'] ?? null), $bounds);
            } elseif ($type === 'between' && ! ($where['not'] ?? false) && is_array($where['values'] ?? null)) {
                $values = array_values($where['values']);
                self::applyComparison($role, '>=', self::parse($values[0] ?? null), $bounds);
                self::applyComparison($role, '<=', self::parse($values[1] ?? null), $bounds);
            }
        }
    }

    /**
     * @param  'start'|'end'  $role
     * @param  array{lowerStart: ?CarbonImmutable, upperStart: ?CarbonImmutable, lowerEnd: ?CarbonImmutable}  $bounds
     */
    private static function applyComparison(string $role, string $operator, ?CarbonImmutable $value, array &$bounds): void
    {
        if ($value === null) {
            return;
        }

        $isUpper = in_array($operator, ['<', '<=', '='], true);
        $isLower = in_array($operator, ['>', '>=', '='], true);

        // An occurrence never starts after it ends, so an upper end bound also caps its start.
        if ($isUpper) {
            $bounds['upperStart'] = self::earlier($bounds['upperStart'], $value);
        }

        if ($isLower && $role === 'start') {
            $bounds['lowerStart'] = self::later($bounds['lowerStart'], $value);
        }

        if ($isLower && $role === 'end') {
            $bounds['lowerEnd'] = self::later($bounds['lowerEnd'], $value);
        }
    }

    /**
     * @return 'start'|'end'|null
     */
    private static function roleOf(mixed $column, string $startColumn, ?string $endColumn, string $table): ?string
    {
        if (! is_string($column)) {
            return null;
        }

        $segments = explode('.', $column);
        $name = array_pop($segments);

        if ($segments !== [] && end($segments) !== $table) {
            return null;
        }

        return match ($name) {
            $startColumn, 'groundhog_original_starts_at' => 'start',
            $endColumn => 'end',
            default => null,
        };
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // Not a date literal (e.g. a free-text comparison): it cannot bound the window.
            return null;
        }
    }

    private static function earlier(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current === null || $candidate->lessThan($current) ? $candidate : $current;
    }

    private static function later(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
