# Contract: Query Semantics

The rule that governs everything (FR-009): **a query on a recurring model returns exactly what the
same query would return if every non-excluded occurrence (within the window/horizon) were a
stored row carrying the series' attributes, its own start/end, a `null` key and its identity
attributes. Exceptions and plain records are ordinary rows, and series rows are absent.**

## Row set seen by a query

| Source | Included when | Key | start/end |
|---|---|---|---|
| Plain record | always | own | own |
| Exception | its series is not trashed (when soft-deletable) | own | own (may be moved) |
| Virtual occurrence | rule generates it, not excluded, `start < cap` for infinite rules without an upper bound | `null` | generated |
| Series record | never (use key lookup or `withoutOccurrences()`) | — | — |

## Operation matrix

| Eloquent operation | Behaviour |
|---|---|
| `get`, `first`, `sole`, `firstWhere`, `value`, `pluck`, `cursor`, `lazy`, `chunk`, `each` | over the row set |
| `chunkById`, `chunkByIdDesc`, `eachById`, `lazyById`, `lazyByIdDesc` | rejected (`RecurrenceNotSupported`) while expanded; use `chunk`/`lazy` or `withoutOccurrences()` |
| `count`, `exists`, `doesntExist`, `min`, `max`, `sum`, `avg` | over the row set |
| `paginate` (total = row-set count), `simplePaginate` | over the row set |
| `cursorPaginate` | out of scope for v1 (spec assumption); not guaranteed |
| `where*`, `orWhere*`, `whereHas`, joins, `orderBy*`, `latest`, `groupBy`/`having` | evaluated per row in SQL |
| relationship queries targeting the model (`$user->meetings()`) and `whereHas('meetings')` from other models | over the row set |
| `find`, `findMany`, `findOrFail`, `whereKey`, `whereKeyNot`, route model binding, `destroy`, and queries whose top-level AND-ed clauses equate the primary key with a value, list or column (e.g. `$attendee->meeting`, `Attendee::whereHas('meeting')`) | stored rows only |
| query-level `update`, `delete`, `increment`, `insert*`, `upsert` | stored rows only |
| `with()` eager loading | belongs-to relations resolve from copied values; relations keyed on the model's own key are empty on virtual rows; `series` is available |

## Ordering

When the query has at least one `orderBy` and is not grouped, distinct or a union, the scope
appends tie-breakers `groundhog_series_key`, `groundhog_original_starts_at`, primary key
(FR-016). Grouped, distinct and union queries keep only the caller's ordering, because databases
reject ORDER BY columns outside the grouped/selected set. Without any ordering, row order is
database-defined, as in plain Eloquent.

## Window derivation

See [research.md R5](../research.md#r5--deriving-the-time-window-from-ordinary-constraints-fr-008).
Summary:
- An upper bound comes from AND-ed `<`, `<=`, `=`, `between` on start, end or
  `groundhog_original_starts_at`.
- If there is no upper bound, infinite rules are capped at `(lower ?? now) + horizon`.
- A query whose window needs index rows later than `now + max_materialization_ahead` throws
  `OccurrenceLimitExceeded` before anything is written.
- Bounds hidden under `or`/raw SQL are ignored (conservative).

## Explicit column selection

When the query selects explicit columns and is neither `distinct` nor grouped, the identity
attributes are appended so returned virtual occurrences remain saveable. Otherwise, partial rows
behave like partial Eloquent selections (not reliably saveable).
