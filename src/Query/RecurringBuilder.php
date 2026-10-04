<?php

namespace BoysFromTheFactory\Groundhog\Query;

use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use BoysFromTheFactory\Groundhog\Index\ExceptionLedger;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Eloquent builder of every recurring model.
 *
 * Reads go through OccurrenceScope and see expanded occurrences. Key lookups act on stored
 * rows (FR-015) because series rows are absent from the expanded set, and key-based
 * iteration is rejected because virtual occurrences have no key to page by.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class RecurringBuilder extends Builder
{
    /** Set by the `series` relation: series rows are never exceptions, so no lookup is needed. */
    protected bool $skipsExceptionLinkLookup = false;

    /**
     * Removes occurrence expansion: the query sees stored rows (plain records, series and
     * exceptions), like `withTrashed()` does for soft deletes. Never required for normal use.
     */
    public function withoutOccurrences(): static
    {
        return $this->withoutGlobalScope(OccurrenceScope::class);
    }

    /**
     * Whether results will be expanded into occurrences.
     */
    public function expandsOccurrences(): bool
    {
        return isset($this->scopes[OccurrenceScope::class]);
    }

    /**
     * @internal Used by the `series` relation.
     */
    public function withoutExceptionLinkLookup(): static
    {
        $this->skipsExceptionLinkLookup = true;

        return $this;
    }

    /**
     * Stored rows read without expansion get the identity attributes expanded rows carry, in
     * one query per result set and before eager loading, so `toArray()` and `with('series')`
     * behave the same whichever way a record was loaded.
     *
     * @param  array<int, string>|string  $columns
     * @return array<int, TModel>
     */
    public function getModels($columns = ['*'])
    {
        $models = parent::getModels($columns);

        if ($models === [] || $this->expandsOccurrences()) {
            return $models;
        }

        $unresolved = array_filter($models, fn (Model $model) => ! array_key_exists(OccurrenceScope::SERIES_KEY, $model->getAttributes()));
        $links = $this->skipsExceptionLinkLookup ? [] : ExceptionLedger::exceptionLinksFor($unresolved);

        foreach ($unresolved as $model) {
            $key = $model->getKey();
            $link = is_int($key) || is_string($key) ? ($links[$key] ?? null) : null;

            $model->setRawAttributes([
                ...$model->getAttributes(),
                OccurrenceScope::SERIES_KEY => $link['series_key'] ?? null,
                OccurrenceScope::ORIGINAL_START => $link['original_starts_at'] ?? null,
            ], true);
        }

        return $models;
    }

    /**
     * @param  mixed  $id
     * @return $this
     */
    public function whereKeyNot($id)
    {
        $this->withoutOccurrences();

        return parent::whereKeyNot($id);
    }

    /**
     * Backs chunkById(), chunkByIdDesc() and eachById().
     *
     * @param  int  $count
     * @param  string|null  $column
     * @param  string|null  $alias
     * @param  bool  $descending
     * @return bool
     *
     * @throws RecurrenceNotSupported while occurrences are expanded
     */
    public function orderedChunkById($count, callable $callback, $column = null, $alias = null, $descending = false)
    {
        if ($this->expandsOccurrences()) {
            throw RecurrenceNotSupported::forKeyedIteration('chunkById');
        }

        return parent::orderedChunkById($count, $callback, $column, $alias, $descending);
    }

    /**
     * Backs lazyById() and lazyByIdDesc().
     *
     * @param  int  $chunkSize
     * @param  string|null  $column
     * @param  string|null  $alias
     * @param  bool  $descending
     * @return LazyCollection<int, TModel>
     *
     * @throws RecurrenceNotSupported while occurrences are expanded
     */
    protected function orderedLazyById($chunkSize = 1000, $column = null, $alias = null, $descending = false)
    {
        if ($this->expandsOccurrences()) {
            throw RecurrenceNotSupported::forKeyedIteration('lazyById');
        }

        return parent::orderedLazyById($chunkSize, $column, $alias, $descending);
    }

    /*
     * Query-level writes act on stored rows with the caller's constraints and never create
     * exceptions or cancellations (FR-026). Each would otherwise pass through applyScopes()
     * and target the derived occurrence table. forceDelete() needs no override: Eloquent runs
     * it on the unscoped base query.
     */

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->withoutOccurrences();

        return parent::update($values);
    }

    /**
     * @return mixed
     */
    public function delete()
    {
        $this->withoutOccurrences();

        return parent::delete();
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->withoutOccurrences();

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  array<int, string>|string|null  $column
     * @return int|false
     */
    public function touch($column = null)
    {
        $this->withoutOccurrences();

        return parent::touch($column);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->withoutOccurrences();

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->withoutOccurrences();

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->withoutOccurrences();

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return int
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->withoutOccurrences();

        return parent::decrementEach($columns, $extra);
    }

    /**
     * insert(), insertGetId(), insertOrIgnore(), insertOrIgnoreReturning(), insertUsing() and
     * insertOrIgnoreUsing() are forwarded to the base query through __call().
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (str_starts_with(strtolower($method), 'insert')) {
            $this->withoutOccurrences();
        }

        return parent::__call($method, $parameters);
    }
}
