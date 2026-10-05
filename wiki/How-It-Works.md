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

The derived table is built from three package tables, created by the package migration:

| Table | Holds |
|---|---|
| `groundhog_recurrences` | One row per series: the rule text, its time zone, whether it is infinite, and how far its index is filled (`materialized_until`) |
| `groundhog_occurrences` | The occurrence index: `recurrence_id`, `starts_at`, `ends_at` |
| `groundhog_exclusions` | Exception links and cancellations |

## The occurrence index

Running a rule engine on every query was too slow for the performance target: it measured 5.3 seconds against a 1-second target. Groundhog therefore stores each rule's raw expansion in `groundhog_occurrences`, with a primary key on `(recurrence_id, starts_at)`. Each series' occurrences are stored together in start order. The rule stays the single source of truth, and the index is always derived from the rule, the series start and its duration.

- **On save**, a finite rule (with `COUNT` or `UNTIL`) is indexed completely. An infinite rule is indexed up to now plus the [horizon](Configuration#horizon). For the Standup series saved on 1 March 2026, that is every Monday up to 1 March 2027.
- **When the rule, the start or the duration changes**, the series' index is deleted and rebuilt.
- **On read**, the index is extended lazily. Before the query runs, Groundhog checks which infinite rules of the model are indexed only up to a date before the query's upper bound (or, with no upper bound, the horizon end). Each of those rules is extended in its own transaction with a row lock, so concurrent readers extend each range only once.

Occurrence times are stored in the application time zone, in the model's date format, just like the start column. So the join and your range filters compare like with like.

## Exclusions

Edits and cancellations never change the index. They are recorded in `groundhog_exclusions`, one row per affected occurrence, keyed by `(recurrence_id, original_starts_at)`:

- **Exception link:** `exception_id` points to the stored record that replaces the occurrence. Saving the 16 March occurrence creates this link.
- **Cancellation:** `exception_id` is `null`. Deleting the 23 March occurrence creates one. When an exception is hard-deleted, its link becomes a cancellation, so the occurrence stays cancelled.

The derived table anti-joins the exclusions. An occurrence with an exclusion row is left out, and its exception, if any, appears as an ordinary stored row with the identity columns filled in from the link. The unique key on `(recurrence_id, original_starts_at)` makes a second exception for the same occurrence fail and roll back. When the rule or the start of a series changes, its exclusions are deleted: exceptions become plain records and cancellations are discarded. See [Changing the rule or the start](Managing-a-Series#changing-the-rule-or-the-start).

## Limits

Three settings bound the work one save or query can cause:

- **[horizon](Configuration#horizon)** (`'P1Y'`): when a query has no upper bound on the start or end column, infinite rules are expanded only up to this distance after the query's lower start bound, or after now. This is a SQL predicate, not a property of what has been indexed so far, so a query's result does not depend on which queries ran before it.
- **[max_occurrences_per_series](Configuration#max_occurrences_per_series)** (`50000`): the most occurrences one generation pass may produce for one series, on save or when extending the index. Groundhog asks the rule engine for one more than the limit to detect an over-long pass without generating all of it.
- **[max_materialization_ahead](Configuration#max_materialization_ahead)** (`'P10Y'`): how far after now a read may extend the index. It is checked only when some rule actually needs extending.

Going over the last two throws [OccurrenceLimitExceeded](Errors#occurrencelimitexceeded).

## Key-pinned queries

A virtual occurrence has no key, so a query that fixes the primary key can only mean stored rows. If a top-level, AND-ed `where` compares the key with `=`, `in` or another column (`find`, `findMany`, `whereKey`, `destroy`, a belongs-to relation, `whereHas` from another model), Groundhog skips expansion and reads the stored table directly. `Meeting::find($series->id)` therefore returns the stored series, starting on 2 March 2026 at 09:00, not one of its occurrences. A key condition inside an `orWhere` proves nothing, so such a query stays expanded. `withoutOccurrences()` gives the same stored-row view to any query. See [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes).

## Performance

The target is measured with 1,000 daily series over a one-year window (365,000 occurrences), loading a page of 25 with its total count. Pages 1, 200 and the last page were each run three times, with a built index:

| Database | Measured | Budget |
|---|---|---|
| PostgreSQL | 0.33–0.65 s | 1 s |
| MySQL 8.4 | 0.95–1.11 s | 2 s |
| SQLite | 0.14–2.01 s | 3 s |

On SQLite the last page is the slowest, because it needs a full sort of 365,000 rows.

Narrow time bounds make queries faster. Groundhog copies a query's own bounds on the start column onto the join with the index, so even databases that do not push predicates into derived tables read only the matching range of each series. Queries without an upper bound are still capped by the horizon.
