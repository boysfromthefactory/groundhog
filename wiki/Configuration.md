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

## max_occurrences_per_series

- **Type:** int (must be at least 1)
- **Default:** `50000`

This is the most occurrences that one series may generate for one query, or on save. A rule or query that needs more throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded) and stops before it uses unbounded time or memory. It applies in two places:

- **On save.** A finite rule is generated in full, and an infinite rule up to now plus the horizon. If that is over the limit, the save fails and nothing is stored. With the defaults, a `FREQ=MINUTELY` series is rejected on save.
- **On every query.** Each query generates each series' occurrences inside its own window only. If one series has more than the limit in that window, the query throws.

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

A query fails in the same way when the window it asks for holds too many occurrences of one series. Only occurrences inside the query's window count, so a narrow window far in the future works. Take a daily series starting 1 March 2026, saved with the default limit, then lower the limit to 1000. Assuming today is 1 March 2026:

```php
config(['groundhog.max_occurrences_per_series' => 1000]);

Meeting::where('starts_at', '<', '2034-01-01 00:00:00')->count();
// => throws OccurrenceLimitExceeded (more than 1000 daily occurrences before 2034)

Meeting::whereBetween('starts_at', ['2040-01-01 00:00:00', '2040-01-31 23:59:59'])->count(); // => 31
```

Raise the value if your rules really are that dense. Otherwise narrow the rule or the query's window.

## Invalid values

`horizon` must be an ISO-8601 duration, and `max_occurrences_per_series` must be a positive integer. Otherwise the first query or save that reads the value throws an `InvalidArgumentException`, for example: `Configuration value groundhog.horizon must be an ISO-8601 duration such as P1Y.`
