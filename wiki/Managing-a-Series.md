This page explains what happens to occurrences, exceptions and cancellations when you edit, re-schedule or delete the stored series record. Read it before you build screens that let users change a whole recurring series.

The examples use the reference series: a weekly Monday "Standup" in "Room A", 2026-03-02 09:00–10:00, with March occurrences on 2, 9, 16, 23 and 30 March. Some examples first turn the 16 March occurrence into an exception in "Room B" and cancel the 23 March occurrence:

```php
$series = Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00',
    'ends_at' => '2026-03-02 10:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

$march = ['2026-03-01 00:00:00', '2026-03-31 23:59:59'];

// Exception on 16 March
$sixteenth = Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();
$sixteenth->location = 'Room B';
$sixteenth->save();

// Cancellation on 23 March
Meeting::whereBetween('starts_at', ['2026-03-23 00:00:00', '2026-03-23 23:59:59'])->sole()->delete();
```

See [Editing Occurrences](Editing-Occurrences) and [Cancelling Occurrences](Cancelling-Occurrences) for how those two steps work.

## Editing series attributes

Occurrences are computed from the series row, so editing the series changes every occurrence that has no exception. An exception is a stored record of its own and keeps its own values.

```php
$series->title = 'Daily sync';
$series->save();

Meeting::whereBetween('starts_at', $march)->orderBy('starts_at')->pluck('title')->all();
// => ['Daily sync', 'Daily sync', 'Standup', 'Daily sync', 'Daily sync']
```

The 16 March exception still says "Standup" and is still linked to the series: its `groundhog_series_key` is the series key.

## Changing the rule or the start

Changing the rule, or moving the series start, produces a different set of occurrences. The old exceptions no longer replace anything, so Groundhog detaches them: each becomes a plain record with no link to the series. Cancellations are discarded.

Assigning a Tuesday rule:

```php
$series->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU']);
```

Result: March now returns the Tuesdays 3, 10, 17, 24 and 31 March at 09:00 in "Room A", plus the former 16 March exception in "Room B" as a stored plain record. Its `groundhog_series_key` is `null` and `isOccurrenceException()` returns `false`.

Moving the start from 09:00 to 10:00:

```php
$series->update([
    'starts_at' => '2026-03-02 10:00:00',
    'ends_at' => '2026-03-02 11:00:00',
]);

Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])
    ->orderBy('starts_at')
    ->get();
// => 09:00 Room B (plain record, the detached exception)
// => 10:00 Room A (virtual occurrence of the series)
```

The cancellation of 23 March is discarded too, so 23 March is back, now at 10:00.

A detached exception and a new occurrence can fall at the same time. With a daily rule, 16 March returns both: the virtual "Room A" occurrence and the stored "Room B" record.

## Changing only the duration

Changing only the end column keeps the rule and the start, so exceptions and cancellations stay. The ends of the occurrences move.

```php
$series->update(['ends_at' => '2026-03-02 11:00:00']);
```

Result: March returns 2, 9 (both ending at 11:00), 16 ("Room B", still an exception) and 30 March. 23 March stays cancelled.

## Re-assigning the same rule

Assigning the rule the series already has changes nothing: exceptions and cancellations are kept.

```php
$series->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO']);
```

Result: March returns 2, 9, 16 ("Room B", still an exception) and 30 March.

## Removing the rule

Setting the rule to `null` turns the series into a plain record. It is returned once, at its own start.

```php
$series->update(['recurrence_rule' => null]);

Meeting::whereBetween('starts_at', $march)->get()->modelKeys();
// => [$series->id]
```

See [Recurrence Rules](Recurrence-Rules#replacing-and-removing-a-rule) for rule assignment in general.

## Deleting a series

`forceDelete()` on the series removes its rule, its occurrence index, its exclusions and every exception, including soft-deleted ones. Each removed exception fires the `deleted` event.

```php
$trashed = Meeting::whereBetween('starts_at', ['2026-03-23 00:00:00', '2026-03-23 23:59:59'])->sole();
$trashed->save();   // 23 March becomes an exception
$trashed->delete(); // and is soft-deleted

$series->forceDelete();

Meeting::withTrashed()->withoutOccurrences()->count(); // => 0
```

A model without soft deletes does the same on `delete()`. `destroy()` takes the same path, so `Shift::destroy($shift->id)` removes the shift series and its rule:

```php
$shift = Shift::create([
    'label' => 'Early',
    'starts_at' => '2026-03-02 06:00:00',
    'recurrence_rule' => 'FREQ=DAILY;COUNT=5',
]);

Shift::destroy($shift->id);

Shift::count(); // => 0
```

Deleting series rows with a query-level `delete()` skips this cleanup; see [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes#query-level-writes).

## Soft deleting and restoring a series

When the model uses `SoftDeletes`, `delete()` on the series hides the series and all its occurrences. `restore()` brings them back, exceptions included.

```php
$series->delete();

Meeting::whereBetween('starts_at', $march)->count();                // => 0
Meeting::withTrashed()->whereBetween('starts_at', $march)->count(); // => 5

$series->restore();
```

Result: March again returns 2, 9, 16 ("Room B", stored), 23 and 30 March (this example has the 16 March exception but no cancellation). `Meeting::destroy($series->id)` soft-deletes the same way.

Next: [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes)
