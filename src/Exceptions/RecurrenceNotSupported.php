<?php

namespace BoysFromTheFactory\Groundhog\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when an operation cannot apply to a recurring model in its current role.
 */
final class RecurrenceNotSupported extends LogicException
{
    /**
     * An occurrence exception replaces exactly one occurrence, so it cannot itself recur.
     */
    public static function forOccurrenceException(Model $model): self
    {
        return new self(sprintf(
            'Cannot attach a recurrence rule to %s [%s]: it is an occurrence exception of another series.',
            $model::class,
            self::keyOf($model),
        ));
    }

    /**
     * The series start is the rule's DTSTART; without it no occurrence can be computed.
     */
    public static function missingStart(Model $model): self
    {
        return new self(sprintf(
            'Cannot save a recurrence rule on %s [%s]: the start attribute is empty.',
            $model::class,
            self::keyOf($model),
        ));
    }

    /**
     * Key-based iteration pages by "key > last key"; virtual occurrences have no key and
     * would be skipped silently.
     */
    public static function forKeyedIteration(string $method): self
    {
        return new self(sprintf(
            '%s() cannot iterate expanded occurrences because virtual occurrences have no primary key; use chunk()/lazy(), or withoutOccurrences() for stored rows only.',
            $method,
        ));
    }

    private static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : 'new';
    }
}
