This page explains what an ordinary Eloquent query on a recurring model returns and how time windows, other constraints and relationships apply to occurrences. Read it once your model uses the trait and you want to list, filter or load occurrences.

The examples use the reference series: a weekly Monday "Standup" in "Room A", starting 2026-03-02 09:00–10:00.

```php
$series = Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);
```

## What a query returns

A query on a recurring model behaves as if every occurrence were a stored row carrying the series' attributes, its own start and end, and a `null` key. Plain records (no rule) and exceptions (edited occurrences) are returned as ordinary stored rows. The series row itself is not part of the result; its occurrences stand in for it.

```php
$meetings = Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get();

$meetings->map(fn ($m) => $m->starts_at->format('Y-m-d H:i'))->all();
// => ['2026-03-02 09:00', '2026-03-09 09:00', '2026-03-16 09:00', '2026-03-23 09:00', '2026-03-30 09:00']

$meetings->map(fn ($m) => $m->ends_at->format('H:i'))->unique()->all();  // => ['10:00']
$meetings->pluck('title')->unique()->all();     // => ['Standup']
$meetings->pluck('location')->unique()->all();  // => ['Room A']
```

A plain record in the same window is returned once, as a stored model:

```php
$plain = Meeting::create([
    'title' => 'Review',
    'starts_at' => '2026-03-11 14:00:00',
    'ends_at' => '2026-03-11 15:00:00',
]);

Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->get()
    ->filter(fn ($m) => $m->exists)
    ->modelKeys();
// => [$plain->id]
```

Single-row and scalar methods work over the same rows:

```php
$march = ['2026-03-01 00:00:00', '2026-03-31 23:59:59'];

Meeting::whereBetween('starts_at', $march)->value('title');  // => 'Standup'

Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])
    ->sole()
    ->starts_at;  // => 2026-03-16 09:00
```

`pluck('starts_at')` over March, ordered by start, gives the dates 2026-03-02, 2026-03-09, 2026-03-16, 2026-03-23 and 2026-03-30.

Lookups by primary key (`find`, `findOrFail`, `whereKey`, route model binding) see stored rows only, so `Meeting::find($series->id)` returns the stored series starting 2026-03-02 09:00. See [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes).

## Time windows

Constrain the start column to choose which occurrences you get. The bounds are evaluated per occurrence, exactly as they would be on stored rows.

```php
Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])->count();
// => 5
```

A finite rule only produces its own occurrences, however large the window. With `FREQ=DAILY;COUNT=3`:

```php
Meeting::whereBetween('starts_at', ['2000-01-01 00:00:00', '2099-12-31 00:00:00'])->count();
// => 3
```

Constraints on the end column are evaluated too, so you can find occurrences that are running at a given moment, including ones that started before it. For a daily series at 09:00–10:00 starting 2 March:

```php
Meeting::where('starts_at', '<', '2026-03-03 09:30:00')
    ->where('ends_at', '>', '2026-03-03 09:30:00')
    ->get();
// => one occurrence, starting 2026-03-03 09:00
```

Bounds combined with `orWhere` are never used to drop occurrences: Groundhog ignores bounds it cannot prove apply to every row, and the database then evaluates the full condition. Assuming today is 1 March 2026, a daily series starting 2026-03-01 09:00 gives:

```php
Meeting::where(fn ($q) => $q->where('starts_at', '<', '2026-03-10 00:00:00')->orWhere('title', 'x'))
    ->get();
// => 9 occurrences (1 to 9 March)
```

A rule that never produces an occurrence (for example `FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30`) contributes no rows: `Meeting::count()` is 0, but `Meeting::find($series->id)` still returns the record.

## Non-time constraints

Any other `where` clause is evaluated against each occurrence, using the attributes it inherits from its series (or the exception's own values).

```php
// Standup in Room A, Retro in Room B, both weekly on Mondays from 2 March.
Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->where('location', 'Room A')
    ->get();
// => 5 occurrences, all titled 'Standup'
```

## Open-ended rules and the horizon

A rule without `COUNT` or `UNTIL` never ends. When a query gives no upper bound on the start or end column, Groundhog expands such a rule up to the configured [horizon](Configuration#horizon) (one year by default) after the query's lower bound, or after now when there is no lower bound.

Assuming today is 1 March 2026, with a daily series starting 2026-03-01 09:00:

```php
Meeting::count();            // => 365
Meeting::max('starts_at');   // => '2027-02-28 09:00:00'

$later = Meeting::where('starts_at', '>=', '2030-01-01 00:00:00')->orderBy('starts_at')->get();

$later->count();             // => 365
$later->first()->starts_at;  // => 2030-01-01 09:00
$later->last()->starts_at;   // => 2030-12-31 09:00
```

Give queries an upper bound whenever you can; the horizon only exists so that unbounded queries end.

## Occurrence attributes and predicates

An occurrence is a hydrated `Meeting` that has not been stored:

- `exists` is `false` and `getKey()` returns `null`.
- `isVirtualOccurrence()` returns `true`.
- `originalOccurrenceStart()` returns the start the rule generated it at; for an unedited occurrence it equals `starts_at`.
- `series` is the stored series it belongs to.

```php
$occurrence = Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();

$occurrence->exists;                                                   // => false
$occurrence->getKey();                                                 // => null
$occurrence->isVirtualOccurrence();                                    // => true
$occurrence->originalOccurrenceStart()->equalTo($occurrence->starts_at); // => true
$occurrence->series->is($series);                                      // => true
```

To find the same occurrence again later, query its identity columns; see [Identifying Occurrences](Identifying-Occurrences).

## Relationships

Relations on the model work over occurrences. Assume `Room` has a `meetings()` HasMany relation, and `Meeting` has `room()` (BelongsTo), `attendees()` (HasMany) and `series`.

Eager loading resolves foreign-key relations from the series values. Relations keyed on the model's own primary key, such as `attendees`, are empty on occurrences, because an occurrence has no key. `series` loads the stored series.

```php
$roomA = Room::create(['name' => 'A']);
$series->update(['room_id' => $roomA->id]);  // the Standup series from the top of the page
Attendee::create(['meeting_id' => $series->id, 'name' => 'Ada']);

$meetings = Meeting::with(['room', 'attendees', 'series'])
    ->whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->get();

$meetings->count();                                     // => 5
$meetings->every(fn ($m) => $m->room?->is($roomA));     // => true
$meetings->every(fn ($m) => $m->attendees->isEmpty());  // => true
$meetings->every(fn ($m) => $m->series?->is($series));  // => true
```

`whereHas` from another model, relation queries and joins see the occurrences. With the Standup series in room A and a one-off "Offsite" meeting in room B on 15 April:

```php
$march = ['2026-03-01 00:00:00', '2026-03-31 23:59:59'];

Room::whereHas('meetings', fn ($q) => $q->whereBetween('starts_at', $march))->pluck('id')->all();
// => [$roomA->id]

$roomA->meetings()->whereBetween('starts_at', $march)->count();
// => 5

Meeting::select('meetings.*')
    ->join('rooms', 'rooms.id', '=', 'meetings.room_id')
    ->where('rooms.name', 'A')
    ->whereBetween('meetings.starts_at', $march)
    ->get();
// => 5 occurrences
```

Use `select('meetings.*')` with joins so the joined table's columns do not overwrite the meeting's.

A relation pointing at a series by its key resolves to the stored series, because key lookups see stored rows only:

```php
$attendee = Attendee::create(['meeting_id' => $series->id, 'name' => 'Ada']);

$attendee->meeting->is($series);                                  // => true
Attendee::with('meeting')->find($attendee->id)->meeting->is($series); // => true
Attendee::whereHas('meeting')->pluck('id')->all();                // => [$attendee->id]
```

## Multiple recurring models

Each recurring model only returns its own occurrences, even though all models share one rule table and keys may collide across tables. With a `Meeting` series on Mondays and a `Shift` series ("Early", 06:00) on Tuesdays from 3 March, both with the same id:

```php
$march = ['2026-03-01 00:00:00', '2026-03-31 23:59:59'];

Meeting::whereBetween('starts_at', $march)->orderBy('starts_at')->get();
// => 2, 9, 16, 23 and 30 March at 09:00

Shift::whereBetween('starts_at', $march)->orderBy('starts_at')->get();
// => 3, 10, 17, 24 and 31 March at 06:00
```

`Shift` has no end column, so its occurrences only get a start.

Next: [Pagination and Collections](Pagination-and-Collections)
