# Groundhog

Declarative recurring Eloquent models backed by RFC 5545 rules.

Add one trait to a model, give a record a recurrence rule, and keep querying it like any other
model: every read returns one hydrated, non-stored instance per occurrence, next to your plain
records. Pagination, counts, aggregates, ordering, `whereHas`, joins and eager loading all work
over the occurrences. Saving an occurrence stores it as an exception of its series; deleting one
cancels it.

Rules are parsed and expanded by [rlanvin/php-rrule](https://github.com/rlanvin/php-rrule).

## Requirements

- PHP 8.4+, Laravel 13
- SQLite 3.35+, MySQL 8.4 or PostgreSQL 14+ (SQL Server is not supported)
- `ext-intl` is recommended for localised `humanReadable()` output

## Installation

```bash
composer require boysfromthefactory/groundhog
php artisan vendor:publish --tag="groundhog-migrations"
php artisan migrate
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag="groundhog-config"
```

```php
return [
    // ISO-8601 duration: how far an infinite rule is expanded after the query's lower start
    // bound (or after now) when the query gives no upper bound.
    'horizon' => 'P1Y',

    // Most occurrences one series may generate in one pass; more fails with OccurrenceLimitExceeded.
    'max_occurrences_per_series' => 50000,

    // ISO-8601 duration from now: how far ahead a read may extend the stored occurrence index.
    'max_materialization_ahead' => 'P10Y',
];
```

The migration creates three tables: `groundhog_recurrences` (one rule per series),
`groundhog_occurrences` (a derived index of each rule's occurrences) and `groundhog_exclusions`
(exceptions and cancellations). Your own tables need no new columns.

## Declaring a recurring model

```php
use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasRecurrence;

    // Only needed when your columns are named differently (defaults shown).
    public const RECURRENCE_STARTS_AT = 'starts_at';
    public const RECURRENCE_ENDS_AT = 'ends_at'; // null when the model has no end column

    protected $fillable = ['title', 'starts_at', 'ends_at', 'recurrence_rule'];
}
```

List `recurrence_rule` in `$fillable` if the model uses `$fillable`; `$guarded` models accept it
as is.

## Assigning rules

A rule is attached through the `recurrence_rule` attribute and saved with the record:

```php
Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-02 09:00',
    'ends_at' => '2026-03-02 10:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);
```

The rule may be an RRULE string, a string with a `DTSTART;TZID=…` line, a php-rrule parts array
(`['FREQ' => 'WEEKLY', 'BYDAY' => 'MO']`) or an `RRule\RRule`. All RFC 5545 RRULE components are
supported. The first occurrence and the duration of every occurrence always come from the
record's own start and end; a `DTSTART` in the input only contributes its time zone. Occurrences
keep their local wall-clock time across daylight-saving changes in that zone.

Invalid input throws `InvalidRecurrenceRule` naming the offending part as soon as it is
assigned. `EXDATE`, `RDATE` and `EXRULE` are rejected: exclusions come from cancelling or
editing occurrences. Assign `null` to turn a series back into a plain record.

## The rule cast

`$meeting->recurrence_rule` returns an `RRule\RRule`, so every php-rrule method works:

```php
$meeting->recurrence_rule->humanReadable(); // "weekly on Monday, starting from 3/2/26, forever"
$meeting->recurrence_rule->getOccurrencesBetween('2026-03-01', '2026-03-31');
```

It reflects the rule only; cancellations and exceptions are not applied to it.

## Querying

Write queries as for any model:

```php
Meeting::whereBetween('starts_at', [$from, $to])
    ->where('location', 'Room A')
    ->orderBy('starts_at')
    ->paginate(25);
```

Each occurrence carries the series' attributes with its own start and end, reports
`exists === false`, has no primary key, and exposes:

```php
$occurrence->isVirtualOccurrence();     // true
$occurrence->series;                    // the stored series record
$occurrence->originalOccurrenceStart(); // the start the rule generated
```

An occurrence is addressed by `groundhog_series_key` plus `groundhog_original_starts_at`; both
are present on every row (`null` for plain records) and serialised by `toArray()`.

A rule without `COUNT` or `UNTIL` never ends, so a query without an upper bound on the start or
end column expands it up to the configured `horizon` after its lower bound, or after now.

## Editing, cancelling and deleting

```php
$occurrence->update(['location' => 'Room B']); // stored as an exception of this occurrence only
$occurrence->delete();                          // cancels this occurrence; nothing is stored
```

`save()`, `update()`, `increment()` and `decrement()` on an occurrence persist it as an
exception, even without changes, and fire the usual `creating`/`created`/`saved` events.
Hard-deleting an exception keeps its occurrence cancelled; soft-deleting it hides it until
`restore()`.

Editing the series record changes every occurrence that has no exception. Changing its rule or
its start detaches its exceptions as plain records and discards its cancellations; changing only
its end, or assigning the identical rule, keeps both. Deleting a series removes its rule,
occurrences and exceptions; soft-deleting it hides them until `restore()`.

## Caveats

- Lookups by primary key (`find`, `whereKey`, `destroy`, route model binding, belongs-to
  relations pointing at the model) and query-level writes (`update`, `delete`, `increment`,
  `insert`, `upsert`, …) act on stored rows only and never create exceptions or cancellations.
  `withoutOccurrences()` gives any query that view.
- Relations keyed on the model's own primary key (e.g. `hasMany` attendees) are empty on
  occurrences, which have no key; reach them through `$occurrence->series`.
- A custom Eloquent builder must extend `BoysFromTheFactory\Groundhog\Query\RecurringBuilder`.
- `chunkById()`, `lazyById()` and `eachById()` throw while occurrences are expanded; use
  `chunk()`/`lazy()`, or `withoutOccurrences()` for stored rows.
- Stored rows streamed by `withoutOccurrences()->cursor()` get the identity attributes only once
  `isOccurrenceException()`, `originalOccurrenceStart()` or `series` is used; use `get()` or
  `lazy()` when serialising them.
- A series whose rule generates more than `max_occurrences_per_series` occurrences between its
  start and the horizon is rejected on save, and a read that would need occurrences beyond
  `max_materialization_ahead` throws `OccurrenceLimitExceeded`.
- `cursorPaginate()` is not supported, and occurrences cannot be queued by identity.
- Bulk deletes of series rows bypass the cleanup of their rules, as Eloquent bulk deletes bypass
  model events.

## Testing

```bash
composer test     # SQLite in memory
DB_CONNECTION=mysql DB_DATABASE=groundhog composer test
DB_CONNECTION=pgsql DB_DATABASE=groundhog composer test
composer analyse  # Larastan, level 9
composer bench    # SC-003 pagination timing on DB_CONNECTION (recreates its schema)
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [License File](LICENSE.md).
