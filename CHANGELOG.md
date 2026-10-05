# Changelog

All notable changes to `Groundhog` will be documented in this file.

## Unreleased

### Changed

- Occurrences are generated per query and never stored. A query loads every series of the model
  type, expands each rule only for the query's time window and passes the rows to the database as
  one JSON parameter (SQLite `json_each`, MySQL `JSON_TABLE`, PostgreSQL `json_array_elements`).
  Far windows work: there is no read ceiling any more.
- `groundhog.max_occurrences_per_series` now bounds the occurrences one series generates for one
  query (only occurrences inside the window count); the check on save is unchanged.
- Changing only a series' duration no longer rebuilds anything; occurrence ends change on the
  next query.
- Performance: 1,000 daily series over a one-year window, page of 25 plus total, now takes
  4.8–7.4 s on SQLite, 7.0–7.9 s on MySQL 8.4 and 6.8–7.1 s on PostgreSQL 18; cost grows with
  series × occurrences in the window on every query.

### Removed

- Table `groundhog_occurrences` and column `groundhog_recurrences.materialized_until`.
- Configuration `groundhog.max_materialization_ahead`; queries have no read ceiling.

### Added

- Migration `drop_groundhog_occurrence_index`, published with the `groundhog-migrations` tag. It
  drops the 0.1 occurrence table and column if present and is a no-op on fresh installs. To
  upgrade from 0.1, run `php artisan vendor:publish --tag="groundhog-migrations"` and then
  `php artisan migrate`.
- `RecurrenceNotSupported` is thrown by expanded queries on drivers other than SQLite, MySQL and
  PostgreSQL.

## v0.1.0 - 2026-10-05

### Added

- `HasRecurrence` trait: a model becomes recurring by using the trait; a record becomes a series
  when a `recurrence_rule` (RFC 5545 RRULE string, php-rrule parts array or `RRule\RRule`) is
  assigned. Start and end columns default to `starts_at`/`ends_at` and are configurable with
  `RECURRENCE_STARTS_AT`/`RECURRENCE_ENDS_AT`.
- Ordinary Eloquent reads (get, first, count, exists, aggregates, paginate, simplePaginate,
  chunk, lazy, cursor, whereHas, joins, eager loading) return one non-stored instance per
  occurrence alongside plain records, with stable ordering for ties.
- `recurrence_rule` cast exposing the stored rule as an `RRule\RRule` (`humanReadable()` and the
  full `RRuleInterface`).
- Saving, updating or incrementing an occurrence stores it as an exception; deleting one cancels
  it. Series edits propagate to occurrences; rule or start changes detach exceptions and discard
  cancellations; deleting or soft-deleting a series removes or hides its occurrences and
  exceptions.
- Key lookups, route model binding and query-level writes act on stored rows only;
  `withoutOccurrences()` gives any query that view.
- Configuration `groundhog.horizon` (`P1Y`), `groundhog.max_occurrences_per_series` (`50000`) and
  `groundhog.max_materialization_ahead` (`P10Y`).
- Migration creating `groundhog_recurrences`, `groundhog_occurrences` and `groundhog_exclusions`.
- Exceptions `InvalidRecurrenceRule`, `RecurrenceNotSupported`, `OccurrenceLimitExceeded` and
  `IncompatibleEloquentBuilder`.

### Documentation

- [GitHub wiki](https://github.com/boysfromthefactory/groundhog/wiki) with an overview, quick
  start, usage guide for every feature, configuration, error and limitation reference, and
  maintainer publishing guide. Sources live in `wiki/`.
