<?php

namespace BoysFromTheFactory\Groundhog\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A recurring model's declared start/end columns and the values read from them.
 *
 * Read from the class constants rather than through trait methods so that the scope, the
 * occurrence rows and the trait share one implementation that is statically typed against any Model.
 *
 * @internal
 */
final readonly class RecurrenceColumns
{
    public function __construct(
        public string $start,
        public ?string $end,
    ) {}

    /**
     * `RECURRENCE_STARTS_AT` (default `starts_at`) and `RECURRENCE_ENDS_AT` (default `ends_at`,
     * `null` when the model has no end column).
     */
    public static function of(Model $model): self
    {
        $start = defined($model::class.'::RECURRENCE_STARTS_AT') ? constant($model::class.'::RECURRENCE_STARTS_AT') : 'starts_at';
        $end = defined($model::class.'::RECURRENCE_ENDS_AT') ? constant($model::class.'::RECURRENCE_ENDS_AT') : 'ends_at';

        return new self(is_string($start) ? $start : 'starts_at', is_string($end) ? $end : null);
    }

    /**
     * Time zone used for stored date-times and for rules whose input names none.
     */
    public static function applicationTimezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    public function startOf(Model $model): ?CarbonImmutable
    {
        return self::parse($model->getAttributes()[$this->start] ?? null);
    }

    /**
     * Series end minus series start in seconds; null without an end column or end value.
     */
    public function durationOf(Model $model): ?int
    {
        $start = $this->startOf($model);
        $end = $this->end === null ? null : self::parse($model->getAttributes()[$this->end] ?? null);

        return $start === null || $end === null ? null : $end->getTimestamp() - $start->getTimestamp();
    }

    /**
     * Raw attribute values are strings in the model's date format, interpreted in the
     * application time zone exactly as Eloquent's datetime cast does.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, self::applicationTimezone());
    }
}
