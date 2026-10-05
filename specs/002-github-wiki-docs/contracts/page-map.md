# Contract: Page Map

The pages, in reading order, with the content each must contain. Section headings listed here are
link targets used by other pages; keep them stable.

## Start

| # | File | Must contain | Spec |
|---|---|---|---|
| 1 | `Home.md` | What Groundhog is (2–3 sentences); the problem it solves; when to use it and when not (e.g. needs "this and following" edits, iCalendar import, SQL Server); supported platforms (PHP 8.4+, Laravel 13, SQLite 3.35+, MySQL 8.4, PostgreSQL 14+); **Core concepts** glossary: recurring model, series, recurrence rule, occurrence / virtual occurrence, exception, cancellation, horizon; contents list linking every page | FR-001, FR-002 |
| 2 | `Quick-Start.md` | Numbered steps, each with code and expected outcome: 1 install (Packagist command + "Before the first tagged release" VCS note, research R4); 2 publish and run the migration (+ optional config); 3 declare `Meeting` with `HasRecurrence`; 4 create the weekly Monday series; 5 query March and paginate; 6 edit the 16 March occurrence; 7 cancel the 23 March occurrence; 8 re-run the query and show the result; "Where next" links | FR-003, SC-001 |

## Guide

| # | File | Sections (`##`) | Spec |
|---|---|---|---|
| 3 | `Declaring-Recurring-Models.md` | Adding the trait · Start and end columns (`RECURRENCE_STARTS_AT`, `RECURRENCE_ENDS_AT`) · Models without an end (`RECURRENCE_ENDS_AT = null`) · Mass assignment (`$fillable` vs `$guarded`) · Soft deletes · Custom Eloquent builders (`RecurringBuilder`, `IncompatibleEloquentBuilder`) | FR-004 |
| 4 | `Recurrence-Rules.md` | Assigning a rule (string, `DTSTART;TZID` string, parts array, `RRule`) · Time zones and daylight saving · Supported RRULE parts (and rejected EXDATE/RDATE/EXRULE) · Validation errors · Reading the rule (`recurrence_rule`, `humanReadable()`, php-rrule methods; rule only, not exceptions) · Replacing and removing a rule · The stored rule (`recurrence()` relation, `Recurrence` model, read-only use) | FR-004, FR-005 |
| 5 | `Querying-Occurrences.md` | What a query returns (occurrences + plain records + exceptions; series rows absent) · Time windows (`whereBetween`, start/end constraints, running occurrences) · Non-time constraints · Open-ended rules and the horizon · Occurrence attributes and predicates (`isVirtualOccurrence()`, `originalOccurrenceStart()`, `series`) · Relationships (eager loading, `whereHas`, joins, relations keyed on the model's own key) · Multiple recurring models | FR-004, FR-005 |
| 6 | `Pagination-and-Collections.md` | Pagination (`paginate`, `simplePaginate`) · Counting, existence and aggregates · Ordering and ties · Chunking and lazy iteration (`chunk`, `lazy`, `cursor`; `chunkById` rejected) · Collections (`unique`, `merge`, `diff`, `intersect`, `find`) · Selecting columns, grouping and distinct | FR-004, FR-005 |
| 7 | `Editing-Occurrences.md` | Saving an edited occurrence · `update()`, `increment()`, `decrement()` · Moving an occurrence · Editing an exception again · Saving without changes · Events · Concurrent edits of the same occurrence · Rules on exceptions are rejected | FR-004, FR-005 |
| 8 | `Cancelling-Occurrences.md` | Cancelling an occurrence (`delete()`) · Vetoing with a `deleting` listener · Deleting an exception (hard vs soft) · Restoring a soft-deleted exception | FR-004, FR-005 |
| 9 | `Managing-a-Series.md` | Editing series attributes · Changing the rule or the start (exceptions detached, cancellations discarded) · Changing only the duration · Re-assigning the same rule · Removing the rule · Deleting a series (hard) · Soft deleting and restoring a series | FR-004, FR-005 |
| 10 | `Stored-Records-and-Bulk-Writes.md` | Looking up by key (`find`, `whereKey`, `whereKeyNot`, `destroy`, belongs-to relations) · Route model binding · `withoutOccurrences()` · Query-level writes (`update`, `delete`, `forceDelete`, `increment*`, `decrement*`, `touch`, `insert*`, `upsert`) · Roles of stored records (`isRecurringSeries()`, `isOccurrenceException()`) | FR-004, FR-005 |
| 11 | `Identifying-Occurrences.md` | The identity attributes (`groundhog_series_key`, `groundhog_original_starts_at`) · In JSON / `toArray()` · Finding an occurrence again from its identity (query by both attributes) · Editing an occurrence from a form or API request · Stored rows streamed with `cursor()` | FR-004, FR-005 |

## Reference

| # | File | Must contain | Spec |
|---|---|---|---|
| 12 | `Configuration.md` | Publishing the config; one `##` per key (`horizon`, `max_occurrences_per_series`, `max_materialization_ahead`) with type, default, effect, example change | FR-008 |
| 13 | `Errors.md` | One `##` per exception class (`InvalidRecurrenceRule`, `RecurrenceNotSupported`, `OccurrenceLimitExceeded`, `IncompatibleEloquentBuilder`): parent class, each cause with an example message, how to fix or avoid | FR-009, SC-006 |
| 14 | `Limitations.md` | One `##` per limitation, matching the README caveats: key-based relations empty on occurrences; `chunkById`/`lazyById`/`eachById`; `cursorPaginate`; SQL Server; queueing occurrences; bulk deletes of series rows; dense rules and the ceiling; `cursor()` identity; "this and following" edits; RDATE/EXDATE; iCalendar import/export — each with workaround | FR-010, FR-017 |
| 15 | `How-It-Works.md` | Expansion as a derived table aliased as the model's table · The occurrence index and lazy extension · Exclusions (exception links, cancellations) · Horizon, per-series limit and materialisation ceiling · Key-pinned queries read stored rows · Performance per database (SC-003 numbers) and what makes queries faster (time bounds) | FR-011 |

## Contributing

| # | File | Must contain | Spec |
|---|---|---|---|
| 16 | `Maintaining-the-Wiki.md` | Where sources live (`wiki/`); writing rules (page map, running example, traced outcomes); enabling the wiki and first publication; publishing an update (clone, copy, commit, push); updating the footer version on release | FR-014, FR-015, FR-016 |

## Shared

| File | Must contain | Spec |
|---|---|---|
| `_Sidebar.md` | Pages 1–16 under the headings Start / Guide / Reference / Contributing | FR-012 |
| `_Footer.md` | "Documents Groundhog dev-master (unreleased)"; links to repository, README, CHANGELOG | FR-012, FR-016 |

## Repository changes outside `wiki/`

| File | Change | Spec |
|---|---|---|
| `.gitattributes` | `/wiki export-ignore` | FR-014 |
| `README.md` | "Documentation" section linking to the wiki | FR-017 |
| `CHANGELOG.md` | `Unreleased` → "Documentation: GitHub wiki with overview, quick start, usage guide and reference" | Constitution V |
