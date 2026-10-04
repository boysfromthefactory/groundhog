<?php

namespace BoysFromTheFactory\Groundhog\Concerns;

use BoysFromTheFactory\Groundhog\Casts\AsRecurrenceRule;
use BoysFromTheFactory\Groundhog\Collections\OccurrenceCollection;
use BoysFromTheFactory\Groundhog\Exceptions\IncompatibleEloquentBuilder;
use BoysFromTheFactory\Groundhog\Exceptions\InvalidRecurrenceRule;
use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use BoysFromTheFactory\Groundhog\Index\ExceptionLedger;
use BoysFromTheFactory\Groundhog\Index\OccurrenceIndex;
use BoysFromTheFactory\Groundhog\Models\Recurrence;
use BoysFromTheFactory\Groundhog\Query\OccurrenceScope;
use BoysFromTheFactory\Groundhog\Query\RecurringBuilder;
use BoysFromTheFactory\Groundhog\Rules\RuleFactory;
use BoysFromTheFactory\Groundhog\Support\RecurrenceColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use RRule\RRule;

/**
 * Makes an Eloquent model recurring.
 *
 * Declare the start column with `RECURRENCE_STARTS_AT` (default `starts_at`) and the end
 * column with `RECURRENCE_ENDS_AT` (default `ends_at`; `null` when the model has no end).
 * Assigning `recurrence_rule` (RRULE string, php-rrule parts array, `RRule`, or `null` to
 * remove) turns a record into a series; ordinary queries then return one hydrated, non-stored
 * instance per occurrence alongside plain records and occurrence exceptions.
 *
 * Saving a virtual occurrence persists it as an exception of its series; deleting one cancels
 * it. Key lookups, route binding and query-level bulk writes act on stored rows only.
 *
 * @see contracts/public-api.md for the complete contract.
 */
trait HasRecurrence
{
    /**
     * Raw rule input assigned since the last save; `null` means a pending removal.
     *
     * @var string|array<string, mixed>|RRule|null
     */
    protected string|array|RRule|null $pendingRecurrenceInput = null;

    /** Parsed form of the pending input, validated at assignment time. */
    protected ?RRule $pendingRecurrenceRule = null;

    protected bool $hasPendingRecurrence = false;

    public static function bootHasRecurrence(): void
    {
        static::addGlobalScope(new OccurrenceScope);
    }

    public function initializeHasRecurrence(): void
    {
        $this->mergeCasts(['recurrence_rule' => AsRecurrenceRule::class]);
    }

    /**
     * Column holding each occurrence's start (`RECURRENCE_STARTS_AT`, default `starts_at`).
     */
    public function getRecurrenceStartColumn(): string
    {
        return RecurrenceColumns::of($this)->start;
    }

    /**
     * Column holding each occurrence's end (`RECURRENCE_ENDS_AT`, default `ends_at`), or
     * `null` when the model has no end column.
     */
    public function getRecurrenceEndColumn(): ?string
    {
        return RecurrenceColumns::of($this)->end;
    }

    public function getQualifiedRecurrenceStartColumn(): string
    {
        return $this->qualifyColumn($this->getRecurrenceStartColumn());
    }

    public function getQualifiedRecurrenceEndColumn(): ?string
    {
        $column = $this->getRecurrenceEndColumn();

        return $column === null ? null : $this->qualifyColumn($column);
    }

    /**
     * The stored rule row of a series.
     *
     * @return MorphOne<Recurrence, $this>
     */
    public function recurrence(): MorphOne
    {
        return $this->morphOne(Recurrence::class, 'recurrable');
    }

    /**
     * The series a virtual occurrence or occurrence exception belongs to; empty otherwise.
     *
     * @return BelongsTo<static, $this>
     */
    public function series(): BelongsTo
    {
        // The relation reads the foreign key when it is built, so resolve it first.
        $this->resolveOccurrenceIdentity();

        $relation = $this->belongsTo(static::class, OccurrenceScope::SERIES_KEY, $this->getKeyName(), 'series');

        $relation->getQuery()->withoutOccurrences()->withoutExceptionLinkLookup();

        return $relation;
    }

    /**
     * Stored record with a rule attached.
     */
    public function isRecurringSeries(): bool
    {
        return $this->exists && $this->getKey() !== null && $this->storedRecurrence() !== null;
    }

    /**
     * Instance hydrated from an occurrence of a series; it does not exist in storage. A key
     * assigned before saving (e.g. a chosen id) does not change that.
     */
    public function isVirtualOccurrence(): bool
    {
        return ! $this->exists && $this->getSeriesKey() !== null;
    }

    /**
     * Stored record that replaces one occurrence of a series.
     */
    public function isOccurrenceException(): bool
    {
        $this->resolveOccurrenceIdentity();

        return $this->exists && $this->getSeriesKey() !== null;
    }

    /**
     * The generated start this instance stands for (virtual occurrence or exception).
     */
    public function originalOccurrenceStart(): ?CarbonImmutable
    {
        $this->resolveOccurrenceIdentity();

        return RecurrenceColumns::parse($this->attributes[OccurrenceScope::ORIGINAL_START] ?? null);
    }

    /**
     * Pending rule, else the stored rule of the series (or of a virtual occurrence's series).
     *
     * @internal Read side of the `recurrence_rule` cast.
     */
    public function currentRecurrenceRule(): ?RRule
    {
        if ($this->hasPendingRecurrence) {
            return $this->pendingRecurrenceRule;
        }

        $recurrence = $this->isVirtualOccurrence()
            ? $this->seriesRecord()?->storedRecurrence()
            : $this->storedRecurrence();

        return $recurrence === null ? null : RuleFactory::fromStored($recurrence->rule);
    }

    /**
     * Validates rule input and records it until the next save; `null` records a removal.
     *
     * @internal Write side of the `recurrence_rule` cast.
     *
     * @param  string|array<string, mixed>|RRule|null  $input
     *
     * @throws InvalidRecurrenceRule
     */
    public function assignRecurrenceRule(string|array|RRule|null $input): void
    {
        if ($input !== null && ($this->isVirtualOccurrence() || $this->isOccurrenceException())) {
            throw RecurrenceNotSupported::forOccurrenceException($this);
        }

        $columns = RecurrenceColumns::of($this);

        $this->pendingRecurrenceRule = $input === null
            ? null
            // The start may not be filled yet; DTSTART is rebuilt from the saved start on save().
            : RuleFactory::make($input, $columns->startOf($this) ?? CarbonImmutable::now(), RecurrenceColumns::applicationTimezone());
        $this->pendingRecurrenceInput = $input;
        $this->hasPendingRecurrence = true;
    }

    /**
     * Saves the record and, in the same transaction, its pending rule and occurrence index.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws RecurrenceNotSupported when a rule is pending but the start is empty
     * @throws OccurrenceLimitExceeded when the rule generates too many occurrences
     */
    public function save(array $options = [])
    {
        $saved = $this->getConnection()->transaction(fn () => $this->saveSeries($options));

        if ($saved) {
            $this->clearPendingRecurrence();
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function saveSeries(array $options): bool
    {
        $columns = RecurrenceColumns::of($this);
        $pendingInput = $this->pendingRecurrenceInput;
        $assignsRule = $this->hasPendingRecurrence && $pendingInput !== null;

        if ($assignsRule && ($this->isVirtualOccurrence() || $this->isOccurrenceException())) {
            throw RecurrenceNotSupported::forOccurrenceException($this);
        }

        if ($this->isVirtualOccurrence()) {
            return $this->saveAsException($options);
        }

        if ($assignsRule && $columns->startOf($this) === null) {
            throw RecurrenceNotSupported::missingStart($this);
        }

        $startChanged = $this->exists && $this->isDirty($columns->start);
        $durationChanged = $this->exists && $columns->end !== null && $this->isDirty($columns->end);

        if (! parent::save($options)) {
            return false;
        }

        if ($assignsRule) {
            $this->persistRecurrence($pendingInput, $this->storedRecurrence(), $durationChanged);
        } elseif ($this->hasPendingRecurrence) {
            // Deleting the rule row cascades its index and exclusions, so its exceptions become
            // plain records (FR-022).
            $this->storedRecurrence()?->delete();
            $this->setRelation('recurrence', null);
        } elseif (($startChanged || $durationChanged) && ($stored = $this->storedRecurrence()) !== null) {
            // A new start or duration moves every occurrence; the rule text carries DTSTART.
            $this->persistRecurrence(RuleFactory::fromStored($stored->rule), $stored, $durationChanged);
        }

        return true;
    }

    /**
     * Inserts a virtual occurrence as a new record and excludes its original start from the
     * series, in the caller's transaction (FR-017, FR-025). Saving persists even without
     * changes, as saving any non-stored model does.
     *
     * @param  array<string, mixed>  $options
     */
    private function saveAsException(array $options): bool
    {
        $seriesKey = $this->getSeriesKey();
        $originalStart = $this->originalOccurrenceStart();

        if (! (is_int($seriesKey) || is_string($seriesKey)) || $originalStart === null) {
            return false;
        }

        if (! parent::save($options)) {
            return false;
        }

        $key = $this->getKey();

        if (is_int($key) || is_string($key)) {
            ExceptionLedger::recordException($this, $seriesKey, $originalStart, $key);
        }

        return true;
    }

    /**
     * On a virtual occurrence: fill and save as an exception (Eloquent would return false for
     * a non-stored model).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = [])
    {
        if ($this->isVirtualOccurrence()) {
            return $this->fill($attributes)->save($options);
        }

        return parent::update($attributes, $options);
    }

    /**
     * On a virtual occurrence: change the attribute and save as an exception. Eloquent would
     * otherwise run an unconstrained, table-wide update for a non-stored model.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @param  string  $method
     * @return int
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! $this->isVirtualOccurrence()) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }

        $current = $this->getAttribute($column);
        $this->setAttribute($column, (is_numeric($current) ? $current : 0) + ($method === 'increment' ? $amount : -$amount));
        $this->forceFill($extra);

        return $this->save() ? 1 : 0;
    }

    /**
     * A stored record loaded without the occurrence scope (e.g. cursor()) lacks the identity
     * attributes; look them up once and keep them as non-dirty attributes.
     */
    protected function resolveOccurrenceIdentity(): void
    {
        if (! $this->exists || $this->getKey() === null || array_key_exists(OccurrenceScope::SERIES_KEY, $this->attributes)) {
            return;
        }

        $key = $this->getKey();
        $link = is_int($key) || is_string($key) ? (ExceptionLedger::exceptionLinksFor([$this])[$key] ?? null) : null;

        $this->attributes[OccurrenceScope::SERIES_KEY] = $link['series_key'] ?? null;
        $this->attributes[OccurrenceScope::ORIGINAL_START] = $link['original_starts_at'] ?? null;
        $this->syncOriginalAttributes([OccurrenceScope::SERIES_KEY, OccurrenceScope::ORIGINAL_START]);
    }

    /**
     * Stores the rule anchored at the saved start. A first rule builds the index. A changed rule
     * text (new rule or new start) resets the series' exclusions and rebuilds the index
     * (FR-022). An identical rule leaves both alone unless the duration changed, which only
     * rebuilds the index.
     *
     * @param  string|array<string, mixed>|RRule  $input
     */
    private function persistRecurrence(string|array|RRule $input, ?Recurrence $existing, bool $durationChanged): void
    {
        $start = RecurrenceColumns::of($this)->startOf($this) ?? throw RecurrenceNotSupported::missingStart($this);
        $rule = RuleFactory::make($input, $start, RecurrenceColumns::applicationTimezone());
        $text = $rule->rfcString();

        if ($existing !== null && $existing->rule === $text) {
            if ($durationChanged) {
                OccurrenceIndex::rebuild($this, $existing, $rule);
            }

            return;
        }

        if ($existing !== null) {
            ExceptionLedger::resetExclusions($existing);
        }

        $recurrence = $existing ?? $this->recurrence()->make();
        $recurrence->rule = $text;
        $recurrence->timezone = RuleFactory::timezoneOf($rule);
        $recurrence->is_infinite = $rule->isInfinite();
        $recurrence->save();
        $this->setRelation('recurrence', $recurrence);

        $existing === null
            ? OccurrenceIndex::materialize($this, $recurrence, $rule)
            : OccurrenceIndex::rebuild($this, $recurrence, $rule);
    }

    /**
     * Deletes in one transaction.
     *
     * - Virtual occurrence: fires `deleting`/`deleted` and cancels the occurrence; nothing is
     *   stored (FR-019).
     * - Exception: deletes the record; a hard delete turns its link into a cancellation so the
     *   occurrence stays cancelled (FR-020). A soft delete hides it until restore.
     * - Series: a soft delete hides it with its occurrences and exceptions; a hard delete also
     *   force-deletes its exceptions, trashed ones included, and removes the rule, whose rows
     *   cascade to the index and exclusions (FR-023).
     *
     * @return bool|null
     */
    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            if ($this->isVirtualOccurrence()) {
                return $this->cancelOccurrence();
            }

            $isException = $this->isOccurrenceException();
            $recurrence = $isException ? null : $this->storedRecurrence();

            $deleted = parent::delete();

            // Soft deletes keep `exists`; a hard delete (forced, or a model without SoftDeletes)
            // clears it.
            if (! $deleted || $this->exists) {
                return $deleted;
            }

            if ($isException) {
                ExceptionLedger::releaseException($this);
            } elseif ($recurrence !== null) {
                $this->newQueryWithoutScopes()
                    ->whereKey(ExceptionLedger::exceptionKeysOf($recurrence))
                    ->get()
                    ->each(fn (Model $exception) => $exception->forceDelete());
                $recurrence->delete();
                $this->setRelation('recurrence', null);
            }

            return $deleted;
        });
    }

    private function cancelOccurrence(): bool
    {
        $seriesKey = $this->getSeriesKey();
        $originalStart = $this->originalOccurrenceStart();

        if (! (is_int($seriesKey) || is_string($seriesKey)) || $originalStart === null) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        ExceptionLedger::cancel($this, $seriesKey, $originalStart);

        $this->fireModelEvent('deleted', false);

        return true;
    }

    protected function clearPendingRecurrence(): void
    {
        $this->pendingRecurrenceInput = null;
        $this->pendingRecurrenceRule = null;
        $this->hasPendingRecurrence = false;
    }

    /**
     * Collection whose set operations keep virtual occurrences apart (they have no key).
     *
     * @param  array<array-key, static>  $models
     * @return OccurrenceCollection<array-key, static>
     */
    public function newCollection(array $models = [])
    {
        return new OccurrenceCollection($models);
    }

    /**
     * Rows with a null key and a series key are virtual occurrences, not stored records.
     *
     * @param  array<string, mixed>  $attributes
     * @param  \UnitEnum|string|null  $connection
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = parent::newFromBuilder($attributes, $connection);

        if ($model->getKey() === null && $model->getSeriesKey() !== null) {
            $model->exists = false;
        }

        return $model;
    }

    /**
     * The identity attributes are query-time values, never columns.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $attributes = array_diff_key(parent::getAttributesForInsert(), array_flip([OccurrenceScope::SERIES_KEY, OccurrenceScope::ORIGINAL_START]));

        if (($attributes[$this->getKeyName()] ?? null) === null) {
            unset($attributes[$this->getKeyName()]);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        return array_diff_key(parent::getDirtyForUpdate(), array_flip([OccurrenceScope::SERIES_KEY, OccurrenceScope::ORIGINAL_START]));
    }

    /**
     * Returns RecurringBuilder, or a declared custom builder that extends it.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return RecurringBuilder<static>
     *
     * @throws IncompatibleEloquentBuilder when a declared builder does not extend RecurringBuilder
     */
    public function newEloquentBuilder($query)
    {
        $declared = $this->resolveCustomBuilderClass();
        $builderClass = $declared ?: static::$builder;

        // An undeclared builder means Eloquent's default, which recurring models replace.
        if ($declared === false && $builderClass === Builder::class) {
            $builderClass = RecurringBuilder::class;
        }

        if ($builderClass !== RecurringBuilder::class && ! is_subclass_of($builderClass, RecurringBuilder::class)) {
            throw IncompatibleEloquentBuilder::forModel(static::class, $builderClass);
        }

        /** @var RecurringBuilder<static> */
        return new $builderClass($query);
    }

    /**
     * Route model binding resolves stored records, never occurrences.
     *
     * @param  Model|\Illuminate\Contracts\Database\Eloquent\Builder|Relation<static, Model, mixed>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        // resolveRouteBinding() passes the model itself; its query would expand occurrences.
        if ($query instanceof Model) {
            $query = $query->newQuery();
        }

        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        if ($builder instanceof RecurringBuilder) {
            $builder->withoutOccurrences();
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    protected function getSeriesKey(): mixed
    {
        return $this->attributes[OccurrenceScope::SERIES_KEY] ?? null;
    }

    private function storedRecurrence(): ?Recurrence
    {
        $recurrence = $this->exists ? $this->getRelationValue('recurrence') : null;

        return $recurrence instanceof Recurrence ? $recurrence : null;
    }

    /**
     * @return static|null
     */
    private function seriesRecord(): ?Model
    {
        $series = $this->getRelationValue('series');

        return $series instanceof static ? $series : null;
    }
}
