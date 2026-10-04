<?php

namespace BoysFromTheFactory\Groundhog\Casts;

use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;
use BoysFromTheFactory\Groundhog\Exceptions\InvalidRecurrenceRule;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RRule\RRule;

/**
 * The `recurrence_rule` attribute: reads the series rule as an `RRule` and records assigned
 * rules until the model is saved (FR-004, FR-028).
 *
 * The rule lives in `groundhog_recurrences`, not in the model's table, so `set()` returns no
 * attributes: returning a value would make Eloquent write a `recurrence_rule` column. The
 * rule state itself belongs to the HasRecurrence trait; this cast is its attribute adapter.
 *
 * @implements CastsAttributes<RRule|null, string|array<string, mixed>|RRule|null>
 */
final class AsRecurrenceRule implements CastsAttributes
{
    /**
     * Eloquent re-runs set() for cached cast objects on every save; a cached series rule would
     * then look like a fresh assignment on each save of a virtual occurrence or exception.
     */
    public bool $withoutObjectCaching = true;

    /**
     * Pending (assigned, unsaved) rule, else the stored rule of the series (for a virtual
     * occurrence: of its series), else null. The rule reflects the RRULE only; cancellations
     * and exceptions are not applied to it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?RRule
    {
        if (! method_exists($model, 'currentRecurrenceRule')) {
            throw self::notRecurring($model);
        }

        $rule = $model->currentRecurrenceRule();

        return $rule instanceof RRule ? $rule : null;
    }

    /**
     * Validates the input immediately and records it as pending; `null` records a removal.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, never>
     *
     * @throws InvalidRecurrenceRule
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! method_exists($model, 'assignRecurrenceRule')) {
            throw self::notRecurring($model);
        }

        $model->assignRecurrenceRule($value);

        return [];
    }

    private static function notRecurring(Model $model): LogicException
    {
        return new LogicException(sprintf('%s must use %s to cast recurrence_rule.', $model::class, HasRecurrence::class));
    }
}
