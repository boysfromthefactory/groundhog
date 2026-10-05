This page explains how to remove a single occurrence from a series and what happens when you delete an occurrence's exception. Read it when users can skip one date of a recurring model.

Examples use the reference series: a `Meeting` titled "Standup" in "Room A", every Monday 09:00–10:00 from 2 March 2026, with March occurrences on 2, 9, 16, 23 and 30 March at 09:00. `$on($date)` is shorthand for `Meeting::whereBetween('starts_at', ["$date 00:00:00", "$date 23:59:59"])->sole()`, and "March" lists each row as day, location and "(stored)" for stored rows, as in [Editing Occurrences](Editing-Occurrences#saving-an-edited-occurrence).

## Cancelling an occurrence (`delete()`)

Calling `delete()` on a virtual occurrence cancels it. Groundhog records the cancellation in `groundhog_exclusions` against the occurrence's original start; it does not insert a `meetings` row. The `deleting` and `deleted` model events fire as usual.

```php
Meeting::deleting(fn () => logger('deleting'));
Meeting::deleted(fn () => logger('deleted'));

$on('2026-03-23')->delete();
// => deleting, deleted
```

Result: March reads `['02 Room A', '09 Room A', '16 Room A', '30 Room A']`. The `meetings` table still has only the series row, and `groundhog_exclusions` has 1 row with a `null` `exception_id` (a cancellation).

The query-level `Meeting::where(...)->delete()` acts on stored rows only and never cancels occurrences; see [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes).

## Vetoing with a `deleting` listener

A `deleting` listener that returns `false` stops the cancellation, as it stops any Eloquent delete. `delete()` then returns `false` and nothing is recorded.

```php
Meeting::deleting(fn () => false);

$on('2026-03-23')->delete();   // => false
```

Result: March still reads `['02 Room A', '09 Room A', '16 Room A', '23 Room A', '30 Room A']`, and `groundhog_exclusions` is empty.

## Deleting an exception (hard vs soft)

An exception is a stored row that replaces one occurrence (see [Editing Occurrences](Editing-Occurrences#saving-an-edited-occurrence)). Hard-deleting it does not bring the original occurrence back: Groundhog turns the exception's link into a cancellation, so the occurrence stays cancelled.

```php
$exception = $on('2026-03-16');
$exception->location = 'Room B';
$exception->save();

$on('2026-03-16')->forceDelete();
```

Result: March reads `['02 Room A', '09 Room A', '23 Room A', '30 Room A']`, and `meetings` has only the series row.

The same holds for an exception loaded by primary key. After `Meeting::find($exception->id)->forceDelete()`, March reads `['02 Room A', '09 Room A', '23 Room A', '30 Room A']` and the single `groundhog_exclusions` row has a `null` `exception_id`.

`Meeting` uses `SoftDeletes`, so `forceDelete()` is the hard delete. On a model without `SoftDeletes`, `delete()` is a hard delete and behaves the same way: the exception row is removed and its occurrence stays cancelled.

## Restoring a soft-deleted exception

Soft-deleting an exception with `delete()` hides it. While it is trashed, its occurrence counts as cancelled: neither the exception nor the original occurrence is returned. `restore()` brings the exception back.

```php
$exception = $on('2026-03-16');
$exception->location = 'Room B';
$exception->save();

$exception->delete();
// March => ['02 Room A', '09 Room A', '23 Room A', '30 Room A']

$exception->restore();
// March => ['02 Room A', '09 Room A', '16 Room B (stored)', '23 Room A', '30 Room A']
```

Next: [Managing a Series](Managing-a-Series)
