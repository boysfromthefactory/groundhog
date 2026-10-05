# Implementation Plan: Groundhog — Recurring Eloquent Models

**Branch**: `001-recurring-eloquent-models` | **Date**: 2026-10-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-recurring-eloquent-models/spec.md`

## Summary

Groundhog is a Laravel 13 package, scaffolded from `spatie/package-skeleton-laravel`. It lets any
Eloquent model recur by adding the `HasRecurrence` trait.
- **Rule storage**: A series' rule is stored polymorphically in `groundhog_recurrences` and
  exposed through the `recurrence_rule` cast as an `RRule\RRule` (`humanReadable()` and the
  full `RRuleInterface`).
- **Read path**: A global scope swaps the model's `FROM` for a derived table that is aliased to
  the model's table name. In that table, series rows are replaced by their occurrences, excluded
  starts are removed, and exceptions and plain rows pass through. Every standard Eloquent read,
  including pagination, counts, aggregates, `whereHas` and joins, therefore runs unchanged in SQL
  and returns hydrated, non-stored instances.
- **Per-query expansion**: Each query loads the model type's series, expands each rule with
  php-rrule for the query's window only, and passes the rows to SQL as one JSON parameter. Nothing
  derived from a rule is stored (FR-002). A stored index (0.1) was withdrawn on 2026-10-05.
- **Write path**: Saving a virtual occurrence inserts an exception row and an exclusion in one
  transaction. Deleting one records a cancellation.

## Technical Context

**Language/Version**: PHP ^8.4 (skeleton floor; Laravel 13 needs ^8.3)

**Primary Dependencies**: `illuminate/contracts ^13.0` (Laravel 13.34 latest),
`rlanvin/php-rrule ^3.0`, `spatie/laravel-package-tools ^1.16`; suggest `ext-intl`

**Storage**: The host app's database. Supported: SQLite 3.35+ (with JSON functions), MySQL 8.4,
PostgreSQL 14+ (CI: SQLite, MySQL 8.4, PostgreSQL 17). Two package tables (see
[data-model.md](data-model.md)). Other drivers, including SQL Server, throw
`RecurrenceNotSupported` on expanded queries.

**Testing**: Pest 4 + Orchestra Testbench 11 (workbench models `Meeting`, `Shift`), Larastan
level 9, Pint

**Target Platform**: Laravel 13 applications (any OS running PHP 8.4+)

**Project Type**: Library (Composer package)

**Performance Goals**: SC-003 (relaxed 2026-10-05): a page of 25 plus total for 1,000 daily
series over a 1-year window measured at SQLite 3.45 4.8–7.4 s, MySQL 8.4 7.0–7.9 s, PostgreSQL 18
6.8–7.1 s (Apple-silicon development machine, PHP 8.5, local database); cost grows with the
occurrences in the window on every query

**Constraints**:
- No new query syntax.
- Deterministic results that do not depend on query history.
- Nothing derived from a rule is stored (FR-002).
- Each series generates at most `max_occurrences_per_series` occurrences per query or save
  (SC-005).
- Multi-record writes are atomic (FR-025).

**Scale/Scope**: ~10 production classes, 2 tables, 1 config file; roughly 365k occurrences
generated per query for 1,000 daily series over a one-year window

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Pre-research | Post-design |
|---|---|---|---|
| I. Readable code | One responsibility per class; public API documents inputs/outputs/errors | ✅ planned | ✅ Each class in the structure below has a one-line responsibility; [public-api.md](contracts/public-api.md) defines inputs, outputs and exceptions |
| II. Test-first | Acceptance tests from spec scenarios written before implementation; clock and DB isolated | ✅ | ✅ [quickstart.md](quickstart.md) maps every US scenario to a feature test; `travelTo()` for horizon; `RefreshDatabase` per test; SC-003 is measured by `composer bench`, a script rather than a test because a wall-clock assertion cannot be deterministic; timings go in the PR |
| III. Meaningful tests | Behaviour via public API only, no internals pinned | ✅ | ✅ Tests assert query results, stored rows and exceptions only; the derived SQL text is never asserted |
| IV. Simplicity | Abstractions need ≥ 2 consumers; dependencies justified | ⚠️ | ⚠️ Justified in Complexity Tracking (JSON occurrence rows, custom builder, Model overrides) |
| V. Clean change | No skeleton leftovers; docs and changelog updated | ✅ | ✅ Generated `Skeleton` class, facade, command and views are removed; README and CHANGELOG are written with the feature |
| Quality standards | Formatter, static analysis, lockfile, input validation | ✅ | ✅ Pint, Larastan 9 in CI; rule input validated at the cast boundary (FR-006) |

Dependency justification (Principle IV):
- **`rlanvin/php-rrule`**: Required by the user. MIT licence, v3.0.0 released 2026-07. It
  replaces an in-repo RFC 5545 engine, which would be far larger and riskier.
- **`spatie/laravel-package-tools`**: Comes from the mandated skeleton. Replaces hand-written
  provider boilerplate for config and migration publishing.

Gate result: **PASS** with the documented exceptions below.

## Project Structure

### Documentation (this feature)

```text
specs/001-recurring-eloquent-models/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── public-api.md
│   └── query-semantics.md
└── tasks.md            # /speckit.tasks
```

### Source Code (repository root)

```text
composer.json                         # boysfromthefactory/groundhog; L13-only constraints
config/groundhog.php                  # horizon, max_occurrences_per_series
database/migrations/create_groundhog_tables.php.stub
database/migrations/drop_groundhog_occurrence_index.php.stub  # upgrade from 0.1; no-op on fresh installs
src/
├── GroundhogServiceProvider.php      # registers config + migration (package-tools)
├── Concerns/HasRecurrence.php        # opt-in trait: relations, predicates, pending rule, save/delete/update interception
├── Casts/AsRecurrenceRule.php        # recurrence_rule ⇄ RRule; delegates to the trait
├── Rules/RuleFactory.php             # input (string|array|RRule) → validated RRule with DTSTART/TZ
├── Models/Recurrence.php             # groundhog_recurrences row
├── Query/OccurrenceScope.php         # derived FROM, cap, key-pinned bypass, tie-break ordering, identity columns
├── Query/RecurringBuilder.php        # bulk writes on stored rows, keyed-iteration guard, stored-row identity; withoutOccurrences()
├── Query/TimeWindow.php              # derives lower/upper start bounds from where clauses
├── Query/IdentifierSql.php           # Expression whose identifiers the grammar wraps (no raw SQL strings)
├── Rules/OccurrenceGenerator.php     # expands one series for a window with php-rrule, limit guard
├── Query/OccurrenceRows.php          # loads the model type's series, encodes occurrences as one JSON parameter, per-driver JSON table
├── Index/ExceptionLedger.php         # exclusions: record exception, cancel, release, reset, resolve links
├── Collections/OccurrenceCollection.php  # dictionary keyed by occurrence identity
├── Support/RecurrenceColumns.php     # start/end columns and values, typed for code outside the trait
└── Exceptions/{InvalidRecurrenceRule,RecurrenceNotSupported,OccurrenceLimitExceeded,IncompatibleEloquentBuilder}.php
stubs/RRuleInterface.stub             # PHPStan generics for php-rrule (no suppressions at level 9)
testbench.yaml                        # workbench:build (quickstart)
tests/
├── Pest.php, TestCase.php, ArchTest.php
├── Feature/                          # one file per user story + edge cases (quickstart table)
│   ├── QueryOccurrencesTest.php      # US1
│   ├── PaginationAndAggregatesTest.php  # US2
│   ├── EditOccurrenceTest.php        # US3
│   ├── SeriesLifecycleTest.php       # US4
│   ├── RecurrenceRuleCastTest.php    # FR-004/006/028
│   └── EdgeCasesTest.php             # DST, limits, keys, custom builders, keyed iteration
└── Unit/{TimeWindowTest,RuleFactoryTest}.php
workbench/
├── app/Models/{Meeting,Shift,Room,Attendee}.php
└── database/migrations/              # package stub (required), rooms, meetings (soft deletes, capacity), shifts (no end column), attendees
benchmarks/pagination.php             # composer bench (SC-003); measurement script, not a test
.github/workflows/run-tests.yml       # matrix: sqlite, mysql 8.4, pgsql 17 × PHP 8.4/8.5 × L13
```

**Structure Decision**: Single Composer package following the skeleton's layout (`src/`,
`config/`, `database/`, `tests/`, `workbench/`). Classes are grouped by the Eloquent seam they
plug into (Concerns, Casts, Query, Rules, Index). `Index/ExceptionLedger.php` holds the only
persistence logic, so the query and write paths share one implementation of the exclusion rules;
nothing derived from a rule is persisted.

## Phase 0 / Phase 1 outputs

- [research.md](research.md): R1–R10, all decisions with rationale and rejected alternatives.
- [data-model.md](data-model.md): tables, invariants, state transitions.
- [contracts/public-api.md](contracts/public-api.md): trait, cast, builder, exceptions, config.
- [contracts/query-semantics.md](contracts/query-semantics.md): row-set rule and operation matrix.
- [quickstart.md](quickstart.md): gates, the 27 validation scenarios, and the benchmark.

Spec amendments made during planning (recorded in the spec's *Planning input* section):
- FR-002 permitted a derived index (withdrawn 2026-10-05).
- FR-028 added (RRule cast).
- Relationship assumption corrected (virtual rows have a `null` key).
- New "runaway generation" edge case.
- Platform set to Laravel 13.

Spec amendments from the 2026-10-05 analysis (recorded in the spec's *Analysis remediation*
section):
- EXDATE/EXRULE out of scope; exclusions come only from cancellations and exceptions (FR-005).
- Key-based chunking rejected while expanded (FR-007).
- Materialisation ceiling for reads (FR-008; withdrawn 2026-10-05); dense rules rejected on save
  (edge cases).
- Tie-break ordering only for ordered, ungrouped, non-distinct queries (FR-016).
- Rule/start changes reset all exclusions; identical re-assignment is a no-op (FR-022).
- SC-003 reference machine and built-index condition (superseded 2026-10-05).

Spec amendment 2026-10-05 (recorded in the spec's *Amendment 2026-10-05* section):
- The derived occurrence index and the read ceiling are withdrawn; FR-002 forbids storing
  anything derived from the rule. Occurrences are generated per query.
- SC-003 relaxed to the measured per-query expansion times.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Per-query occurrence rows passed as one JSON parameter and unpacked by the database's JSON table function (`json_each` / `JSON_TABLE` / `json_array_elements`) | FR-002 forbids storing occurrences, while FR-009 requires arbitrary SQL constraints to see them as rows | A stored derived index (0.1) was fast but stored rule-derived data the user never approved (withdrawn 2026-10-05); one bind parameter per value hits bind limits; temp tables add writes per query; recursive CTEs cannot express full RRULE |
| Custom Eloquent builder (`RecurringBuilder`) with overrides for bulk writes, key lookups, keyed iteration (`chunkById`, `chunkByIdDesc`, `eachById`, `lazyById`, `lazyByIdDesc`) and `getModels()` | Bulk writes and key lookups pass through `applyScopes()` and would target the derived table (SQL error or wrong rows); key-based iteration pages by `key > last` and would silently skip virtual rows (null key); stored rows loaded without the occurrence scope need their identity attributes from one query per result set, before eager loading (FR-012, FR-015) | Global scope alone cannot tell reads from writes; a base `Query\Builder` swap misses nested `whereExists` compiled directly by the grammar; documenting "don't use `chunkById`" leaves a silent data-loss trap; resolving identity per model on first access costs one query per row and leaves `toArray()` and `with('series')` dependent on call order |
| Trait overrides of `Model` internals (`save`, `delete`, `update`, `incrementOrDecrement`, `newFromBuilder`, `getAttributesForInsert`, `getDirtyForUpdate`, `newCollection`, `newEloquentBuilder`, `resolveRouteBindingQuery`) | FR-017/019/024/025 and safety: Eloquent's default for non-existing models is `update()` → false, and `increment()` → table-wide update | Event listeners are skipped by `saveQuietly()`/`withoutEvents()` and cannot strip attributes or change `exists`; requiring users to call package methods violates "no special syntax" |
| `OccurrenceCollection` | Eloquent's collection set operations key by `getKey()`; null keys collapse all occurrences into one | Documenting "don't use unique/merge" leaves a silent data-loss trap |
