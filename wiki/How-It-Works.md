This page explains how Groundhog turns a series into rows that ordinary Eloquent queries can filter, join and paginate, and what that costs. Read it if you want to predict query behaviour and performance, or debug the generated SQL. You do not need it to use the package.

## Expansion

Groundhog adds a global scope to every recurring model. When a query runs, the scope replaces the model's table with a derived table (a subquery) that has the same name. For `Meeting`, the query reads from `(select ...) as meetings` instead of `meetings`.

In the derived table:

- every plain record and every stored exception appears once, unchanged;
- every series row is replaced by one row per occurrence. The row has the series' column values, the occurrence's own `starts_at` and `ends_at`, and a `null` primary key;
- the series row itself does not appear;
- every row has two identity columns: `groundhog_series_key` and `groundhog_original_starts_at` (see [Identifying Occurrences](Identifying-Occurrences)). Groundhog adds them to the select list of a partial `select()` so that occurrences returned by it can still be saved and deleted.

Because the derived table has the model's table name, your own SQL runs on it without any change: `where` constraints, `whereHas`, joins, ordering, aggregates and the count query behind `paginate()`. For example, `Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])->get()` filters the occurrence rows directly. It returns the Standup occurrences on 2, 9, 16, 23 and 30 March.

If a query has an `orderBy`, Groundhog appends `groundhog_series_key`, `groundhog_original_starts_at` and the primary key as tie-breakers, so pages do not overlap or skip rows. Grouped, distinct and union queries are left alone, because databases reject ORDER BY columns outside the grouped or selected set.

Two package tables, created by the package migration, hold what Groundhog stores:

| Table | Holds |
|---|---|
| `groundhog_recurrences` | One row per series: the rule text, its time zone and whether it is infinite |
| `groundhog_exclusions` | Exception links and cancellations |

Nothing derived from a rule is stored. Occurrences are generated for each query.

## Generating occurrences per query

When a query on a recurring model is built, the global scope:

1. loads every series of the model type, with one query that joins the model's table to `groundhog_recurrences`;
2. expands each rule only for the query's time window;
3. passes all occurrence rows to the database as one JSON parameter of `[recurrence_id, starts_at, ends_at]` rows, which the database unpacks with its JSON table function: `json_each` on SQLite, `JSON_TABLE` on MySQL, `json_array_elements` on PostgreSQL. A single parameter avoids the databases' bind-parameter limits.

The derived table is then a `UNION ALL` of two parts:

- the stored rows that are not series: plain records, and exceptions with the identity of the occurrence they replace;
- the occurrence rows joined to their series by primary key, minus the excluded starts (an anti-join on `groundhog_exclusions`).

Each series' generation window is:

- **lower:** the query's lower start bound. If the query bounds the end column from below, the lower bound is raised to that end bound minus the series' duration, so occurrences that started earlier but are still running are included.
- **upper:** the query's upper start bound, inclusive; an upper bound on the end column also caps the start. Without an upper bound, an infinite rule stops at the [horizon](Configuration#horizon) and a finite rule expands to its end.

Results never depend on earlier queries. Changing a series' rule, start or duration takes effect on the next query. Occurrence times are generated in the application time zone, in the model's date format, just like the start column, so your range filters compare like with like.

`paginate()` builds two queries, the count and the page, so it generates twice. Expansion needs a JSON table function, so any expanded query on a driver other than SQLite, MySQL or PostgreSQL throws [RecurrenceNotSupported](Errors#recurrencenotsupported). SQLite has its JSON functions built in since 3.38; earlier builds need JSON1 compiled in, which PHP's bundled SQLite has.

## Exclusions

Edits and cancellations never change the rule. They are recorded in `groundhog_exclusions`, one row per affected occurrence, keyed by `(recurrence_id, original_starts_at)`:

- **Exception link:** `exception_id` points to the stored record that replaces the occurrence. Saving the 16 March occurrence creates this link.
- **Cancellation:** `exception_id` is `null`. Deleting the 23 March occurrence creates one. When an exception is hard-deleted, its link becomes a cancellation, so the occurrence stays cancelled.

The derived table anti-joins the exclusions. An occurrence with an exclusion row is left out, and its exception, if any, appears as an ordinary stored row with the identity columns filled in from the link. The unique key on `(recurrence_id, original_starts_at)` makes a second exception for the same occurrence fail and roll back. When the rule or the start of a series changes, its exclusions are deleted: exceptions become plain records and cancellations are discarded. See [Changing the rule or the start](Managing-a-Series#changing-the-rule-or-the-start).

## Limits

Two settings bound the work one save or query can cause:

- **[horizon](Configuration#horizon)** (`'P1Y'`): when a query has no upper bound on the start or end column, infinite rules are expanded only up to this distance after the query's lower start bound, or after its lower end bound, or after now.
- **[max_occurrences_per_series](Configuration#max_occurrences_per_series)** (`50000`): the most occurrences one series may generate for one query (only occurrences inside the window count), or on save (a finite rule in full, an infinite rule up to now plus the horizon). Groundhog asks the rule engine for one more than the limit to detect an over-long pass without generating all of it.

Going over the limit throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded).

## Key-pinned queries

A virtual occurrence has no key, so a query that fixes the primary key can only mean stored rows. If a top-level, AND-ed `where` compares the key with `=`, `in` or another column (`find`, `findMany`, `whereKey`, `destroy`, a belongs-to relation, `whereHas` from another model), Groundhog skips expansion and reads the stored table directly. `Meeting::find($series->id)` therefore returns the stored series, starting on 2 March 2026 at 09:00, not one of its occurrences. A key condition inside an `orWhere` proves nothing, so such a query stays expanded. `withoutOccurrences()` gives the same stored-row view to any query. See [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes).

## Performance

Because nothing derived from a rule is stored, every query pays for generating its occurrences. The cost grows with the number of series times the occurrences each has in the query's window. Measured with 1,000 daily series over a one-year window (365,000 occurrences), loading a page of 25 with its total count, pages 1, 200 and the last page, on an Apple-silicon development machine with PHP 8.5 and local databases (`composer bench`):

| Database | Measured |
|---|---|
| SQLite 3.45 | 4.8–7.4 s |
| MySQL 8.4 | 7.0–7.9 s |
| PostgreSQL 18 | 6.8–7.1 s |

Most of that time is in PHP. Generating and encoding the 365,000 occurrences takes about 1.9 seconds per query (the rule engine alone about 1.3 seconds), and `paginate()` runs two queries, the count and the page, so it generates twice. The count query's SQL alone takes 0.4 s on SQLite, 1.4 s on MySQL and 1.2 s on PostgreSQL. The original targets of 1, 2 and 3 seconds are not met; they were given up in exchange for storing nothing.

Narrow time bounds make queries faster: a series only emits the occurrences inside the query's window, so a one-month window produces about a twelfth of the rows of a one-year window. The rule engine still steps through every occurrence between the series start and the window, so a far window on a dense, old series costs more than its row count suggests. Queries without an upper bound are still capped by the horizon for infinite rules.
