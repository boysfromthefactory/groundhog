<?php

namespace BoysFromTheFactory\Groundhog\Exceptions;

use BoysFromTheFactory\Groundhog\Query\RecurringBuilder;
use LogicException;

/**
 * Thrown when a recurring model declares a custom Eloquent builder that does not extend
 * RecurringBuilder; using it would silently lose stored-row key lookups and bulk writes.
 */
final class IncompatibleEloquentBuilder extends LogicException
{
    public static function forModel(string $modelClass, string $builderClass): self
    {
        return new self(sprintf(
            'The Eloquent builder %s of recurring model %s must extend %s.',
            $builderClass,
            $modelClass,
            RecurringBuilder::class,
        ));
    }
}
