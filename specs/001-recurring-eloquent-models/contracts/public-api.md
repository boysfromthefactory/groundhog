# Contract: Public PHP API

Namespace root: `BoysFromTheFactory\Groundhog`. Everything not listed here is internal
(`@internal`) and may change without a major release.

## Opt-in: `Concerns\HasRecurrence` (trait)

```php
use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;

class Meeting extends Model
{
    use HasRecurrence;

    // Optional; defaults shown. Set RECURRENCE_ENDS_AT = null when the model has no end column.
    public const RECURRENCE_STARTS_AT = 'starts_at';
    public const RECURRENCE_ENDS_AT = 'ends_at';
}
```

| Member | Signature | Contract |
|---|---|---|
| cast attribute | `recurrence_rule: ?RRule\RRule` | **Read**: pending rule, else stored series rule, else (virtual occurrence) the series' rule, else `null`. Returned object has DTSTART = series start in the rule's zone, so `humanReadable()`, `getOccurrences()`, `getOccurrencesBetween()`, `occursAt()`, `isFinite()`, … behave as php-rrule documents. It reflects the **rule only**: exclusions/exceptions are not applied to it. **Write**: `string` (RRULE, optionally with a `DTSTART[;TZID=…]` line), `array` (php-rrule parts), `RRule`, or `null` (remove). Validated immediately → `InvalidRecurrenceRule`. Persisted on `save()`. Input DTSTART contributes only its time zone; the date-time always comes from the start column. |
| `recurrence()` | `MorphOne<Models\Recurrence>` | stored rule row of a series |
| `series()` | `BelongsTo<static>` | series of a virtual occurrence or exception; `null` otherwise; eager-loadable |
| `isRecurringSeries()` | `bool` | stored record with a rule |
| `isVirtualOccurrence()` | `bool` | hydrated from expansion, not stored |
| `isOccurrenceException()` | `bool` | stored record replacing an occurrence |
| `originalOccurrenceStart()` | `?CarbonImmutable` | generated start this instance stands for (virtual or exception); `null` otherwise |
| `save(array $options = [])` | `bool` | Transactional. Virtual → inserts exception + exclusion (also when unchanged). Pending rule → persists rule, rebuilds index; rule/start change or rule removal detaches existing exceptions (FR-022); duration change rebuilds index only. |
| `update(array $attributes = [], array $options = [])` | `bool` | On a virtual occurrence: `fill()` + `save()` (Eloquent's default returns `false`). |
| `increment()/decrement()` | as Eloquent | On a virtual occurrence: changes the attribute and saves as exception; never a table-wide update. |
| `delete()` | `?bool` | Transactional. Virtual → `deleting`/`deleted` events, cancellation; nothing inserted. Exception → cancellation (hard) or hidden (soft). Series → removes rule, index, exclusions and deletes its exceptions through `delete()` (events fire). |
| `newCollection()` | `Collections\OccurrenceCollection` | Eloquent collection whose dictionary keys virtual occurrences by series key + original start |

Lifecycle events: `saving/creating/created/saved` fire when a virtual occurrence becomes an exception;
`saving/updating/updated/saved` on exception edits; `deleting/deleted` on any delete (FR-024).

Identity attributes on expanded rows: `groundhog_series_key`, `groundhog_original_starts_at`
(see [data-model.md](../data-model.md)). They are readable, serialised by `toArray()`, filterable
in queries, and never written.

## Builder: `Query\RecurringBuilder` (extends `Illuminate\Database\Eloquent\Builder`)

Returned automatically by the trait. If a model declares `#[UseEloquentBuilder(X::class)]`, `X`
MUST extend `RecurringBuilder`, otherwise `newQuery()` throws `IncompatibleEloquentBuilder`.

| Method | Contract |
|---|---|
| `withoutOccurrences()` | Removes expansion; query sees stored rows (plain, series, exceptions). Optional, never required. |
| `whereKey()` / `find*()` / `destroy()` | Act on stored rows (FR-015). |
| `update`, `delete`, `forceDelete`, `increment*`, `decrement*`, `touch`, `insert*`, `upsert` | Act on stored rows with the caller's constraints; never create exceptions or cancellations (FR-026). |
| everything else | Unchanged Eloquent API over the expanded row set (see [query-semantics.md](query-semantics.md)). |

## `Models\Recurrence`

Eloquent model for `groundhog_recurrences`. Public for relationships/inspection; `rule` is the
stored RFC text (`string`). Applications SHOULD change rules through the `recurrence_rule`
cast attribute, not this model, because that path keeps the index and exceptions consistent.

## Exceptions (`Exceptions\*`)

| Class | Extends | Thrown when |
|---|---|---|
| `InvalidRecurrenceRule` | `InvalidArgumentException` | rule input unparseable, unsupported part, EXDATE/RDATE present; message names the part |
| `RecurrenceNotSupported` | `LogicException` | rule assigned to an exception; series saved without a start value |
| `OccurrenceLimitExceeded` | `RuntimeException` | one generation pass for one series would exceed `max_occurrences_per_series`; message names model, key, window |
| `IncompatibleEloquentBuilder` | `LogicException` | model's custom builder does not extend `RecurringBuilder` |

## Configuration: `config/groundhog.php` (publishable, tag `groundhog-config`)

| Key | Type | Default | Meaning |
|---|---|---|---|
| `horizon` | ISO-8601 duration string | `'P1Y'` | FR-008 cap length after the query's lower bound (or now) |
| `max_occurrences_per_series` | int | `50000` | SC-005 guard per generation pass |

## Migration

Published via `php artisan vendor:publish --tag=groundhog-migrations`; creates the three tables in
[data-model.md](../data-model.md).
