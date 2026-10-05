# Changelog

All notable changes to `Groundhog` will be documented in this file.

## Unreleased

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
