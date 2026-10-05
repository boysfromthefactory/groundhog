Some queries work on the rows stored in your table rather than on occurrences: lookups by primary key, route model binding, `withoutOccurrences()` and query-level writes. This page explains when you get stored rows and how to tell series, exceptions and plain records apart.

The examples use the reference series (weekly Monday "Standup", "Room A", from 2026-03-02 09:00–10:00) and, where noted, a plain meeting:

```php
$series = Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00',
    'ends_at' => '2026-03-02 10:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

$plain = Meeting::create([
    'title' => 'Review',
    'location' => 'Room A',
    'starts_at' => '2026-03-11 14:00',
    'ends_at' => '2026-03-11 15:00',
]);
```

## Looking up by key

Occurrences have no primary key, so any query that pins the primary key reads stored rows. `find()` on the series key returns the series record itself, not an occurrence.

```php
$found = Meeting::find($series->id);

$found->exists;                          // => true
$found->starts_at->format('Y-m-d H:i'); // => '2026-03-02 09:00'
```

The same applies to `whereKey()`, `whereIn('id', [...])`, `whereKeyNot()` and `destroy()`:

```php
Meeting::whereIn('id', [$series->id])->get()->modelKeys(); // => [$series->id]

Meeting::whereKeyNot($plain->id)->get()->modelKeys();     // => [$series->id]
```

`whereKeyNot()` returns stored rows other than the key, not the March occurrences. `destroy()` deletes the series through its normal delete path; see [Managing a Series](Managing-a-Series#deleting-a-series).

Belongs-to relations that point at the model resolve to the stored series. With an `Attendee` whose `meeting_id` is the series key:

```php
$attendee->meeting->is($series);                               // => true
Attendee::with('meeting')->find($attendee->id)->meeting->is($series); // => true
Attendee::whereHas('meeting')->pluck('id')->all();             // => [$attendee->id]
```

## Route model binding

Implicit route model binding looks the record up by key, so a route parameter binds the stored series.

```php
Route::get('/meetings/{meeting}', fn (Meeting $meeting) => $meeting->title.'#'.$meeting->getKey());
```

Result: `GET /meetings/{series id}` responds `Standup#{series id}`. To address a single occurrence in a URL or request, use its identity instead; see [Identifying Occurrences](Identifying-Occurrences#editing-an-occurrence-from-a-form-or-api-request).

## `withoutOccurrences()`

`withoutOccurrences()` turns off expansion for one query. It returns the stored rows: series records, plain records and exceptions, with no virtual occurrences.

```php
Meeting::withoutOccurrences()->orderBy('id')->get()->modelKeys();
// => [$series->id, $plain->id]
```

Every returned model has `exists === true`. Use it for admin screens over the table itself, and for key-based iteration such as `chunkById()`, which throws on expanded queries (see [Pagination and Collections](Pagination-and-Collections#chunking-and-lazy-iteration)).

## Query-level writes

Query-level writes act on stored rows only. They never create exceptions or cancellations, and they do not fire model events. This covers `update`, `delete`, `forceDelete`, `increment`, `decrement`, `incrementEach`, `decrementEach`, `touch`, `insert`, `insertGetId`, `insertOrIgnore` and `upsert`.

With the series, the 16 March exception (saved in "Room B") and the plain "Review" meeting:

```php
Meeting::where('title', 'Standup')->update(['location' => 'X']); // => 2
Meeting::where('title', 'Standup')->delete();                    // => 2
```

Both statements affect the two stored "Standup" rows, the series and the exception. They do not turn the virtual occurrences into exceptions. The plain meeting keeps "Room A" and `groundhog_exclusions` still holds only the one existing link.

The other writes behave the same. With the series and its exception at capacity 10:

```php
Meeting::where('title', 'Standup')->increment('capacity');          // series and exception: 11
Meeting::where('title', 'Standup')->decrementEach(['capacity' => 2]); // from 10: 8
```

`insert()`, `insertGetId()`, `insertOrIgnore()` and `upsert()` write stored rows through the model query and store no exclusions:

```php
$id = Meeting::insertGetId([
    'title' => 'With id',
    'starts_at' => '2026-04-01 09:00:00',
    'ends_at' => '2026-04-01 10:00:00',
]);

Meeting::upsert([['id' => $id, 'title' => 'Upserted', 'starts_at' => '2026-04-01 09:00:00', 'ends_at' => '2026-04-01 10:00:00']], ['id'], ['title']);
```

To change or cancel a single occurrence, load it and call `save()`, `update()` or `delete()` on the model; see [Editing Occurrences](Editing-Occurrences) and [Cancelling Occurrences](Cancelling-Occurrences).

Warning: bulk-deleting series rows bypasses the cleanup of their rules, just as Eloquent bulk deletes bypass model events. A query-level `delete()` or `forceDelete()` on a series row leaves its rule, occurrence index and exceptions behind. Delete series one model at a time, as described in [Managing a Series](Managing-a-Series#deleting-a-series). See [Limitations](Limitations).

## Roles of stored records

A stored row is a series, an exception or a plain record. Two predicates tell them apart:

- `isRecurringSeries()` is `true` only for a stored record with a rule.
- `isOccurrenceException()` is `true` for a stored record that replaces one occurrence of a series.

```php
Meeting::find($series->id)->isRecurringSeries(); // => true
Meeting::find($plain->id)->isRecurringSeries();  // => false

$virtual = Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])->first();
$virtual->isRecurringSeries();                   // => false
```

After the 16 March occurrence is saved, `isOccurrenceException()` returns `true` for it, also when it is loaded later with `Meeting::find($id)`. A plain record answers `false` to both.

Next: [Identifying Occurrences](Identifying-Occurrences)
