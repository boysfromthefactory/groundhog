This page lists every exception Groundhog throws, what causes it, the message you will see, and how to fix or avoid it. All classes are in the `BoysFromTheFactory\Groundhog\Exceptions` namespace and extend a standard PHP exception, so existing `catch` blocks for that parent still work. The example messages use `App\Models\Meeting`; the real message shows your model class and key.

## InvalidRecurrenceRule

**Extends:** `InvalidArgumentException`

The value assigned to `recurrence_rule` cannot become one RFC 5545 RRULE. It is thrown as soon as the rule is assigned, before anything is saved, so no meeting and no rule row are written.

**Cause: a malformed or unsupported rule part.** The message repeats your input and names the part the parser rejected:

```php
Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=XX',
]);
// => InvalidRecurrenceRule: Invalid recurrence rule "FREQ=WEEKLY;BYDAY=XX": Invalid BYDAY value: XX
```

**Cause: an `EXDATE`, `RDATE` or `EXRULE` line.** These would turn the rule into a set of rules and dates, which Groundhog does not support:

```php
$meeting->recurrence_rule = "RRULE:FREQ=DAILY\nEXDATE:20260303T090000Z";
// => InvalidRecurrenceRule: Invalid recurrence rule: unsupported line "EXDATE:20260303T090000Z"; only DTSTART and RRULE are accepted.
```

**Fix:** correct the part named in the message. See [Supported RRULE parts](Recurrence-Rules#supported-rrule-parts). To skip dates, cancel those occurrences ([Cancelling an occurrence](Cancelling-Occurrences#cancelling-an-occurrence-delete)). To add dates, create plain records. See [No RDATE or EXDATE](Limitations#no-rdate-or-exdate).

## RecurrenceNotSupported

**Extends:** `LogicException`

The operation cannot apply to the model in its current role (series, occurrence or exception).

**Cause: a rule assigned to an occurrence or an exception.** An exception replaces exactly one occurrence, so it cannot recur itself. The same applies to a virtual occurrence. The error is thrown on assignment:

```php
$exception = Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();
// the 16 March occurrence, already saved as an exception with key 7

$exception->recurrence_rule = 'FREQ=DAILY';
// => RecurrenceNotSupported: Cannot attach a recurrence rule to App\Models\Meeting [7]: it is an occurrence exception of another series.
```

For a virtual occurrence, which has no key, the message shows `[new]`.

**Fix:** put the rule on a series record, or create a new record for the new series. See [Rules on exceptions are rejected](Editing-Occurrences#rules-on-exceptions-are-rejected).

**Cause: a series saved without a start.** The start column provides the rule's first occurrence. A rule on a record with an empty start cannot be saved, and nothing is written:

```php
$meeting = new Meeting(['title' => 'Standup', 'ends_at' => '2026-03-02 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);
$meeting->save();
// => RecurrenceNotSupported: Cannot save a recurrence rule on App\Models\Meeting [new]: the start attribute is empty.
```

**Fix:** set the start column (`starts_at`, or the column named by `RECURRENCE_STARTS_AT`) before saving. See [Start and end columns](Declaring-Recurring-Models#start-and-end-columns).

**Cause: `chunkById()`, `lazyById()` or `eachById()` while occurrences are expanded.** These methods page by "key greater than the last key". Virtual occurrences have no key, so they would be skipped silently:

```php
Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])->chunkById(3, fn ($chunk) => null);
// => RecurrenceNotSupported: chunkById() cannot iterate expanded occurrences because virtual occurrences have no primary key; use chunk()/lazy(), or withoutOccurrences() for stored rows only.
```

`lazyById()` names `lazyById()` in the message. `chunkByIdDesc()` and `eachById()` run through `chunkById()`, so its message names `chunkById()`.

**Fix:** use `chunk()` or `lazy()` to iterate occurrences. Use `Meeting::withoutOccurrences()->chunkById(...)` to iterate stored rows only. See [Chunking and lazy iteration](Pagination-and-Collections#chunking-and-lazy-iteration).

## OccurrenceLimitExceeded

**Extends:** `RuntimeException`

A rule or query would generate more occurrences than the configured limits allow. Groundhog throws this exception instead of using unbounded time, memory or storage.

**Cause: one generation pass for one series exceeds [max_occurrences_per_series](Configuration#max_occurrences_per_series).** This happens on save when a finite rule is too long, or when an infinite rule is too dense to fit up to now plus the horizon. It also happens on a query that would need more new occurrences of one series than the limit. A rejected save stores nothing; a rejected query does not extend the index.

```php
config(['groundhog.max_occurrences_per_series' => 10]);

Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=DAILY;COUNT=11',
]);
// => OccurrenceLimitExceeded: Recurrence of App\Models\Meeting [1] generates more than 10 occurrences between its start and its end; raise groundhog.max_occurrences_per_series or narrow the rule.
```

The record is inserted before its rule is generated, so the message shows the key it would have had (here 1); the whole save is then rolled back.

With the default limit of 50,000, `'recurrence_rule' => 'FREQ=MINUTELY'` fails in the same way on save. For a query, the message names the stored series key and the range being generated, for example `... App\Models\Meeting [1] generates more than 1000 occurrences between 2027-03-01T00:00:00+00:00 and 2034-01-01T00:00:01+00:00; ...`.

**Fix:** narrow the rule (a lower frequency, `COUNT` or `UNTIL`), give the query a nearer upper bound, or raise `groundhog.max_occurrences_per_series`.

**Cause: a read beyond [max_materialization_ahead](Configuration#max_materialization_ahead).** A query would have to extend the stored index of an infinite rule past now plus the ceiling. Assuming today is 1 March 2026 and the ceiling is `'P2Y'`, with a daily series that never ends:

```php
Meeting::where('starts_at', '<', '2030-06-01 00:00:00')->count();
// => OccurrenceLimitExceeded: Querying App\Models\Meeting occurrences up to 2030-06-01T00:00:01+00:00 requires generating beyond the materialisation ceiling 2028-03-01T00:00:00+00:00; constrain the query or raise groundhog.max_materialization_ahead.
```

The first part of the message shows the model's morph class. That is the class name unless you use a morph map.

**Fix:** constrain the query to a nearer upper bound, or raise `groundhog.max_materialization_ahead`. Queries that need no new occurrences, such as those that touch only finite rules, are never rejected by the ceiling.

## IncompatibleEloquentBuilder

**Extends:** `LogicException`

A recurring model declares a custom Eloquent builder that does not extend `BoysFromTheFactory\Groundhog\Query\RecurringBuilder`. Groundhog rejects it because a plain builder would silently lose the stored-row key lookups and bulk writes. It is thrown the first time the model builds a query, for example on `Meeting::query()`:

```php
use Illuminate\Database\Eloquent\Builder;

class MeetingBuilder extends Builder {}

// Meeting declares MeetingBuilder through #[UseEloquentBuilder] or newEloquentBuilder()
Meeting::query();
// => IncompatibleEloquentBuilder: The Eloquent builder App\Models\MeetingBuilder of recurring model App\Models\Meeting must extend BoysFromTheFactory\Groundhog\Query\RecurringBuilder.
```

**Fix:** extend `RecurringBuilder` instead of `Builder`:

```php
use BoysFromTheFactory\Groundhog\Query\RecurringBuilder;

class MeetingBuilder extends RecurringBuilder {}
```

See [Custom Eloquent builders](Declaring-Recurring-Models#custom-eloquent-builders).
