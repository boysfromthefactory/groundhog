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

## R3 — Expanding on every read vs. a derived occurrence index

- **Decision**: Keep a **derived occurrence index** table (`groundhog_occurrences`) holding the
  rule's raw expansion `(recurrence_id, starts_at, ends_at)`. It is filled when a rule is saved,
  extended on demand by reads, and rebuilt whenever rule, series start or duration change. The
  rule stays the single source of truth (spec FR-002 as amended).
- **Rationale**: Measured php-rrule throughput: 1,000 daily series × 1 year = 366,000
  occurrences in **5.31 s** (PHP 8.5, this machine). SC-003 requires a page plus total in < 1 s
  for exactly that volume, so expanding in PHP on each read cannot meet it. Also, FR-009 requires
  every SQL constraint (raw wheres, `whereHas`, joins, arbitrary `orderBy`) to apply to
  occurrences. That is only possible if occurrences exist as SQL rows when the query runs; an
  in-PHP filter cannot evaluate arbitrary SQL.
- **Exclusions are not removed from the index**; queries anti-join `groundhog_exclusions`. The
  index is therefore a pure function of (rule, start, duration), and concurrent
  cancel/extend operations cannot re-insert an excluded start.
- **Alternatives considered**:
  - Per-query temporary table / inline `VALUES`: still pays the 5.3 s generation per query, and
    inline values hit the 65,535 bind-parameter limit.
  - SQL-native expansion (recursive CTE): cannot express BYSETPOS/BYWEEKNO/BYDAY ordinals
    portably across SQLite, MySQL and PostgreSQL.
  - Materialising occurrences as model rows: violates FR-002 and the "non-persisted instances"
    requirement.

## R4 — Making ordinary Eloquent queries return occurrences

- **Decision**: A global scope (`OccurrenceScope`) replaces the query's `FROM` with a derived
  table aliased as the model's own table name:

  ```text
  (select <model columns, start/end replaced by the occurrence's values>,
          <pk: null for virtual rows>, groundhog_series_key, groundhog_original_starts_at
     from <table> m
     left join groundhog_recurrences r   on r.recurrable_type = :morphClass and r.recurrable_id = m.<pk>
     left join groundhog_occurrences o   on o.recurrence_id = r.id [and pushed-down start bounds]
     left join groundhog_exclusions xo   on xo.recurrence_id = o.recurrence_id and xo.original_starts_at = o.starts_at
     left join groundhog_exclusions xe   on xe.exception_id = m.<pk>   -- identifies exception rows
     left join groundhog_recurrences re  on re.id = xe.recurrence_id and re.recurrable_type = :morphClass
    where (r.id is null or (o.id is not null and xo.id is null and (r.is_infinite = false or o.starts_at < :cap)))
  ) as <table>
  ```

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
- **Retrieval by key (FR-015)**: `RecurringBuilder::whereKey()` drops the occurrence scope (used by
  `find`, `findMany`, `findOrFail`, `destroy`); the trait's `resolveRouteBindingQuery()` does the
  same. Series rows are absent from the expanded set, so key lookups must read stored rows.
- **Escape hatch**: `withoutOccurrences()` (scope extension, like `withTrashed()`) returns stored
  rows. Optional; never required for normal use.
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
- **Cap**: If no upper bound is derived, `cap = (lower bound ?? now()) + config('groundhog.horizon')`
  and the derived table filters index rows of infinite rules by `o.starts_at < cap`. Finite rules
  are never capped (US1-5). The cap is a SQL predicate, not a property of what happens to be in
  the index, so results never depend on earlier queries (determinism).
- **Push-down**: Start-derived bounds are also added to the `groundhog_occurrences` join, so that
  databases without derived-condition push-down still use the `(recurrence_id, starts_at)` index.
  This is safe because the user's own predicates imply them.
- **Materialisation target**: before the query runs, every infinite recurrence of the model type
  whose `materialized_until` is below `upper bound ?? cap` is extended up to it (R6).

## R6 — Index maintenance, concurrency and limits

- **Decision**:
  - **On rule save**: generate from DTSTART up to `max(now + horizon, previous materialized_until)` (infinite) or to the end (finite).
  - **On read**: extend lagging infinite recurrences in a transaction that takes a `lockForUpdate` on the recurrence row, re-reads `materialized_until`, and inserts in chunks of 500 via `insertOrIgnore` on the unique `(recurrence_id, starts_at)` key. This is idempotent if two workers race.
  - **On rule/start change**: the same locked transaction deletes the index rows, regenerates them, detaches exceptions (FR-022) and rewrites DTSTART.
  - **On duration change only**: regenerate the index without detaching.
- **Limit**: `config('groundhog.max_occurrences_per_series')` (default 50,000) bounds any single
  generation pass. php-rrule is asked for `limit + 1` occurrences; reaching it throws
  `OccurrenceLimitExceeded` naming the series and window (SC-005, spec edge case "Runaway
  generation"). 50,000 covers 136 years of daily or 5.7 years of hourly occurrences.
- **Write connection**: materialisation runs on the model's connection inside a transaction, so
  read/write-split setups use the write PDO.
- **Alternatives considered**: Scheduled artisan command to pre-extend — rejected as the only
  mechanism because a query beyond the scheduled range would silently return too little. Lazy
  extension is required anyway; a scheduler would only be an optimisation (YAGNI).

## R7 — Time representation

- **Decision**: Index `starts_at`/`ends_at` and exclusion `original_starts_at` are written with the
  model's own `fromDateTime()` (its date format, in the application time zone), the exact
  representation Eloquent uses for the model's start column. SQL comparisons and equality joins
  are therefore exact. The rule is evaluated in `groundhog_recurrences.timezone`, which comes
  from the assigned input's DTSTART zone if it has one, otherwise `config('app.timezone')`.
  DTSTART's date-time always comes from the model's start attribute. Occurrence end =
  occurrence start + (series end − series start), computed in PHP during materialisation.
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
  - **Stripping**: `getAttributesForInsert()` and `getDirtyForUpdate()` strip both identity
    attributes and a null key.
  - **Explicit selects**: When the user selected explicit columns (not distinct, not grouped),
    the scope appends both identity columns so saved partial selections stay addressable.
- **Write interception (all inside a DB transaction)**:
  - **`save()`**:
    - Virtual occurrence → insert as a new row, then insert the exclusion with `exception_id` (FR-017, FR-025).
    - Any record with a pending rule → persist the rule and (re)build the index.
  - **`delete()`**:
    - Virtual occurrence → fire `deleting`; if not halted, insert a cancellation exclusion; fire `deleted` (FR-019, FR-024).
    - Exception, hard delete → null its exclusion's `exception_id`, leaving a cancellation (FR-020). Soft delete keeps the link; the soft-delete scope hides the row, so the occurrence stays cancelled until restore.
    - Series → delete its exceptions through model `delete()`, then the recurrence row; FKs cascade index and exclusions (FR-023).
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
  - **Benchmark**: An opt-in Pest group `benchmark` measures SC-003; it is excluded from the
    default run because it is time-based.
- **Alternatives considered**: SQLite-only CI (skeleton default) — rejected; join/type semantics
  differ (PostgreSQL rejects `varchar = bigint` comparisons), which is exactly where this package
  can break.
