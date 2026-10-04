<?php

namespace BoysFromTheFactory\Groundhog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The stored rule of one series (`groundhog_recurrences`). Applications should change rules
 * through the recurring model's `recurrence_rule` attribute, which keeps the occurrence index
 * and exclusions consistent; this model is public for relationships and inspection only.
 *
 * @property int $id
 * @property string $recurrable_type
 * @property int|string $recurrable_id
 * @property string $rule RFC 5545 text from RRule::rfcString(), including DTSTART;TZID=
 * @property string $timezone
 * @property bool $is_infinite
 * @property CarbonImmutable|null $materialized_until exclusive upper bound of indexed starts; null = finite rule fully indexed
 */
class Recurrence extends Model
{
    protected $table = 'groundhog_recurrences';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_infinite' => 'boolean',
            'materialized_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recurrable(): MorphTo
    {
        return $this->morphTo();
    }
}
