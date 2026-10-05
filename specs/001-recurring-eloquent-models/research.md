# Phase 0 Research: Groundhog — Recurring Eloquent Models

All facts below were checked on 2026-10-04 against Packagist, the upstream repositories and the
installed sources of `illuminate/database` v13.34.0 and `rlanvin/php-rrule` v3.0.0 (probe project
outside the repo). No `NEEDS CLARIFICATION` items remain.

## R1 — Platform and scaffold

- **Decision**: Scaffold from `spatie/package-skeleton-laravel` (main). Require `php: ^8.4`
  (the skeleton's floor), `illuminate/contracts: ^13.0`, `spatie/laravel-package-tools: ^1.16`.
  Dev: `orchestra/testbench: ^11.0`, `pestphp/pest: ^4.0` (+ `pest-plugin-laravel`,
  `pest-plugin-arch`), `larastan/larastan: ^3.0`, `laravel/pint: ^1.14`. Package
  `boysfromthefactory/groundhog`, namespace `BoysFromTheFactory\Groundhog`.
- **Rationale**: Latest Laravel is v13.34.0 (2026-09-29, requires PHP ^8.3). The user asked for the
  latest version only, so the skeleton's `^11.0||^12.0||^13.0` and testbench `^9||^10` ranges
  are narrowed to 13 / 11. The PHP floor stays at the skeleton's ^8.4 (stricter than Laravel's).
- **Skeleton cleanup**: The generated `Skeleton` class, facade, example command, view folder and
  their tests are removed; unused generated code is dead code (Constitution V). Kept: service
  provider (via `PackageServiceProvider`), config, migration stub, Pest/Larastan/Pint setup,
  GitHub workflows, workbench.
- **Alternatives considered**: Supporting Laravel 12 as well — rejected by the explicit "latest
  version" instruction; it doubles the CI matrix for internals (Builder/Model) that the design
  hooks into.

## R2 — Recurrence engine

- **Decision**: `rlanvin/php-rrule: ^3.0` (v3.0.0, 2026-07-29, MIT, PHP ≥ 7.3). Only
  `RRule\RRule` is used; `RRule\RSet` is not. Rules are built with `new RRule($input)`, never
  `RRule::createFromRfcString()`, because the latter silently returns an `RSet` when the text
  contains EXDATE/RDATE lines (verified), which would break FR-028 and the RDATE out-of-scope
  assumption.
- **Verified behaviour**:
  - `rfcString()` / `__toString()` produce `DTSTART;TZID=Europe/Budapest:20260302T090000\nRRULE:FREQ=WEEKLY;BYDAY=MO` — one round-trippable text form including the time zone.
  - Occurrences keep local wall-clock across DST (`2026-03-23T09:00+01:00`, `2026-03-30T09:00+02:00`) → satisfies FR-011.
  - `humanReadable()` → `weekly on Monday, starting from 3/2/26, forever`. `ext-intl` is auto-detected; without it the library falls back to built-in translations. `ext-intl` goes into `suggest`.
  - Invalid input throws `InvalidArgumentException` with the offending part (`Invalid BYDAY value: XX`) → wrapped for FR-006.
  - `isFinite()` / `isInfinite()` available for the horizon rule (FR-008).
- **Alternatives considered**: `simshaun/recurr` — rejected; the user mandated php-rrule, and it
  has no `humanReadable()` equivalent with the same interface.

## R3 — Expanding per query vs. a derived occurrence index

- **Decision** (revised 2026-10-05): **expand per query**. When a query is built, every series of
  the model type is loaded (one query joining the model table to `groundhog_recurrences`), each
  rule is expanded with php-rrule for the query's window only (R6), and all rows
  `(recurrence_id, starts_at, ends_at)` are passed to SQL as one JSON parameter that the
  database's JSON table function unpacks (R4). Nothing derived from a rule is stored (FR-002).
- **Rationale**: FR-002 and the input ("hydrated but non-persisted model instances") forbid
  storing occurrences; the user chose per-query expansion and accepted its cost. FR-009 still
  requires every SQL constraint (raw wheres, `whereHas`, joins, arbitrary `orderBy`) to apply to
  occurrences, so they must exist as SQL rows when the query runs; an in-PHP filter cannot
  evaluate arbitrary SQL. Measured cost at the SC-003 volume (1,000 daily series × 1 year =
  365,000 occurrences): ≈1.9 s per query to generate and encode (php-rrule alone ≈1.3 s; per-row
  Carbon conversion would add ≈3.4 s, so the generator uses native date-times), twice per
  `paginate()`; full page plus total 4.8–7.4 s (SQLite 3.45), 7.0–7.9 s (MySQL 8.4), 6.8–7.1 s
  (PostgreSQL 18).
- **Exclusions are applied in SQL**; queries anti-join `groundhog_exclusions`, so generation is a
  pure function of (rule, start, duration, window).
- **Alternatives considered**:
  - Derived occurrence index table (`groundhog_occurrences`, chosen in planning on 2026-10-04 and
    shipped in 0.1): filled on rule save, extended lazily by reads up to a materialisation ceiling,
    rebuilt on rule/start/duration change; met the 1/2/3 s targets. Dropped on 2026-10-05: it
    stored rule-derived data, which the original FR-002 forbids, through a planning amendment the
    user never approved.
  - Inline `VALUES` / one bind parameter per value: hits the 65,535 bind-parameter limit.
  - Per-query temporary table: adds writes and DDL to every read.
  - SQL-native expansion (recursive CTE): cannot express BYSETPOS/BYWEEKNO/BYDAY ordinals
    portably across SQLite, MySQL and PostgreSQL.
  - Storing occurrences as model rows: violates FR-002 and the "non-persisted instances"
    requirement.

## R4 — Making ordinary Eloquent queries return occurrences

- **Decision**: A global scope (`OccurrenceScope`) replaces the query's `FROM` with a derived
  table aliased as the model's own table name:

  ```text
  ( -- stored rows that are not series: plain records and exceptions
    select m.<columns>, xl.series_key as groundhog_series_key,
           xl.original_starts_at as groundhog_original_starts_at
      from <table> m
      left join (select xe.exception_id, xe.original_starts_at, re.recurrable_id as series_key
                   from groundhog_exclusions xe
                   join groundhog_recurrences re on re.id = xe.recurrence_id
                  where re.recurrable_type = :morphClass
                    and xe.exception_id is not null) xl on xl.exception_id = m.<pk>
      left join <table> s                on s.<pk> = xl.series_key          -- soft-deletable models only
      left join groundhog_recurrences r  on r.recurrable_type = :morphClass and r.recurrable_id = m.<pk>
     where r.id is null
    union all
    -- occurrence rows generated for this query, joined to their series
    select <series columns, pk null, start/end from o>, r.recurrable_id, o.starts_at
      from <json table of :occurrences> o            -- [[recurrence_id, starts_at, ends_at], ...]
      join groundhog_recurrences r       on r.id = o.recurrence_id
      join <table> m                     on m.<pk> = r.recurrable_id
      left join groundhog_exclusions xo  on xo.recurrence_id = o.recurrence_id and xo.original_starts_at = o.starts_at
     where xo.recurrence_id is null
  ) as <table>
  ```

  `<json table>` is `json_each(?)` on SQLite, `JSON_TABLE(?, '$[*]' …)` on MySQL and
  `json_array_elements(cast(? as json))` on PostgreSQL; other drivers throw
  `RecurrenceNotSupported::forDatabaseDriver()`. One JSON parameter carries every row, so the
  bind-parameter limit (65,535 on MySQL/PostgreSQL) is never reached.

  Because the alias equals the table name, user constraints like `where('meetings.title', …)`,
  joins, `whereHas` correlation, `orderBy`, aggregates, `paginate()`'s count query and
  `exists()` all run unchanged in SQL (FR-007, FR-009). Plain rows and exceptions pass through;
  series rows are replaced by their occurrences; excluded starts vanish (FR-013, FR-014).
- **Column list**: Required to replace start/end inside the derived select (duplicate column
  names in derived tables fail on MySQL). It is read once per model class per process via
  `Schema::getColumnListing()`, the same mechanism Eloquent's `isGuardableColumn()` uses.
- **Why a global scope**: Eloquent applies global scopes inside `applyScopes()`, which every read
  path goes through (`get`, `cursor`, `toBase()` → `count`/`exists`/aggregates/`pluck`,
  `paginate`, `chunk`, `lazy`, relationship-existence subqueries). Verified in
  `Eloquent\Builder` v13.34.0. Model saves use `newModelQuery()`, which never registers global
  scopes, so single-record writes hit the real table.
- **Bulk writes (FR-026)**: Eloquent `update()`/`delete()` and the insert passthrus also call
  `toBase()` → `applyScopes()`. The package's `RecurringBuilder` (custom Eloquent builder)
  overrides `update`, `delete`, `forceDelete`, `increment`, `decrement`, `incrementEach`,
  `decrementEach`, `touch`, `insert`, `insertOrIgnore`, `insertGetId`, `insertUsing`,
  `insertOrIgnoreUsing`, `upsert` to drop the occurrence scope first, so they act on stored rows
  with the user's own constraints.
- **Retrieval by key (FR-015)**: when a top-level, AND-ed clause equates the primary key with a
  value, a list or a column, the scope removes itself and the query reads stored rows. This covers
  `find`, `findMany`, `whereKey`, `destroy`, belongs-to relations and `whereHas` from other
  models without overriding each entry point: virtual rows have no key, so only the series row
  could be lost. The trait's `resolveRouteBindingQuery()` drops the scope for custom route keys.
- **Join order (SC-003)**: in the stored-rows part, the joins that depend only on the stored row
  (`xl`, `s`) come first; the occurrence part joins each JSON row to its series by primary key.
- **Exception link isolation**: The model-type filter sits inside the `xl` subquery. A plain
  left join on `exception_id` alone would duplicate a row whenever exceptions of two model types
  share a key value.
- **Keyed iteration (FR-007)**: `chunkById`/`lazyById`/`eachById` page by `key > last key`;
  virtual rows have a null key and would be skipped, so `RecurringBuilder` throws
  `RecurrenceNotSupported` while the occurrence scope is active.
- **Escape hatch**: `RecurringBuilder::withoutOccurrences()` removes `OccurrenceScope` and returns
  stored rows, like `withTrashed()` does for soft deletes. Optional; never required for normal use.
- **Custom builders**: The trait's `newEloquentBuilder()` honours `#[UseEloquentBuilder]` only if
  the class extends `RecurringBuilder`; otherwise it throws `IncompatibleEloquentBuilder`
  instead of silently losing FR-015/FR-026.
- **Alternatives considered**: Swapping `FROM` inside a custom base `Query\Builder` at compile
  time — rejected: nested queries (`whereExists`, `whereHas`) are compiled by the grammar directly
  and would bypass the override.

## R5 — Deriving the time window from ordinary constraints (FR-008)

- **Decision**: `TimeWindow::fromQuery()` walks top-level `where` clauses and nested groups whose
  boolean is `and` throughout. It reads:

  | Constraint | Upper bound for start | Lower bound for start |
  |---|---|---|
  | `start <`, `<=`, `=` X; `between` [A,B] on start | X / B | `=` X, `>`, `>=` X / A |
  | `end <`, `<=` X; `between` on end | X (start ≤ end) | — |
  | `end >`, `>=` X | — | used only as horizon base |
  | `groundhog_original_starts_at` comparisons | same as start | same as start |

  Anything under an `or`, raw expressions or subqueries contributes nothing (conservative).
- **Cap**: If no upper bound is derived, `cap = (lower start bound ?? lower end bound ?? now()) +
  config('groundhog.horizon')`, and infinite rules are generated only for starts before `cap`.
  Finite rules are never capped (US1-5). Generation depends only on the query, so results never
  depend on earlier queries (determinism).
- **Generation window**: the derived bounds also limit which occurrences are generated (R6), so
  a narrow window generates few rows. This is safe because the user's own predicates imply them.

## R6 — Generation window and limits

- **Decision** (revised 2026-10-05): nothing is maintained between queries. For each query,
  `OccurrenceRows` loads every series of the model type and `OccurrenceGenerator` expands each
  rule for that series' window:
  - **Lower**: the query's lower start bound, raised to (lower end bound − series duration) when
    the query bounds the end column from below.
  - **Upper**: the query's upper start bound, inclusive (an upper end bound also caps start);
    otherwise infinite rules stop at the cap (R5) and finite rules expand to their end.
  - **On rule/start change**: the series' exclusions are reset (exceptions detached,
    cancellations discarded; FR-022) and DTSTART is rewritten. A rule counts as changed only when
    its `rfcString()` differs from the stored text.
  - **On duration change only**: nothing happens; occurrence ends change on the next query.
- **Limit**: `config('groundhog.max_occurrences_per_series')` (default 50,000) bounds the
  occurrences one series generates for one query (only occurrences inside the window count) and
  the check on save (a finite rule in full, an infinite rule from DTSTART to `now + horizon`).
  php-rrule is asked for `limit + 1` occurrences; reaching it throws `OccurrenceLimitExceeded`
  naming the series and window (SC-005, spec edge case "Runaway generation"). 50,000 covers 136
  years of daily or 5.7 years of hourly occurrences; minutely rules are rejected on save.
- **No read ceiling**: because a query generates only its own window, a far window (e.g. one
  month in 2040) costs the same as a near one. The 0.1 `max_materialization_ahead` ceiling, which
  stopped a request from persisting unbounded rows, is removed.
- **Alternatives considered** (history): 0.1 maintained a stored index, built on rule save,
  extended lazily by reads under a `lockForUpdate` on the recurrence row (`insertOrIgnore` in
  chunks of 500), rebuilt on rule, start or duration change, and bounded by a read ceiling.
  Withdrawn with the index (R3).

## R7 — Time representation

- **Decision**: Generated `starts_at`/`ends_at` and exclusion `original_starts_at` are written with the
  model's own `fromDateTime()` (its date format, in the application time zone), the exact
  representation Eloquent uses for the model's start column. SQL comparisons and equality joins
  are therefore exact. The rule is evaluated in `groundhog_recurrences.timezone`, which comes
  from the assigned input's DTSTART zone if it has one, otherwise `config('app.timezone')`.
  DTSTART's date-time always comes from the model's start attribute. Occurrence end =
  occurrence start + (series end − series start), computed in PHP during generation.
- **Tests**: Clock-dependent behaviour (default horizon) uses `$this->travelTo()`; tests never read
  the real clock (Constitution II).

## R8 — The rule cast (FR-028, user input)

- **Decision**: `HasRecurrence::initializeHasRecurrence()` merges the cast
  `['recurrence_rule' => AsRecurrenceRule::class]` into the model.
  - **`get`**: Returns `?RRule\RRule`. Precedence: pending (assigned, unsaved) rule → stored rule
    of the series → for a virtual occurrence, the series' stored rule → `null` for exceptions and
    plain records.
  - **`set`**: Accepts an RRULE string (optionally with a DTSTART line), a php-rrule parts array,
    an `RRule` instance, or `null` (removes the rule). It validates immediately (FR-006), keeps
    the value as pending state on the model, and returns `[]` so nothing is merged into the
    model's attributes. Verified: `setClassCastableAttribute()` merges array responses and wraps
    non-arrays as `[$key => $value]`, which would leak a fake column into the INSERT.
  - **Persisting**: Rule persistence happens in the trait's `save()` override, inside the same
    transaction, after `parent::save()`. It does not use a `saved` listener, which
    `saveQuietly()` would suppress.
- **Mass assignment**: `isGuardableColumn()` returns true for class-castable keys (verified), so
  `$guarded` models accept `recurrence_rule`; `$fillable` models must list it. The trait does
  not call `mergeFillable()`: on a `$guarded` model that would turn every other attribute
  non-fillable.
- **Alternatives considered**: Casting `groundhog_recurrences.rule` on the `Recurrence` model as
  well — rejected: a second cast with the same responsibility. The `Recurrence` model stores
  plain RFC text and the trait's cast is the single public entry point.

## R9 — Virtual occurrence identity and write interception

- **Decision**:
  - **Hydration**: The trait overrides `newFromBuilder()`. A row with a null key and a non-null
    `groundhog_series_key` is hydrated with `exists = false`.
  - **Identity attributes**: `groundhog_series_key` and `groundhog_original_starts_at` stay as
    visible attributes, so API clients can address an occurrence, and so
    `series()` (a `BelongsTo` on `groundhog_series_key`) can be eager loaded.
  - **Stored rows**: Rows loaded without the occurrence scope (key lookups, route binding,
    `refresh()`, `withoutOccurrences()`) get the same attributes from one
    `ExceptionLedger::exceptionLinksFor()` query in `RecurringBuilder::getModels()`, before eager
    loading, so `toArray()` and `with('series')` behave the same as on expanded rows. `cursor()`
    bypasses `getModels()`; its models resolve the link on first predicate use.
  - **Stripping**: `getAttributesForInsert()` and `getDirtyForUpdate()` strip both identity
    attributes and a null key.
  - **Explicit selects**: When the user selected explicit columns (not distinct, not grouped),
    the scope appends both identity columns so saved partial selections stay addressable.
- **Write interception (all inside a DB transaction)**:
  - **`save()`**:
    - Virtual occurrence → insert as a new row, then insert the exclusion with `exception_id` (FR-017, FR-025).
    - Any record with a pending rule → persist the rule after checking the per-series limit: first rule → insert it; changed `rfcString()` → update it and reset exclusions; identical rule → no change.
  - **`delete()`**:
    - Virtual occurrence → fire `deleting`; if not halted, insert a cancellation exclusion; fire `deleted` (FR-019, FR-024).
    - Exception, hard delete → null its exclusion's `exception_id`, leaving a cancellation (FR-020). Soft delete keeps the link; the soft-delete scope hides the row, so the occurrence stays cancelled until restore.
    - Series, hard delete → force-delete its exceptions (including already-trashed ones) through model `forceDelete()`, then the recurrence row; FKs cascade exclusions (FR-023). Soft delete → only the series row is trashed; its exceptions are hidden through the derived deleted-at column until restore.
  - **`update()`**: Eloquent returns `false` on non-existing models (verified); the trait performs
    fill + save for virtual occurrences (US3-5).
  - **`incrementOrDecrement()`**: Eloquent runs an unconstrained table-wide update for
    non-existing models (verified in `Model`). The trait applies the change to the attribute and
    saves as an exception instead.
- **Collections**: `Eloquent\Collection::unique/merge/diff/intersect/only/except` key by
  `getKey()` via `getDictionary()`; null keys would collapse every occurrence into one. The trait's
  `newCollection()` returns `OccurrenceCollection`, whose `getDictionary()` keys virtual
  occurrences by `series key @ original start`.
- **Soft deletes**: When the model uses `SoftDeletes`, the derived select emits
  `coalesce(m.deleted_at, <series deleted_at>)` for exception rows, so the soft-delete scope also
  hides exceptions of a trashed series; restoring the series shows them again (FR-023).

## R10 — Testing and quality gates

- **Decision**:
  - **Tests**: Pest 4 on Testbench 11, with workbench models `Meeting` (start/end, soft deletes)
    and `Shift` (start only). Feature tests map 1:1 to the acceptance scenarios in the spec.
    Unit tests cover `TimeWindow` derivation and rule parsing edge cases.
  - **CI matrix**: SQLite (in-memory), MySQL 8.4 and PostgreSQL 17. The derived-table SQL is
    the main portability risk.
  - **Static analysis**: Larastan level 9. Pint for formatting.
  - **Benchmark**: `composer bench` runs `benchmarks/pagination.php`, a measurement script
    outside the Pest suite (SC-003), because a wall-clock assertion cannot be deterministic.
- **Alternatives considered**: SQLite-only CI (skeleton default) — rejected; join/type semantics
  differ (PostgreSQL rejects `varchar = bigint` comparisons), which is exactly where this package
  can break.
