This page lists every key in Groundhog's config file: its type, default, effect, and an example change. Read it if you need to change how far open-ended rules expand or how much work one query may do. The defaults suit most applications.

## Publishing the config

Groundhog works without a published config file. To change a value, publish the file to `config/groundhog.php`:

```bash
php artisan vendor:publish --tag="groundhog-config"
```

The published file is a copy of [config/groundhog.php](https://github.com/boysfromthefactory/groundhog/blob/master/config/groundhog.php):

```php
return [
    'horizon' => 'P1Y',
    'max_occurrences_per_series' => 50000,
    'max_materialization_ahead' => 'P10Y',
];
```

You can also override a value at runtime with `config([...])`, for example in a test.

## horizon

- **Type:** ISO-8601 duration string (`'P1Y'`, `'P6M'`, `'P1M'`, ...)
- **Default:** `'P1Y'`

A rule without `COUNT` or `UNTIL` never ends. When a query gives no upper bound on the start or end column, Groundhog includes that rule's occurrences only up to this distance:

- after the query's lower start bound, if the query has one;
- otherwise after now.

Finite rules (with `COUNT` or `UNTIL`) are never cut off by the horizon. A query with an upper bound, such as `whereBetween('starts_at', [...])`, ignores the horizon.

Example: with a one-month horizon, a daily series that starts on 1 March 2026 has 31 occurrences in an unconstrained query, assuming today is 1 March 2026:

```php
config(['groundhog.horizon' => 'P1M']);

Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-01 09:00:00',
    'ends_at' => '2026-03-01 10:00:00',
    'recurrence_rule' => 'FREQ=DAILY',
]);

Meeting::count(); // => 31 (1 to 31 March)
```

With the default `'P1Y'`, the same count is 365.

When a series is saved, Groundhog also builds its stored occurrence index up to now plus the horizon. See [The occurrence index](How-It-Works#the-occurrence-index).

## max_occurrences_per_series

- **Type:** int (must be at least 1)
- **Default:** `50000`

This is the most occurrences that one generation pass may produce for one series. A rule or query that needs more throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded) and stops before it uses unbounded time or memory. It applies in two places:

- **On save.** A finite rule is generated in full, and an infinite rule up to now plus the horizon. If that pass is over the limit, the save fails and nothing is stored. With the defaults, a `FREQ=MINUTELY` series is rejected on save.
- **On a far query.** A query whose upper bound lies beyond the stored index generates the missing occurrences in one pass per series. If that pass is over the limit, the query throws and the index is not extended.

Example: a lower limit rejects a finite rule with 11 occurrences:

```php
config(['groundhog.max_occurrences_per_series' => 10]);

Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=DAILY;COUNT=11',
]);
// => throws OccurrenceLimitExceeded; no meeting and no rule are stored
```

A far query fails in the same way. Take a daily series starting 1 March 2026, saved with the default limit, then lower the limit to 1000. Assuming today is 1 March 2026:

```php
config(['groundhog.max_occurrences_per_series' => 1000]);

Meeting::where('starts_at', '<', '2034-01-01 00:00:00')->count();
// => throws OccurrenceLimitExceeded (more than 1000 new daily occurrences are needed)
```

Raise the value if your rules really are that dense. Otherwise narrow the rule or add an upper bound to the query.

## max_materialization_ahead

- **Type:** ISO-8601 duration string
- **Default:** `'P10Y'`

Reads extend the stored occurrence index when they need occurrences it does not hold yet. This value limits how far after now a single read may extend it, so request input such as `?until=2999-01-01` cannot trigger unbounded writes. A query that would need occurrences beyond `now + max_materialization_ahead` throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded).

The ceiling applies only when generation is needed. If no infinite rule of the model would have to be extended, a query with a far upper bound works.

Example, assuming today is 1 March 2026:

```php
config(['groundhog.max_materialization_ahead' => 'P2Y']); // ceiling: 1 March 2028

// A daily series with no end
Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-01 09:00:00',
    'ends_at' => '2026-03-01 10:00:00',
    'recurrence_rule' => 'FREQ=DAILY',
]);

Meeting::where('starts_at', '<', '2030-06-01 00:00:00')->count();
// => throws OccurrenceLimitExceeded; no occurrence after 1 March 2027 is stored
```

With only finite rules and plain records, the same kind of query succeeds:

```php
config(['groundhog.max_materialization_ahead' => 'P2Y']);

// The Standup series with COUNT=2, plus one plain record
Meeting::create([
    'title' => 'Standup',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2',
]);
Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

Meeting::where('starts_at', '<', '2100-01-01 00:00:00')->count(); // => 3
```

To query further ahead, raise the value or give the query a nearer upper bound.

## Invalid values

`horizon` and `max_materialization_ahead` must be ISO-8601 durations, and `max_occurrences_per_series` must be a positive integer. Otherwise the first query or save that reads the value throws an `InvalidArgumentException`, for example: `Configuration value groundhog.horizon must be an ISO-8601 duration such as P1Y.`
