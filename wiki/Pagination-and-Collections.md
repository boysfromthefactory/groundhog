This page shows how pagination, counts, aggregates, ordering, chunking and collection methods behave when a query returns occurrences. Read it when you list occurrences in a UI or process them in batches.

The examples use two daily series and one plain record, queried over 2–6 March 2026:

```php
Meeting::create(['title' => 'Alpha', 'location' => 'Room A', 'capacity' => 10,
    'starts_at' => '2026-03-02 08:00:00', 'ends_at' => '2026-03-02 09:00:00',
    'recurrence_rule' => 'FREQ=DAILY']);

Meeting::create(['title' => 'Bravo', 'location' => 'Room B', 'capacity' => 20,
    'starts_at' => '2026-03-02 12:00:00', 'ends_at' => '2026-03-02 13:00:00',
    'recurrence_rule' => 'FREQ=DAILY']);

Meeting::create(['title' => 'Charlie', 'location' => 'Room A', 'capacity' => 5,
    'starts_at' => '2026-03-04 10:00:00', 'ends_at' => '2026-03-04 11:00:00']);

$week = fn () => Meeting::whereBetween('starts_at', ['2026-03-02 00:00:00', '2026-03-06 23:59:59']);
```

That window holds 11 rows: five Alpha occurrences at 08:00, five Bravo occurrences at 12:00, and Charlie on 4 March at 10:00. The outcomes below are written as "title day time".

## Pagination

`paginate()` pages over the merged rows, and its total counts every occurrence:

```php
$page = $week()->orderBy('starts_at')->paginate(4, page: 2);

$page->total();  // => 11
$page->items();
// => Alpha 04 08:00, Charlie 04 10:00, Bravo 04 12:00, Alpha 05 08:00
```

`simplePaginate()` works the same way without the total:

```php
$page = $week()->orderBy('starts_at')->simplePaginate(4, page: 3);

$page->items();         // => Bravo 05 12:00, Alpha 06 08:00, Bravo 06 12:00
$page->hasMorePages();  // => false
```

`cursorPaginate()` is not supported; see [Limitations](Limitations).

## Counting, existence and aggregates

`count`, `exists`, `doesntExist`, `min`, `max`, `sum` and `avg` are computed over the occurrences, not over the stored rows.

```php
$week()->count();                // => 11
$week()->min('starts_at');       // => '2026-03-02 08:00:00'
$week()->max('starts_at');       // => '2026-03-06 12:00:00'
$week()->sum('capacity');        // => 155  (5 × 10 + 5 × 20 + 5)
$week()->avg('capacity');        // => 14.09  (155 / 11)

$saturday = ['2026-03-07 00:00:00', '2026-03-07 23:59:59'];

Meeting::whereBetween('starts_at', $saturday)->exists();                          // => true
Meeting::whereBetween('starts_at', $saturday)->where('title', 'Charlie')->exists(); // => false
```

## Ordering and ties

Ordering works as on stored rows. Descending order returns the latest occurrence first:

```php
$week()->orderByDesc('starts_at')->first();  // => Bravo 06 12:00
```

Ordering by a non-time column, then by start:

```php
$week()->orderBy('title')->orderBy('starts_at')->get();
// => Alpha 02–06 at 08:00, then Bravo 02–06 at 12:00, then Charlie 04 10:00
```

Occurrences can tie on every column you order by, for example two series with the same title and start. When the query has at least one `orderBy` and is not grouped, distinct or a union, Groundhog appends tie-breakers (series key, original start, primary key), so pages never repeat or skip rows and the same query returns the same order every time. Adding a second Alpha series in "Room C" and paging `orderBy('starts_at')->orderBy('title')` four at a time gives 16 distinct rows across 4 pages, identical on every run.

Grouped and distinct queries keep only your ordering, because databases reject `ORDER BY` columns outside the grouped or selected set. Without any `orderBy`, row order is up to the database, as in plain Eloquent.

## Chunking and lazy iteration

`chunk()`, `each()`, `lazy()` and `cursor()` visit every occurrence exactly once:

```php
$seen = [];
$week()->chunk(3, function ($chunk) use (&$seen) {
    foreach ($chunk as $meeting) {
        $seen[] = $meeting;
    }
});
count($seen);  // => 11

$week()->lazy(3)->count();   // => 11
$week()->cursor()->count();  // => 11
```

Key-based iteration (`chunkById()`, `chunkByIdDesc()`, `eachById()`, `lazyById()`, `lazyByIdDesc()`) needs a primary key on every row, which occurrences do not have. While occurrences are expanded these methods throw `RecurrenceNotSupported` (see [Errors](Errors)). Use `chunk()` or `lazy()` instead, or iterate stored rows with `withoutOccurrences()`:

```php
Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->chunkById(3, fn ($chunk) => null);
// throws RecurrenceNotSupported

Meeting::withoutOccurrences()->chunkById(3, function ($chunk) {
    // stored rows only: series, plain records and exceptions
});
```

## Collections

Query results are Eloquent collections. Occurrences have no key, so the collection compares them by their identity (series and original start) instead. Set operations therefore keep every occurrence distinct:

```php
$all = $week()->get();

$all->unique()->count();                     // => 11
$all->merge($all)->count();                  // => 11
$all->diff($all->take(4))->count();          // => 7
$all->intersect($all->take(4))->count();     // => 4
```

`find()` accepts an occurrence and returns the matching one from the collection:

```php
$target = $all->first(fn ($m) => ! $m->exists && $m->title === 'Bravo' && $m->starts_at->format('d') === '04');

$found = $all->find($target);
$found->title;      // => 'Bravo'
$found->starts_at;  // => 2026-03-04 12:00
```

## Selecting columns, grouping and distinct

When you `select()` explicit columns on a query that is neither grouped nor distinct, Groundhog adds the identity columns, so the returned occurrences stay recognisable and saveable:

```php
$meetings = $week()->select(['title', 'starts_at'])->get();

$meetings->count();  // => 11

$alpha = $meetings->first(fn ($m) => $m->title === 'Alpha');
$alpha->isVirtualOccurrence();  // => true
```

`groupBy`, `having` and `distinct` run over the occurrences as rows. Counting rows per location:

```php
use Illuminate\Support\Facades\DB;

$week()
    ->select('location', DB::raw('count(*) as n'))
    ->groupBy('location')
    ->havingRaw('count(*) > 1')
    ->orderBy('location')
    ->get()
    ->map(fn ($m) => $m->location.'='.$m->n)
    ->all();
// => ['Room A=6', 'Room B=5']

$week()->distinct()->select('title')->orderBy('title')->pluck('title')->all();
// => ['Alpha', 'Bravo', 'Charlie']
```

Grouped and distinct rows are partial results, like partial Eloquent selections: read them, do not save them.

Next: [Editing Occurrences](Editing-Occurrences)
