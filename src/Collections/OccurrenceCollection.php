<?php

namespace BoysFromTheFactory\Groundhog\Collections;

use BoysFromTheFactory\Groundhog\Query\OccurrenceScope;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent collection of a recurring model.
 *
 * Eloquent's set operations key models by primary key; virtual occurrences have none, so
 * they would collapse into one or vanish. Here a virtual occurrence is keyed by its identity:
 * series key plus original start.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
final class OccurrenceCollection extends Collection
{
    /**
     * @param  mixed  $key  a primary key, a model (stored or virtual) or a list of keys
     * @param  mixed  $default
     * @return mixed
     */
    public function find($key, $default = null)
    {
        if ($key instanceof Model && $key->getKey() === null) {
            $identity = self::identityOf($key);

            return $this->first(fn (Model $model) => $identity !== null && self::identityOf($model) === $identity, $default);
        }

        return parent::find($key, $default);
    }

    /**
     * @param  iterable<array-key, TModel>  $items
     * @return static
     */
    public function merge($items)
    {
        $dictionary = $this->getDictionary();

        foreach ($items as $item) {
            $key = self::identityOf($item);

            if ($key !== null) {
                $dictionary[$key] = $item;
            }
        }

        return new self(array_values($dictionary));
    }

    /**
     * @param  iterable<array-key, TModel>  $items
     * @return static
     */
    public function diff($items)
    {
        $dictionary = $this->getDictionary($items);
        $diff = new self;

        foreach ($this->items as $item) {
            $key = self::identityOf($item);

            if ($key === null || ! isset($dictionary[$key])) {
                $diff->add($item);
            }
        }

        return $diff;
    }

    /**
     * @param  iterable<array-key, TModel>  $items
     * @return static
     */
    public function intersect($items)
    {
        $dictionary = $this->getDictionary($items);
        $intersect = new self;

        foreach ($this->items as $item) {
            $key = self::identityOf($item);

            if ($key !== null && isset($dictionary[$key])) {
                $intersect->add($item);
            }
        }

        return $intersect;
    }

    /**
     * Dictionary keyed by primary key for stored models and by occurrence identity for
     * virtual occurrences.
     *
     * @param  iterable<array-key, TModel>|null  $items
     * @return array<array-key, TModel>
     */
    public function getDictionary($items = null)
    {
        $dictionary = [];

        foreach ($items ?? $this->items as $model) {
            $key = self::identityOf($model);

            if ($key !== null) {
                $dictionary[$key] = $model;
            }
        }

        return $dictionary;
    }

    /**
     * Primary key, or `"{series key}@{original start ISO-8601}"` for a virtual occurrence.
     */
    private static function identityOf(Model $model): int|string|null
    {
        $key = $model->getKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        $attributes = $model->getAttributes();
        $series = $attributes[OccurrenceScope::SERIES_KEY] ?? null;
        $start = RecurrenceColumns::parse($attributes[OccurrenceScope::ORIGINAL_START] ?? null);

        return (is_int($series) || is_string($series)) && $start !== null
            ? $series.'@'.$start->toIso8601String()
            : null;
    }
}
