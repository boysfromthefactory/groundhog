This page lists what Groundhog does not do, or does differently from a plain Eloquent model, and the workaround for each. Read it before you choose Groundhog for a feature, or when a query on a recurring model behaves unexpectedly. The items match the "Caveats" section of the [README](https://github.com/boysfromthefactory/groundhog/blob/master/README.md#caveats).

## Key lookups and bulk writes act on stored rows

Lookups by primary key (`find`, `whereKey`, `destroy`, route model binding, belongs-to relations pointing at the model) and query-level writes (`update`, `delete`, `increment`, `insert`, `upsert`, ...) act on stored rows only. They never create exceptions or cancellations. `Meeting::find($series->id)` returns the stored series, not an occurrence.

**Workaround:** to change or cancel one occurrence, query it and call `save()`, `update()` or `delete()` on the model. Use `withoutOccurrences()` when you want the stored-row view for any query. See [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes) and [Identifying Occurrences](Identifying-Occurrences).

## Key-based relations are empty on occurrences

A virtual occurrence has no primary key of its own. Relations keyed on the model's own key, such as `hasMany` attendees, are therefore empty on occurrences. Relations that use a foreign key stored on the model, such as `belongsTo` room, work because occurrences carry the series' values.

```php
$meetings = Meeting::with(['room', 'attendees', 'series'])
    ->whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])
    ->get();

$meetings->first()->room;      // => the series' Room
$meetings->first()->attendees; // => empty collection
$meetings->first()->series;    // => the stored Standup series
```

**Workaround:** reach key-based relations through the series: `$occurrence->series->attendees`. See [Relationships](Querying-Occurrences#relationships).

## Custom builders must extend RecurringBuilder

A recurring model's custom Eloquent builder must extend `BoysFromTheFactory\Groundhog\Query\RecurringBuilder`; a builder extending Laravel's plain `Builder` throws [IncompatibleEloquentBuilder](Errors#incompatibleeloquentbuilder) on the first query.

**Workaround:** change the parent class to `RecurringBuilder`. See [Custom Eloquent builders](Declaring-Recurring-Models#custom-eloquent-builders).

## chunkById, lazyById and eachById

`chunkById()`, `lazyById()` and `eachById()` page by "key greater than the last key". Virtual occurrences have no key and would be skipped silently, so these methods throw [RecurrenceNotSupported](Errors#recurrencenotsupported) while occurrences are expanded.

**Workaround:** use `chunk()` or `lazy()` to iterate occurrences. Use `Meeting::withoutOccurrences()->chunkById(...)` to iterate stored rows only. See [Chunking and lazy iteration](Pagination-and-Collections#chunking-and-lazy-iteration).

## cursorPaginate is not supported

`cursorPaginate()` builds its cursor from column values that must identify a row uniquely. Virtual occurrences have no key, so Groundhog does not support it on expanded queries.

**Workaround:** use `paginate()` or `simplePaginate()`. Both work on occurrences and give a stable order when the query has at least one `orderBy`. See [Pagination](Pagination-and-Collections#pagination).

## SQL Server is not supported

Groundhog supports SQLite 3.35+, MySQL 8.4 and PostgreSQL 14+. SQL Server is not supported.

**Workaround:** none within Groundhog. Use one of the supported databases for the tables of recurring models.

## Occurrences cannot be queued by identity

A virtual occurrence has no key, so Laravel's model serialisation (`SerializesModels`) cannot restore it in a queued job, listener or notification.

**Workaround:** pass the series key and the original start to the job, and find the occurrence again when the job runs:

```php
SendReminder::dispatch($occurrence->groundhog_series_key, $occurrence->groundhog_original_starts_at);

// In the job:
$occurrence = Meeting::where('groundhog_series_key', $this->seriesKey)
    ->where('groundhog_original_starts_at', $this->originalStart)
    ->sole();
```

The query returns the virtual occurrence, or the stored exception if the occurrence has been edited since. See [Finding an occurrence again from its identity](Identifying-Occurrences#finding-an-occurrence-again-from-its-identity).

## Bulk deletes of series rows bypass cleanup

When a series model is deleted, Groundhog removes its rule, its stored occurrences and its exceptions. A query-level delete such as `Meeting::withoutOccurrences()->where('title', 'Standup')->delete()` runs no model events, just as with any Eloquent bulk delete. Groundhog's cleanup does not run, and the rule rows are left behind.

**Workaround:** delete series one model at a time, so the model events run:

```php
Meeting::withoutOccurrences()->where('title', 'Standup')->get()->each->delete();
```

See [Deleting a series](Managing-a-Series#deleting-a-series) and [Query-level writes](Stored-Records-and-Bulk-Writes#query-level-writes).

## Dense rules and the materialisation ceiling

A series whose rule generates more than [max_occurrences_per_series](Configuration#max_occurrences_per_series) occurrences between its start and the horizon is rejected on save. With the default of 50,000, a `FREQ=MINUTELY` rule is rejected. A read that would need occurrences beyond [max_materialization_ahead](Configuration#max_materialization_ahead) after now throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded).

**Workaround:** use a less dense rule, or add `COUNT` or `UNTIL`. Give far queries a nearer upper bound. If your application really needs more, raise the two config values.

## cursor() identity

Stored rows streamed by `withoutOccurrences()->cursor()` do not include the identity attributes `groundhog_series_key` and `groundhog_original_starts_at` at first. They are resolved the first time `isOccurrenceException()`, `originalOccurrenceStart()` or `series` is used on the model. Serialising such a row before that leaves the attributes out.

```php
$exception = Meeting::withoutOccurrences()
    ->where('title', 'Standup')
    ->cursor()
    ->first(fn ($m) => $m->id === $exceptionId); // the 16 March exception

array_key_exists('groundhog_series_key', $exception->getAttributes()); // => false
$exception->isOccurrenceException();                                   // => true
$exception->toArray()['groundhog_series_key'];                         // => the series id
```

**Workaround:** use `get()` or `lazy()` when you serialise stored rows, or call one of the predicates first. See [Stored rows streamed with cursor()](Identifying-Occurrences#stored-rows-streamed-with-cursor).

## No "this and following" edits

Saving an occurrence changes that one occurrence only, and editing the series changes all of its occurrences. Groundhog has no operation that changes one occurrence and every later one.

**Workaround:** split the series yourself. Give the existing series an `UNTIL` before the split date, then create a new series from that date with the new values. Changing the rule detaches the existing exceptions and discards cancellations, so re-apply any that you still need. See [Changing the rule or the start](Managing-a-Series#changing-the-rule-or-the-start).

## No RDATE or EXDATE

A rule is a single RRULE. `EXDATE`, `RDATE` and `EXRULE` lines are rejected with [InvalidRecurrenceRule](Errors#invalidrecurrencerule).

**Workaround:** to remove a date, cancel that occurrence with `delete()` ([Cancelling Occurrences](Cancelling-Occurrences)). To add a date, create a plain record for it.

## No iCalendar import or export

Groundhog stores and returns RRULE values, not iCalendar (`.ics`) files or events.

**Workaround:** use an iCalendar library to read or write files. Assign the `RRULE` (and, for its time zone, the `DTSTART;TZID` line) of an imported event to `recurrence_rule`. When exporting, read the rule from `recurrence_rule`, which is an `RRule\RRule`. Cancellations and exceptions are not part of that rule, so export them as separate entries.
