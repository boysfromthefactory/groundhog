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
| `save(array $options = [])` | `bool` | Transactional. Virtual → inserts exception + exclusion (also when unchanged). Pending rule → persists rule, rebuilds index; a changed rule text, a start change or rule removal resets the series' exclusions (exceptions detached, cancellations discarded; FR-022); re-assigning an identical rule resets nothing; duration change rebuilds index only. |
| `update(array $attributes = [], array $options = [])` | `bool` | On a virtual occurrence: `fill()` + `save()` (Eloquent's default returns `false`). |
| `increment()/decrement()` | as Eloquent | On a virtual occurrence: changes the attribute and saves as exception; never a table-wide update. |
| `delete()` | `?bool` | Transactional. Virtual → `deleting`/`deleted` events, cancellation; nothing inserted. Exception → cancellation (hard) or hidden (soft). Series, hard delete (`forceDelete()`, or a model without `SoftDeletes`) → removes rule, index, exclusions and force-deletes its exceptions, including trashed ones (events fire). Series, soft delete → hides its occurrences and exceptions until `restore()`. |
| `newCollection()` | `Collections\OccurrenceCollection` | Eloquent collection whose dictionary keys virtual occurrences by series key + original start |

Lifecycle events: `saving/creating/created/saved` fire when a virtual occurrence becomes an exception;
`saving/updating/updated/saved` on exception edits; `deleting/deleted` on any delete (FR-024).

Identity attributes: `groundhog_series_key`, `groundhog_original_starts_at` (see
[data-model.md](../data-model.md)). Present on expanded rows and on stored rows returned by
`get`/`first`/`find*`/route binding/`refresh()` without expansion (`null` for plain and series
rows). Stored rows streamed by `withoutOccurrences()->cursor()` bypass the per-result-set lookup
and gain the attributes on the first call to `isOccurrenceException()`,
`originalOccurrenceStart()` or `series`. They are readable, serialised by `toArray()`, filterable
in queries, and never written.

## Builder: `Query\RecurringBuilder` (extends `Illuminate\Database\Eloquent\Builder`)

Returned automatically by the trait. If a model declares `#[UseEloquentBuilder(X::class)]`, `X`
MUST extend `RecurringBuilder`, otherwise `newQuery()` throws `IncompatibleEloquentBuilder`.

| Method | Contract |
|---|---|
| `withoutOccurrences()` | Removes expansion; query sees stored rows (plain, series, exceptions). Optional, never required. |
| `whereKey()` / `find*()` / `destroy()`, and any query whose top-level AND-ed clauses equate the primary key with a value, list or column (belongs-to relations and `whereHas` pointing at the model) | Act on stored rows (FR-015). |
| `whereKeyNot()` | Acts on stored rows. |
| `update`, `delete`, `forceDelete`, `increment*`, `decrement*`, `touch`, `insert*`, `upsert` | Act on stored rows with the caller's constraints; never create exceptions or cancellations (FR-026). |
| `chunkById`, `chunkByIdDesc`, `eachById`, `lazyById`, `lazyByIdDesc` | Throw `RecurrenceNotSupported` while occurrences are expanded (virtual rows have no key); work after `withoutOccurrences()`. |
| everything else | Unchanged Eloquent API over the expanded row set (see [query-semantics.md](query-semantics.md)). |

## `Models\Recurrence`

Eloquent model for `groundhog_recurrences`. Public for relationships/inspection; `rule` is the
stored RFC text (`string`). Applications SHOULD change rules through the `recurrence_rule`
cast attribute, not this model, because that path keeps the index and exceptions consistent.

## Exceptions (`Exceptions\*`)

| Class | Extends | Thrown when |
|---|---|---|
| `InvalidRecurrenceRule` | `InvalidArgumentException` | rule input unparseable, unsupported part, EXDATE/RDATE present; message names the part |
| `RecurrenceNotSupported` | `LogicException` | rule assigned to an exception; series saved without a start value; key-based iteration while occurrences are expanded |
| `OccurrenceLimitExceeded` | `RuntimeException` | one generation pass for one series would exceed `max_occurrences_per_series`; or a read needs index rows beyond `now + max_materialization_ahead`; message names model, key/type and window |
| `IncompatibleEloquentBuilder` | `LogicException` | model's custom builder does not extend `RecurringBuilder` |

## Configuration: `config/groundhog.php` (publishable, tag `groundhog-config`)

| Key | Type | Default | Meaning |
|---|---|---|---|
| `horizon` | ISO-8601 duration string | `'P1Y'` | FR-008 cap length after the query's lower bound (or now) |
| `max_occurrences_per_series` | int | `50000` | SC-005 guard per generation pass |
| `max_materialization_ahead` | ISO-8601 duration string | `'P10Y'` | FR-008 ceiling, from now, on how far a read may extend the index |

## Migration

Published via `php artisan vendor:publish --tag=groundhog-migrations`; creates the three tables in
[data-model.md](../data-model.md).
