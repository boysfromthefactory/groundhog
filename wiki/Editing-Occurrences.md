This page explains how to change a single occurrence of a series without touching the rest. Read it when you let users move, rename or otherwise adjust one date of a recurring model.

Examples use the reference series: a `Meeting` titled "Standup" in "Room A", every Monday 09:00–10:00 from 2 March 2026, so its March occurrences are 2, 9, 16, 23 and 30 March at 09:00 (see [Declaring Recurring Models](Declaring-Recurring-Models)).

## Saving an edited occurrence

An occurrence returned by a query is virtual: it has no row and no primary key. When you save it, Groundhog inserts a new `meetings` row for it and links that row to the series and the occurrence's original start. This stored row is the occurrence's *exception*. The series row and every other occurrence stay unchanged.

```php
$series = Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

$occurrence = Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();

$occurrence->location = 'Room B';
$occurrence->save();

$occurrence->exists;                    // => true
$occurrence->isOccurrenceException();   // => true
Meeting::find($series->id)->location;   // => 'Room A'
```

Result: the `meetings` table has 2 rows (the series and the exception), and `groundhog_exclusions` has one row linking the exception to the original start 2026-03-16 09:00.

From now on, queries return the exception in place of the 16 March occurrence:

```php
Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get()
    ->map(fn (Meeting $m) => $m->starts_at->format('d').' '.$m->location.($m->exists ? ' (stored)' : ''))
    ->all();
// => ['02 Room A', '09 Room A', '16 Room B (stored)', '23 Room A', '30 Room A']
```

The returned exception reports `isOccurrenceException() === true`, and its `groundhog_series_key` is the series id. See [Identifying Occurrences](Identifying-Occurrences) for the identity attributes.

Changing attributes without saving stores nothing. If you set `location = 'Room B'` on the 16 March occurrence and never call `save()`, the table still has 1 row, `groundhog_exclusions` is empty, and the 16 March occurrence is still in "Room A".

## `update()`, `increment()`, `decrement()`

`update()`, `increment()` and `decrement()` on a virtual occurrence persist it as an exception, just like `save()`. Only that occurrence changes; the series row and any plain records keep their values.

```php
$series = Meeting::create([
    'title' => 'Standup', 'location' => 'Room A', 'capacity' => 10,
    'starts_at' => '2026-03-02 09:00:00', 'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);
$plain = Meeting::create([
    'title' => 'Review', 'location' => 'Room A', 'capacity' => 3,
    'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00',
]);

$on = fn (string $date) => Meeting::whereBetween('starts_at', ["$date 00:00:00", "$date 23:59:59"])->sole();

$on('2026-03-16')->update(['location' => 'Room B']);
$on('2026-03-23')->increment('capacity');

$on('2026-03-23')->capacity;            // => 11
$on('2026-03-23')->exists;              // => true
Meeting::find($series->id)->capacity;   // => 10
Meeting::find($plain->id)->capacity;    // => 3
```

Result: `meetings` has 4 rows (series, plain record, two exceptions), `groundhog_exclusions` has 2 rows, and March reads `['02 Room A', '09 Room A', '11 Room A (stored)', '16 Room B (stored)', '23 Room A (stored)', '30 Room A']`.

`decrement()` works the same way. Starting from a fresh series with capacity 10:

```php
$on('2026-03-23')->decrement('capacity', 2);

$exception = $on('2026-03-23');
$exception->exists;                     // => true
$exception->capacity;                   // => 8
Meeting::find($series->id)->capacity;   // => 10
```

Result: `groundhog_exclusions` has 1 row.

These are model methods. The query-level `Meeting::where(...)->update()` or `->increment()` act on stored rows only and never create exceptions; see [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes).

## Moving an occurrence

To move an occurrence, change its start and end and save it. The exception keeps its link to the original start, so the occurrence disappears from its old date and appears only at the new time.

```php
$occurrence = $on('2026-03-16');
$occurrence->fill([
    'starts_at' => '2026-03-18 14:00:00',
    'ends_at' => '2026-03-18 15:00:00',
])->save();

Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->count();
// => 0

Meeting::whereBetween('starts_at', ['2026-03-18 00:00:00', '2026-03-18 23:59:59'])
    ->get()
    ->map(fn (Meeting $m) => $m->starts_at->format('Y-m-d H:i'))
    ->all();
// => ['2026-03-18 14:00']
```

If you move an occurrence onto the time of another occurrence, both are returned. Moving the 16 March occurrence to 23 March 09:00–10:00 in "Room B" makes a query for 23 March return two rows, with locations `['Room A', 'Room B']`.

## Editing an exception again

Once an occurrence is stored, a query returns the exception, which is an ordinary stored model. Saving it again updates the same row in place; no second exception is created.

```php
$on('2026-03-16')->fill(['location' => 'Room B'])->save();

$exception = $on('2026-03-16');
$exception->location = 'Room C';
$exception->save();
```

Result: `meetings` still has 2 rows, `groundhog_exclusions` still has 1 row, and March reads `['02 Room A', '09 Room A', '16 Room C (stored)', '23 Room A', '30 Room A']`.

## Saving without changes

Calling `save()` on a virtual occurrence stores it even if no attribute changed. Use this when you need a primary key for the occurrence, for example to attach related records to it.

```php
$on('2026-03-16')->save();
```

Result: `meetings` has 2 rows, and March reads `['02 Room A', '09 Room A', '16 Room A (stored)', '23 Room A', '30 Room A']`.

## Events

Saving a virtual occurrence is an insert, so it fires Eloquent's creation events. Saving the resulting exception again is an update and fires the update events.

```php
foreach (['saving', 'creating', 'created', 'updating', 'updated', 'saved'] as $event) {
    Meeting::{$event}(fn () => logger($event));
}

$occurrence = $on('2026-03-16');
$occurrence->save();
// => saving, creating, created, saved

$occurrence->location = 'Room B';
$occurrence->save();
// => saving, updating, updated, saved
```

Observers and model event listeners therefore see an occurrence becoming an exception as a newly created model.

## Concurrent edits of the same occurrence

Each occurrence can have at most one exception. If two requests load the same virtual occurrence and both save it, the first save stores the exception and the second throws `Illuminate\Database\QueryException`. The failing save is rolled back, so it leaves no orphan row.

```php
use Illuminate\Database\QueryException;

$first = $on('2026-03-16');
$second = $on('2026-03-16');

$first->save();

try {
    $second->save();
} catch (QueryException $e) {
    // => thrown: the 16 March occurrence already has an exception
}
```

Result: `meetings` has 2 rows and `groundhog_exclusions` has 1 row. To retry, query the occurrence again; the query now returns the stored exception, which you can edit in place.

## Rules on exceptions are rejected

An exception replaces exactly one occurrence, so it cannot recur itself. Assigning a rule to an exception throws `RecurrenceNotSupported`, whether the exception came from a query or from `find()`.

```php
use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;

$on('2026-03-16')->save();

$exception = $on('2026-03-16');
$exception->recurrence_rule = 'FREQ=DAILY';
// => throws RecurrenceNotSupported
```

See [Errors](Errors) for the message and the other cases that throw this exception.

Next: [Cancelling Occurrences](Cancelling-Occurrences)
