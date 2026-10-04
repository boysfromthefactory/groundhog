# Quickstart & Validation Guide: Groundhog

Proves the feature end-to-end. API details: [contracts/public-api.md](contracts/public-api.md);
query rules: [contracts/query-semantics.md](contracts/query-semantics.md); tables:
[data-model.md](data-model.md).

## Prerequisites

- PHP 8.4+ with `pdo_sqlite` (default), optionally `pdo_mysql` / `pdo_pgsql`; `ext-intl`
  recommended for localised `humanReadable()`.
- Composer 2.
- For the full DB matrix: MySQL 8.4 and PostgreSQL 17 reachable locally (e.g. containers).

## Setup

```bash
composer install
vendor/bin/testbench workbench:build   # migrates the workbench app (Meeting, Shift, Room, Attendee)
```

## Gates (all must pass; Constitution merge gates)

```bash
composer format -- --test   # Pint, no changes
composer analyse            # Larastan level 9, zero errors
composer test               # Pest, SQLite in-memory
DB_CONNECTION=mysql DB_DATABASE=groundhog composer test
DB_CONNECTION=pgsql DB_DATABASE=groundhog composer test
```

## Usage in a host app (what the docs must show)

1. `composer require boysfromthefactory/groundhog`
2. `php artisan vendor:publish --tag=groundhog-migrations && php artisan migrate`
3. Add `use HasRecurrence;` to the model, set `RECURRENCE_STARTS_AT` / `RECURRENCE_ENDS_AT` if
   the column names differ, and add `recurrence_rule` to `$fillable` when the model uses `$fillable`.
4. `Meeting::create([... 'starts_at' => '2026-03-02 09:00', 'ends_at' => '2026-03-02 10:00', 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO'])`
5. Query normally: `Meeting::whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->paginate(25)`.

## Validation scenarios

Each row is a Pest feature test named after the scenario; the expected outcome is the pass
criterion. Clock-dependent scenarios freeze time with `travelTo()`.

| # | Scenario (spec ref) | Expected outcome |
|---|---|---|
| 1 | Weekly Monday series, March window (US1-1) | 5 instances: 2, 9, 16, 23, 30 Mar 09:00–10:00, series attributes copied |
| 2 | Inspect an instance (US1-2) | `exists === false`, key `null`, `series` = stored series, `originalOccurrenceStart()` set |
| 3 | `$m->recurrence_rule->humanReadable(['locale' => 'en'])` on series and on a virtual occurrence (FR-028) | starts with `"weekly on Monday"`; same rule for the occurrence; `instanceof RRule\RRuleInterface` |
| 4 | Assign `'FREQ=WEEKLY;BYDAY=XX'` (FR-006) | `InvalidRecurrenceRule` mentioning `BYDAY`; no rows written |
| 5 | Interleaved series + plain, `paginate(4, page: 2)` ordered by start (US2-1/2/3) | items 5–8 of 11; `total() === 11`; `count() === 11`; `exists()` on day 6 false |
| 6 | Frozen clock 2026-03-01, infinite daily, no constraints (US2-6) | `count() === 365` |
| 7 | `where('starts_at', '>=', '2030-01-01')` (US2-7) | 365 rows, last 2030-12-31 09:00 |
| 8 | Edit + save 16 Mar occurrence (US3-1/2) | one new stored row linked to the series; March query: 5 rows, 16 Mar is the exception |
| 9 | Move 16 Mar → 18 Mar 14:00 (US3-3) | 16 Mar empty for this series; 18 Mar returns the exception |
| 10 | `update([...])` and `increment()` on a virtual occurrence (US3-5) | exception persisted; other rows of the table unchanged |
| 11 | Delete virtual 23 Mar; delete exception 16 Mar (US4-1/2) | both dates absent; no exception row for 23 Mar |
| 12 | Change series title (US4-3, US4-7) | virtual rows show new title; exception keeps its own and stays linked |
| 13 | Change rule to Tuesday with exception present (US4-6) | Tuesday rows + former exception as plain row (`groundhog_series_key === null`) |
| 14 | Force-delete / soft-delete + restore series (US4-4, FR-023) | all rows gone, incl. trashed exceptions / hidden / back |
| 15 | `Meeting::find($seriesId)` and route binding (FR-015) | stored series record |
| 16 | `Meeting::where(...)->update([...])` (FR-026) | stored rows only; no exclusions created |
| 17 | Minutely rule on save; daily series queried to 2034 with limit 1000; query beyond `max_materialization_ahead` (SC-005, FR-008) | `OccurrenceLimitExceeded` each time; no index rows written beyond the existing range |
| 18 | Europe/Budapest series across DST (FR-011) | 09:00 local before and after 29 Mar 2026 |
| 19 | Plain record in the March window (US1-3) | returned once, `exists === true` |
| 20 | `where('location', 'Room A')` with a series in Room A (US1-4) | constraint evaluated per occurrence |
| 21 | Daily `COUNT=3`, very large window (US1-5) | exactly 3 occurrences |
| 22 | `Meeting` and `Shift` series side by side (US1-6) | no cross-model leakage; both rules in `groundhog_recurrences` |
| 23 | `orderBy('title')->orderBy('starts_at')` (US2-4) | same order as stored rows |
| 24 | `chunk`, `lazy`, `cursor`; `chunkById` (US2-5) | each occurrence exactly once; `chunkById` throws `RecurrenceNotSupported` |
| 25 | Re-edit a stored exception (US3-4) | updated in place; no new row |
| 26 | Change a virtual occurrence without saving (US3-6) | nothing persisted |
| 27 | Replace the rule; set it to `null` (US4-5) | new occurrences; record returned once as plain |

## Performance check (SC-003)

```bash
composer bench
```

`composer bench` runs `benchmarks/pagination.php`, a measurement script outside the Pest suite:
its wall-clock check cannot be deterministic, so it is not a test. It seeds 1,000 daily series
and times `whereBetween('starts_at', [start, start + 1 year])->orderBy('starts_at')->paginate(25)`
for page 1, page 200 and the last page, after the index has been built. Pass: each page incl.
total < 1 s on PostgreSQL, < 2 s on MySQL and < 3 s on SQLite, on a machine with ≥ 4 CPU cores
and 16 GB RAM running the database locally; report the timings and the machine in the PR.
