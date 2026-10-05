# Contract: Capability and Example Coverage

Every capability in the package's public contract
([001 public-api.md](../../001-recurring-eloquent-models/contracts/public-api.md)) must be
documented with an example (SC-002), and every outcome an example states must be proven by a
check (SC-003, research R3). Checks are existing tests (`File › test name`), new tests marked
**NEW** (to be added before the page is written), or quickstart steps marked **V-n**
([quickstart.md](../quickstart.md)).

Test files: `Cast` = `tests/Feature/RecurrenceRuleCastTest.php`, `Query` =
`tests/Feature/QueryOccurrencesTest.php`, `Page` = `tests/Feature/PaginationAndAggregatesTest.php`,
`Edit` = `tests/Feature/EditOccurrenceTest.php`, `Life` = `tests/Feature/SeriesLifecycleTest.php`,
`Edge` = `tests/Feature/EdgeCasesTest.php`, `Rule` = `tests/Unit/RuleFactoryTest.php`.

## Trait and model

| Capability | Page | Stated outcome | Check |
|---|---|---|---|
| `use HasRecurrence`, default columns | Declaring | weekly series yields 5 March occurrences | Query › returns one instance per occurrence … (US1-1) |
| `RECURRENCE_ENDS_AT = null` | Declaring | occurrences carry only a start | Query › gives occurrences of a model without an end column only a start |
| `$fillable` with `recurrence_rule` | Declaring | rule accepted through mass assignment | Cast › accepts the rule through mass assignment on a $fillable model |
| Custom builder must extend `RecurringBuilder` | Declaring, Errors | plain builder throws; subclass works | Edge › requires a custom builder to extend RecurringBuilder |
| `recurrence_rule` write: string / array / `RRule` | Rules | same occurrences for each form | Cast › accepts a php-rrule parts array or an RRule instance; Cast › stores one rule row … |
| `DTSTART;TZID` input | Rules | stored zone is the input's; date-time from the start column | Cast › stores the time zone of a DTSTART;TZID input; Rule › takes the time zone from a DTSTART;TZID line … |
| Local time across DST | Rules | 09:00 Budapest before and after 29 March | Edge › keeps the local wall-clock time across a daylight-saving change |
| All RRULE parts | Rules | each part round-trips | Rule › keeps every RFC 5545 component … |
| Invalid rule | Rules, Errors | `InvalidRecurrenceRule` naming `BYDAY`; nothing stored | Cast › rejects an invalid rule on assignment and writes nothing |
| EXDATE/RDATE/EXRULE rejected | Rules, Limitations | `InvalidRecurrenceRule` | Rule › rejects lines that would turn the rule into a set |
| `recurrence_rule` read, `humanReadable()` | Rules | `RRule`; text starts "weekly on Monday"; 5 March dates | Cast › exposes the stored rule as an RRule |
| Rule on a virtual occurrence / exception | Rules | series rule / `null` | Cast › exposes the series rule on a virtual occurrence and no rule on an exception |
| Replace rule | Rules, Series | Tuesday occurrences only | Cast › replaces the occurrences when a different rule is assigned |
| Remove rule (`null`) | Rules, Series | record returned once as plain | Cast › turns the record into a plain record when the rule is set to null |
| `recurrence()` relation | Rules | series' `Recurrence` row with RFC text and zone | **NEW** Cast › exposes the stored rule row through recurrence() |
| `series` relation | Querying | occurrence's series is the stored series | Query › marks every occurrence as virtual … (US1-2) |
| `isVirtualOccurrence()`, `originalOccurrenceStart()` | Querying | true; equals start | Query › marks every occurrence as virtual … (US1-2) |
| `isRecurringSeries()` | Stored records | true only for the stored series | Edge › reports the series role only for the stored series |
| `isOccurrenceException()` | Editing, Stored records | true after saving an occurrence; true when loaded by key | Edit › persists an edited occurrence …; Edit › recognises an exception loaded by key |
| `save()` on an occurrence | Editing | new stored record + link; series unchanged | Edit › persists an edited occurrence as an exception … (US3-1) |
| Exception replaces occurrence | Editing | 16 March row is the exception | Edit › returns the exception in place of its occurrence (US3-2) |
| Move an occurrence | Editing | shown on 18 March only | Edit › shows a moved exception at its new time only (US3-3) |
| Edit exception again | Editing | updated in place | Edit › updates a stored exception in place (US3-4) |
| `update()`, `increment()` | Editing | exception stored; other rows unchanged | Edit › persists an exception through update() and increment() … (US3-5) |
| `decrement()` | Editing | exception stored with decremented value | **NEW** Edit › persists an exception through decrement() on a virtual occurrence |
| Unsaved edits | Editing | nothing stored | Edit › persists nothing for an edited but unsaved occurrence (US3-6) |
| Save unchanged | Editing | exception stored | Edit › persists an exception even when an occurrence is saved unchanged |
| Events | Editing | creating/created/saved; updating/updated | Edit › fires the creation events on promotion … |
| Concurrent saves | Editing | second save throws; no orphan | Edit › rejects a second exception for the same occurrence … |
| Moved onto another occurrence | Editing | both returned | Edit › returns both an exception moved onto another occurrence … |
| Rule on exception rejected | Editing, Errors | `RecurrenceNotSupported` | Edit › refuses a rule on an exception |
| `delete()` on an occurrence | Cancelling | cancelled; no record; events | Life › cancels a deleted virtual occurrence … (US4-1) |
| Veto via `deleting` | Cancelling | stays | Life › lets a deleting listener veto the cancellation |
| Hard-delete exception | Cancelling | occurrence stays cancelled | Life › keeps the occurrence cancelled when its exception is hard-deleted; … loaded by key … |
| `delete()` on an exception of a model without soft deletes | Cancelling, Series | occurrence stays cancelled | **NEW (T023)** Life › keeps the occurrence cancelled when an exception of a model without soft deletes is deleted |
| Soft-delete / restore exception | Cancelling | cancelled while trashed; back on restore | Life › cancels an occurrence while its exception is soft-deleted … |
| Edit series attributes | Series | occurrences follow; exception keeps own values | Life › propagates series edits … (US4-3, US4-7) |
| Change rule / start | Series | exceptions detached; cancellations discarded | Life › detaches exceptions … rule changes; … series start changes; Life › discards cancellations … |
| Detached next to new occurrence | Series | both returned | Life › returns a detached exception next to a new occurrence … |
| Same rule again | Series | nothing changes | Life › changes nothing when the identical rule is assigned again |
| Duration only | Series | ends move; exceptions and cancellations kept | Life › keeps exceptions and cancellations when only the duration changes |
| Force-delete series | Series | rule, occurrences, exceptions removed | Life › removes the rule, index, exclusions and exceptions … (US4-4) |
| Soft-delete / restore series | Series | hidden; back on restore | Life › hides and restores a soft-deleted series … |
| `newCollection()` set operations | Pagination | `unique`/`merge`/`diff`/`intersect` keep 11 | Page › keeps occurrences distinct in collection set operations |
| Collection `find()` with an occurrence | Pagination | finds that occurrence | **NEW** Page › finds a virtual occurrence in a collection by the occurrence itself |

## Builder and queries

| Capability | Page | Stated outcome | Check |
|---|---|---|---|
| Plain records alongside occurrences | Querying | plain record once, stored | Query › returns a plain record once … (US1-3) |
| Non-time constraints | Querying | filtered per occurrence | Query › evaluates non-time constraints … (US1-4) |
| Finite rule, huge window | Querying | exactly 3 | Query › returns exactly the occurrences of a finite rule … (US1-5) |
| Multiple recurring models | Querying | no leakage; one rule table | Query › keeps each model type to its own occurrences … (US1-6) |
| Horizon from now / lower bound | Querying, Configuration | 365 rows each (now = 1 March 2026) | Query › expands an infinite series up to one year after now …; … after the lower bound … |
| Running occurrences (end constraint) | Querying | started-before occurrence returned | Edge › matches occurrences that started before the window … |
| OR-combined bounds | Querying | nothing dropped | Edge › never drops matching occurrences because of an OR-combined bound |
| Rule without occurrences | Querying | none returned; record found by key | Edge › contributes nothing for a rule without occurrences … |
| Eager loading, `attendees` empty | Querying, Limitations | room loaded; attendees empty; series loaded | Edge › eager loads foreign-key relations … |
| `whereHas`, joins, relation queries | Querying | 5 March meetings in room A | Query › applies relationship queries, whereHas and joins … |
| `pluck`, `value`, `sole` | Querying | March dates; title; 16 March | Query › answers pluck, value and sole over occurrences |
| `paginate`, totals | Pagination | page 2 of 4 = items 5–8; total 11 | Page › pages over the merged, start-ordered occurrences (US2-1) |
| `simplePaginate` | Pagination | last page 3 items, no more pages | Page › paginates simply over occurrences |
| `count`, `exists` | Pagination | 11; false on an empty day | Page › counts occurrences and answers existence … (US2-2) |
| Aggregates | Pagination | min/max/sum/avg | Page › aggregates over occurrences |
| Ordering, ties | Pagination | stable across pages | Page › orders by a non-time attribute …; … pages occurrences that tie … stably |
| `chunk`, `lazy`, `cursor` | Pagination | each occurrence once | Page › visits every occurrence exactly once … |
| `chunkById` etc. rejected | Pagination, Errors, Limitations | `RecurrenceNotSupported`; works with `withoutOccurrences()` | Edge › rejects key-based iteration …; Edge › allows key-based iteration over stored rows |
| Explicit `select`, `groupBy`, `distinct` | Pagination | identity kept; per-location counts | Page › keeps the identity of occurrences fetched with an explicit select; … groups and de-duplicates … |
| `find`, `whereKey`, belongs-to pointing at a series | Stored records | stored series returned | Query › returns the stored series when looked up by key; Edge › resolves relations that point at a series … |
| `whereKeyNot()` | Stored records | stored rows other than the key | **NEW** Edge › excludes a key from stored rows with whereKeyNot() |
| Route model binding | Stored records | stored series bound | Edge › binds a route parameter to the stored series |
| `withoutOccurrences()` | Stored records | series, plain and exceptions; no virtual rows | Edge › returns stored rows only through withoutOccurrences(); Edit › returns stored exceptions through withoutOccurrences() |
| Query-level writes | Stored records | stored rows only; no exclusions | Life › applies query-level updates and deletes …; … other query-level writes …; … inserts and upserts … |
| `destroy()` | Stored records | hard branch; soft-delete for soft-deletable | Life › destroys a series through its hard delete branch … |
| Identity attributes on stored rows / `toArray()` | Identifying | series key present | Edit › gives stored rows loaded without expansion the same identity attributes |
| Finding an occurrence by its identity | Identifying | the 16 March occurrence | **NEW** Query › finds an occurrence again by series key and original start |
| `cursor()` identity resolved on first predicate | Identifying, Limitations | attribute absent until a predicate is used | **NEW** Edit › resolves the identity of a cursor-loaded exception on first use |

## Configuration and errors

| Capability | Page | Stated outcome | Check |
|---|---|---|---|
| `horizon` | Configuration | `P1M` → 31 | Query › honours a configured horizon length |
| `max_occurrences_per_series` | Configuration, Errors | over-long rule rejected on save; far query throws | Cast › refuses a finite rule …; Cast › refuses an infinite rule too dense …; Edge › fails instead of generating past the per-series limit … |
| `max_materialization_ahead` | Configuration, Errors | throws only when generation is needed | Edge › fails instead of generating past the materialisation ceiling; … applies the ceiling only when … |
| Missing start | Errors | `RecurrenceNotSupported` | Cast › refuses to save a series without a start |
| Rule persisted by `saveQuietly()` | Rules | rule stored | Cast › persists the rule on saveQuietly() |
| Install, publish, migrate | Quick Start | tags publish config and migration; tables created | **V-3** fresh-application walkthrough |
| SC-003 performance | How It Works | per-database budgets | **V-6** `composer bench` results recorded in the 001 spec amendment |

## Review pass (T023)

Statements found in the pages during review that are not behaviour outcomes of a single test:

| Statement | Page | Evidence |
|---|---|---|
| Rendered exception messages (`InvalidRecurrenceRule`, `RecurrenceNotSupported`, `OccurrenceLimitExceeded` incl. the far-query and ceiling timestamps) | Errors, Recurrence Rules | Smoke run of each trigger against the suite's fixtures on 2026-10-05 printed the exact texts on the page; messages are deliberately not pinned by tests (Constitution III: no message-wording tests) |
| JSON of a virtual occurrence (field order and formats) | Identifying | Same smoke run, `toArray()` of the 9 March occurrence on SQLite |
| `humanReadable()` locale defaults to PHP's, not Laravel's | Recurrence Rules | php-rrule `RRule::humanReadable()` source (`Locale::getDefault()` / `setlocale`) |
| Tie-breakers skipped for grouped, distinct and union queries; grouped rows are partial | Pagination, How It Works | `src/Query/OccurrenceScope.php` `breakOrderingTies()` / `keepIdentityColumns()`; 001 query-semantics contract |
| Bulk writes fire no model events; bulk deletes leave rules behind | Stored records, Limitations | Eloquent query-builder semantics; README caveat |
| Workarounds (queued jobs by identity, series splitting, iCalendar, `each->delete()`) | Limitations, Identifying | Recommendations built only from tested operations (identity query T008, rule replacement, model delete) |
