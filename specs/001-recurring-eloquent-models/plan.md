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
- **Occurrence index**: Occurrences come from a derived index (`groundhog_occurrences`) that
  php-rrule fills and the package extends lazily. Expanding on every read measured 5.3 s for the
  SC-003 volume, against a 1 s target.
- **Write path**: Saving a virtual occurrence inserts an exception row and an exclusion in one
  transaction. Deleting one records a cancellation.

## Technical Context

**Language/Version**: PHP ^8.4 (skeleton floor; Laravel 13 needs ^8.3)

**Primary Dependencies**: `illuminate/contracts ^13.0` (Laravel 13.34 latest),
`rlanvin/php-rrule ^3.0`, `spatie/laravel-package-tools ^1.16`; suggest `ext-intl`

**Storage**: The host app's database. Supported: SQLite 3.35+, MySQL 8.4, PostgreSQL 14+ (CI:
SQLite, MySQL 8.4, PostgreSQL 17). Three package tables (see [data-model.md](data-model.md)).
SQL Server is unsupported.

**Testing**: Pest 4 + Orchestra Testbench 11 (workbench models `Meeting`, `Shift`), Larastan
level 9, Pint

**Target Platform**: Laravel 13 applications (any OS running PHP 8.4+)

**Project Type**: Library (Composer package)

**Performance Goals**: SC-003: a page of 25 plus total in < 1 s for 1,000 daily series over a
1-year window (index warm)

**Constraints**:
- No new query syntax.
- Deterministic results that do not depend on query history.
- Each generation pass is bounded by `max_occurrences_per_series` (SC-005).
- Multi-record writes are atomic (FR-025).

**Scale/Scope**: ~10 production classes, 3 tables, 1 config file; roughly 365k index rows per
1,000 daily series per year

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Pre-research | Post-design |
|---|---|---|---|
| I. Readable code | One responsibility per class; public API documents inputs/outputs/errors | ✅ planned | ✅ Each class in the structure below has a one-line responsibility; [public-api.md](contracts/public-api.md) defines inputs, outputs and exceptions |
| II. Test-first | Acceptance tests from spec scenarios written before implementation; clock and DB isolated | ✅ | ✅ [quickstart.md](quickstart.md) maps every US scenario to a feature test; `travelTo()` for horizon; `RefreshDatabase` per test |
| III. Meaningful tests | Behaviour via public API only, no internals pinned | ✅ | ✅ Tests assert query results, stored rows and exceptions only; the derived SQL text is never asserted |
| IV. Simplicity | Abstractions need ≥ 2 consumers; dependencies justified | ⚠️ | ⚠️ Justified in Complexity Tracking (index table, custom builder, Model overrides) |
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
src/
├── GroundhogServiceProvider.php      # registers config + migration (package-tools)
├── Concerns/HasRecurrence.php        # opt-in trait: cast/relations/scope registration, write interception
├── Casts/AsRecurrenceRule.php        # recurrence_rule ⇄ RRule; validation; pending state
├── Rules/RuleFactory.php             # input (string|array|RRule) → validated RRule with DTSTART/TZ
├── Models/Recurrence.php             # groundhog_recurrences row
├── Query/OccurrenceScope.php         # builds the derived FROM, cap, tie-break ordering, identity columns
├── Query/RecurringBuilder.php        # stored-row semantics for key lookups and bulk writes; withoutOccurrences()
├── Query/TimeWindow.php              # derives lower/upper start bounds from where clauses
├── Index/OccurrenceIndex.php         # materialise / extend / rebuild under lock, limit guard
├── Index/ExceptionLedger.php         # exclusions: record exception, cancel, detach
├── Collections/OccurrenceCollection.php  # dictionary keyed by occurrence identity
└── Exceptions/{InvalidRecurrenceRule,RecurrenceNotSupported,OccurrenceLimitExceeded,IncompatibleEloquentBuilder}.php
tests/
├── Pest.php, TestCase.php, ArchTest.php
├── Feature/                          # one file per user story + edge cases (quickstart table)
│   ├── QueryOccurrencesTest.php      # US1
│   ├── PaginationAndAggregatesTest.php  # US2
│   ├── EditOccurrenceTest.php        # US3
│   ├── SeriesLifecycleTest.php       # US4
│   ├── RecurrenceRuleCastTest.php    # FR-004/006/028
│   └── EdgeCasesTest.php             # DST, limits, keys, bulk writes, soft deletes
├── Unit/TimeWindowTest.php
└── Benchmark/PaginationBenchmarkTest.php   # @group benchmark (SC-003), excluded by default
workbench/
├── app/Models/{Meeting,Shift,Attendee}.php
└── database/migrations/              # meetings (soft deletes), shifts (no end column), attendees
.github/workflows/run-tests.yml       # matrix: sqlite, mysql 8.4, pgsql 17 × PHP 8.4/8.5 × L13
```

**Structure Decision**: Single Composer package following the skeleton's layout (`src/`,
`config/`, `database/`, `tests/`, `workbench/`). Classes are grouped by the Eloquent seam they
plug into (Concerns, Casts, Query, Index). `Index/` holds the only persistence logic, so the
query and write paths share a single implementation of index and exclusion rules.

## Phase 0 / Phase 1 outputs

- [research.md](research.md): R1–R10, all decisions with rationale and rejected alternatives.
- [data-model.md](data-model.md): tables, invariants, state transitions.
- [contracts/public-api.md](contracts/public-api.md): trait, cast, builder, exceptions, config.
- [contracts/query-semantics.md](contracts/query-semantics.md): row-set rule and operation matrix.
- [quickstart.md](quickstart.md): gates, the 18 validation scenarios, and the benchmark.

Spec amendments made during planning (recorded in the spec's *Planning input* section):
- FR-002 now permits a derived index.
- FR-028 added (RRule cast).
- Relationship assumption corrected (virtual rows have a `null` key).
- New "runaway generation" edge case.
- Platform set to Laravel 13.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Derived occurrence index table (`groundhog_occurrences`) + lazy extension | SC-003 and FR-009: arbitrary SQL constraints must see occurrences as rows, fast | Expanding on every read took 5.3 s for 366k occurrences (measured); temp tables or inline VALUES pay the same cost and hit bind limits; recursive CTEs cannot express full RRULE |
| Custom Eloquent builder (`RecurringBuilder`) with write-method overrides | Bulk writes and key lookups pass through `applyScopes()` and would target the derived table (SQL error or wrong rows) | Global scope alone cannot tell reads from writes; a base `Query\Builder` swap misses nested `whereExists` compiled directly by the grammar |
| Trait overrides of `Model` internals (`save`, `delete`, `update`, `incrementOrDecrement`, `newFromBuilder`, `getAttributesForInsert`, `getDirtyForUpdate`, `newCollection`, `newEloquentBuilder`, `resolveRouteBindingQuery`) | FR-017/019/024/025 and safety: Eloquent's default for non-existing models is `update()` → false, and `increment()` → table-wide update | Event listeners are skipped by `saveQuietly()`/`withoutEvents()` and cannot strip attributes or change `exists`; requiring users to call package methods violates "no special syntax" |
| `OccurrenceCollection` | Eloquent's collection set operations key by `getKey()`; null keys collapse all occurrences into one | Documenting "don't use unique/merge" leaves a silent data-loss trap |
