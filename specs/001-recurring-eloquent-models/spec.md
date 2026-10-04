# Feature Specification: Groundhog — Recurring Eloquent Models

**Feature Branch**: `001-recurring-eloquent-models`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "Build a Laravel library using package-skeleton-laravel as a template called Groundhog. This package is dealing with recurring time based models, using rlanvin/php-rrule under the hood, and implement a Declarative Recurrence Pattern. It should store the recursion rule in a separate table with a morphable relation back to the base model, and implement the functionality as a trait. It should work through an Eloquent Query Builder, and should not require any special syntax from the user to use it. Querying the model, paginating, etc should work as with an regular Eloquent model, only it should return hydrated but non-persisted model instances. If any instance is edited, it should be persisted, and added to the recursion pattern as exception."

> **Audience note**: Groundhog is a developer library. Its "users" are application developers
> who add it to their Laravel applications. The host framework (Laravel / Eloquent) and the
> recurrence standard (RFC 5545) are product constraints named by the request, not
> implementation choices; how the library realises them is left to the plan.

## Glossary

- **Recurring model**: An application model that has opted into Groundhog's recurrence capability.
- **Series**: A stored record of a recurring model that has a recurrence rule attached. Its
  own start/end values define the first occurrence and the duration of every occurrence.
- **Recurrence rule**: A declaration of when a series repeats (frequency, interval, end
  condition, by-day/by-month selectors, etc.), following the RFC 5545 RRULE vocabulary,
  plus the series' list of excluded occurrence starts.
- **Occurrence**: One instance of a series at one computed start time.
- **Virtual occurrence**: An occurrence returned by a query as a fully populated model instance
  that does not exist in storage.
- **Exception**: A stored record of the recurring model that replaces exactly one occurrence of a
  series, linked to that series and to the original start of the occurrence it replaces.
- **Cancelled occurrence**: An occurrence removed from a series without a replacement.

## Clarifications

### Session 2026-10-04

- Q: What should an unbounded query return for a series that never ends? → A: Expand up to a
  configurable horizon (default: 1 year after the query's lower time bound, or after the current
  time when there is no lower bound).
- Q: What happens to existing exceptions when the series' rule or start time changes? → A: Keep
  all exceptions as standalone records, unlinked from the series.

### Planning input 2026-10-04

- Platform: build from `spatie/package-skeleton-laravel` and target the latest Laravel (13.x).
- The trait MUST provide a cast that exposes the stored rule as an `RRule\RRule` object, so
  `humanReadable()` and every `RRuleInterface` method can be called on it (FR-028).
- Plan-driven amendments: FR-002 permits a derived occurrence index (rule stays the single source
  of truth; expanding on every read measured 5.3 s for SC-003's volume against a 1 s target);
  virtual occurrences have a null primary key, so key-based relationships are reached through the
  series; generation per series is bounded by a configurable limit (SC-005).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Declare a recurring model and query its occurrences (Priority: P1)

An application developer adds Groundhog's recurrence capability to an existing model (for
example `Meeting`), tells it which attributes hold the start and end time, and attaches a
recurrence rule to a record ("every Monday at 09:00, 1 hour"). They then query the model exactly
as they would any other model — e.g. "meetings starting between 1 March and 31 March" — and
receive one model instance per occurrence in that window, each carrying the series' attributes
with its own computed start and end time. No new query methods, scopes, or call syntax are
needed.

**Why this priority**: This is the core value of the library. Without transparent expansion
of series into occurrences, nothing else is meaningful.

**Independent Test**: Create one series with a weekly rule and one plain (non-recurring) record,
run an ordinary time-bounded query, and verify the result contains the plain record plus exactly
the expected occurrences with correct start/end values.

**Acceptance Scenarios**:

1. **Given** a `Meeting` series starting Monday 2026-03-02 09:00–10:00 with rule "weekly on
   Monday", **When** the developer queries meetings whose start is between 2026-03-01 and
   2026-03-31 using standard query constraints, **Then** exactly 5 meeting instances are
   returned, starting 2, 9, 16, 23 and 30 March at 09:00, each ending at 10:00 the same day and
   carrying the series' other attribute values (title, location, owner, …).
2. **Given** the same series, **When** any returned instance is inspected, **Then** it reports
   that it is not stored, has no primary key of its own, and exposes the series it belongs to and
   its original occurrence start.
3. **Given** a plain `Meeting` record with no recurrence rule, **When** the same query runs,
   **Then** the plain record is returned once, unchanged, as a normal stored model.
4. **Given** a series and a query that also filters on a non-time attribute (e.g. location =
   "Room A"), **When** the query runs, **Then** occurrences are included or excluded by
   evaluating that constraint against each occurrence's attribute values, exactly as if each
   occurrence were a stored row.
5. **Given** a series with rule "daily, 3 times", **When** a query covers a window much larger
   than the series, **Then** exactly 3 occurrences are returned.
6. **Given** two different model types (e.g. `Meeting` and `Shift`) both declared recurring,
   **When** each has series with rules, **Then** each model's queries return only its own
   occurrences, and all rules are held in the single shared rule store.

---

### User Story 2 - Paginate, count, order and aggregate across occurrences (Priority: P1)

A developer builds a calendar list view using the framework's normal pagination, ordering and
counting features on a recurring model. Pages, totals, ordering and "is there a next page"
answers are all computed over the expanded set of occurrences, not over the stored rows.

**Why this priority**: The request explicitly requires that "querying, paginating, etc." work as
for a regular model. Listing occurrences without correct pagination is unusable in real views.

**Independent Test**: Create several series and plain records whose occurrences interleave in
time, paginate an ordered, time-bounded query, and verify page contents, totals and ordering
against a hand-computed expected list.

**Acceptance Scenarios**:

1. **Given** series A (daily 08:00) and series B (daily 12:00) and a plain record on day 3 at
   10:00, **When** the developer requests page 2 with 4 items per page of records in days 1–5
   ordered by start ascending, **Then** the page contains items 5–8 of the merged, start-ordered
   list of all 11 items and the reported total is 11.
2. **Given** the same data, **When** the developer counts the records in days 1–5, **Then** the
   count is 11; **When** they ask whether any record exists on day 6, **Then** the answer is no.
3. **Given** the same data, **When** the developer requests the first record ordered by start
   descending within days 1–5, **Then** they receive series B's occurrence on day 5 at 12:00.
4. **Given** a query ordered by a non-time attribute (e.g. title) then by start, **When** it runs,
   **Then** occurrences are ordered by those attributes exactly as stored rows would be.
5. **Given** a large expanded result, **When** the developer processes it in chunks using the
   framework's standard chunking/lazy-iteration features, **Then** every occurrence is visited
   exactly once.
6. **Given** the current time is fixed at 2026-03-01 00:00, the default 1-year horizon, and a
   series repeating daily at 09:00 from 2026-03-01 with no end, **When** the developer counts or
   paginates the model with no time constraints, **Then** the total is 365 (1 March 2026 through
   28 February 2027).
7. **Given** the same series, **When** the developer queries records starting on or after
   2030-01-01 00:00 with no upper bound, **Then** exactly 365 occurrences are returned, from
   1 January 2030 through 31 December 2030 (the horizon ends at 2031-01-01 00:00).

---

### User Story 3 - Edit a single occurrence (Priority: P2)

A developer loads an occurrence (e.g. the 16 March meeting), changes something about it (moves
it to 11:00, changes its location), and saves it using the model's normal save/update
operations. The change is persisted as an exception for that occurrence only; the rest of the
series is unaffected, and every later query shows the edited version in place of the original.

**Why this priority**: Editing a single instance is the explicitly requested write behaviour and
is the most common real-world modification of recurring items.

**Independent Test**: Edit and save one virtual occurrence, then re-run the original query and
verify the edited occurrence appears exactly once with the new values, all other occurrences are
unchanged, and the series' own stored record is unchanged.

**Acceptance Scenarios**:

1. **Given** the weekly Monday series, **When** the developer changes the 16 March occurrence's
   location to "Room B" and saves it, **Then** a new stored record exists for that occurrence,
   linked to the series and to its original start (16 March 09:00), and the series' own record
   and rule attributes are unchanged.
2. **Given** that saved exception, **When** the March query is re-run, **Then** 5 instances are
   returned; the 16 March instance is the stored exception (location "Room B") and the other 4
   are virtual occurrences with the series' location.
3. **Given** the weekly series, **When** the developer moves the 16 March occurrence to
   18 March 14:00 and saves it, **Then** a query for 16 March returns nothing from this series
   and a query for 18 March returns the moved exception.
4. **Given** a stored exception, **When** the developer edits and saves it again, **Then** the
   existing exception is updated in place; no additional record is created.
5. **Given** a virtual occurrence, **When** the developer uses the model's mass-update operation
   on that single instance (e.g. `update([...])`), **Then** it behaves exactly like changing the
   attributes and saving: an exception is persisted.
6. **Given** a virtual occurrence, **When** the developer changes attributes but never saves,
   **Then** nothing is persisted and subsequent queries are unaffected.

---

### User Story 4 - Cancel an occurrence and manage the whole series (Priority: P3)

A developer deletes one occurrence (e.g. the meeting is cancelled for a public holiday) using the
model's normal delete operation, edits the series as a whole, or deletes the whole series.

**Why this priority**: Completes the life-cycle of recurring records; needed for production use
but builds on Stories 1–3.

**Independent Test**: Delete a virtual occurrence and an exception, edit and then delete the
series, verifying query results after each step.

**Acceptance Scenarios**:

1. **Given** the weekly series, **When** the developer deletes the 23 March virtual occurrence,
   **Then** subsequent queries no longer return a 23 March occurrence and no exception record is
   created for it.
2. **Given** a stored exception for 16 March, **When** the developer deletes it, **Then** the
   16 March occurrence is cancelled (it does not revert to the original series values).
3. **Given** the series' own stored record, **When** the developer changes a non-time attribute
   (e.g. title) and saves it, **Then** every virtual occurrence reflects the new title, while
   existing exceptions keep their own values.
4. **Given** a series with exceptions, **When** the developer deletes the series' own record,
   **Then** its recurrence rule, all its exceptions and all its occurrences disappear from
   every subsequent query.
5. **Given** a series, **When** the developer replaces or removes its recurrence rule,
   **Then** subsequent queries reflect the new rule, or return the record once as a plain record
   when the rule is removed.
6. **Given** the weekly Monday series with a stored exception for 16 March (location "Room B"),
   **When** the developer changes the rule to "weekly on Tuesday", **Then** the March query
   returns the Tuesday occurrences (3, 10, 17, 24, 31 March) plus the former exception on
   16 March as a plain record with location "Room B", no longer linked to the series.
7. **Given** the same series and exception, **When** the developer changes only the series' title,
   **Then** the exception stays linked and the 16 March occurrence is still replaced by it.

---

### Edge Cases

- **Unbounded window on an infinite rule**: A query with no derivable upper time bound against a
  series without COUNT/UNTIL expands only up to the configured horizon (FR-008). Because the
  default horizon is measured from the current time, results of such queries depend on the clock;
  tests of this behaviour must control the clock.
- **Detached exceptions next to new occurrences**: After a rule change, a former exception may sit
  at the same time as a newly generated occurrence; both are returned, because the former
  exception is now an independent plain record (FR-022).
- **Window derivable only partially**: Derived time bounds must be conservative: time constraints
  combined with OR groups, raw expressions or constraints on unrelated attributes must never cause
  an occurrence that satisfies the query to be omitted, except by the horizon of FR-008 when no
  upper bound can be derived; at worst more occurrences are evaluated and then filtered.
- **Runaway generation**: A rule or query window that would require generating more occurrences of
  a single series than the configured per-series limit fails with a descriptive error instead of
  exhausting time or memory.
- **Overlap vs. start-in-window**: A query that constrains the end attribute (e.g. "ends after
  09:30") must match occurrences by their own computed end, so occurrences starting before the
  window but still running inside it are returned when the constraints say so.
- **Rule yields no occurrences** (e.g. "30 February yearly", or UNTIL before start): the series
  contributes nothing to queries; it is still a valid stored record retrievable by its key.
- **Invalid rule input**: Malformed or unsupported rule definitions are rejected when assigned or
  saved, with an error naming the invalid component; nothing is persisted.
- **Moved exception collides** with another occurrence of the same series: both are returned.
- **Exception moved outside the original window**: It appears according to its new times, not its
  original start.
- **Daylight-saving transitions**: Occurrences keep the series' local wall-clock time
  (09:00 stays 09:00 across DST changes in the series' time zone).
- **Retrieving by primary key**: Looking up the series' key returns the series' own stored
  record; looking up an exception's key returns the exception.
- **Attaching a rule to an exception**: Rejected; an exception is a single occurrence and cannot
  itself recur.
- **Bulk query-level updates/deletes** (e.g. "update all meetings where … set …" executed directly
  against the query): Operate on stored records only (series records, exceptions, plain records),
  never on virtual occurrences, and must not silently create exceptions.
- **Soft-deletable recurring models**: Soft-deleting a series hides all its occurrences and
  exceptions; restoring it brings them back. Soft-deleting an exception cancels that occurrence.
- **Eager-loaded relationships**: Relationships keyed on the model's own foreign keys (e.g. an
  owner) load on virtual occurrences from the series' values. Relationships keyed on the model's
  own primary key (e.g. attendees) are empty on virtual occurrences, because they have no key of
  their own; they are reached through the occurrence's series. Exceptions load their own.

## Requirements *(mandatory)*

### Functional Requirements

**Declaration & storage**

- **FR-001**: Developers MUST be able to make any model recurring by adding a single reusable
  capability (trait) to it and declaring which attribute holds the start time and, optionally,
  which holds the end time.
- **FR-002**: Recurrence MUST be declarative: a record becomes a series by having a recurrence
  rule attached, and the rule is the single source of truth for its occurrences. Occurrences MUST
  never be stored as records of the recurring model; the library MAY keep a derived occurrence
  index that it rebuilds from the rule whenever the rule, the series start or its duration changes.
- **FR-003**: Recurrence rules MUST be stored separately from the recurring model's own table, in
  a single store shared by all recurring model types, each rule linked polymorphically to exactly
  one owning record.
- **FR-004**: Developers MUST be able to assign, replace and remove a record's rule as part of the
  record's normal create/update operations, providing it either as an RFC 5545 RRULE string or as
  an equivalent structured definition.
- **FR-005**: The library MUST support all RFC 5545 RRULE components (FREQ, INTERVAL, COUNT,
  UNTIL, BYSECOND, BYMINUTE, BYHOUR, BYDAY, BYMONTHDAY, BYYEARDAY, BYWEEKNO, BYMONTH, BYSETPOS,
  WKST) and a per-series list of excluded occurrence starts.
- **FR-006**: Rule input crossing into the library MUST be validated; invalid rules MUST be
  rejected with a descriptive error before anything is persisted.

**Querying**

- **FR-007**: All standard query operations on a recurring model — retrieving collections, first
  result, counting, existence checks, aggregates (min/max/sum/avg), length-aware pagination,
  simple pagination, chunking and lazy iteration — MUST operate over the expanded set of
  occurrences plus plain records, with no library-specific query syntax required.
- **FR-008**: Time bounds for expansion MUST be derived from the query's ordinary constraints on
  the declared start/end attributes. When no upper bound can be derived for a series whose rule
  has no end, the library MUST expand occurrences only up to a configurable horizon: by default
  1 year after the query's derived lower time bound, or 1 year after the current time when no
  lower bound can be derived. The horizon length MUST be configurable application-wide.
  Occurrences beyond the horizon are not returned and not counted.
- **FR-009**: Every query constraint and ordering MUST be evaluated against each occurrence's own
  attribute values (series attributes with the occurrence's computed start/end), so results are
  identical to what the same query would return if every occurrence were a stored row.
- **FR-010**: Each occurrence's end MUST equal its start plus the series' duration (series end
  minus series start); when no end attribute is declared, occurrences carry only a start.
- **FR-011**: Occurrences MUST be computed in the series' time zone (defaulting to the
  application time zone) and preserve local wall-clock time across daylight-saving transitions.
- **FR-012**: Virtual occurrences MUST be fully hydrated instances of the recurring model —
  with all series attributes, casts, accessors and requested relationships — that report as not
  stored, have no primary key of their own, and expose their series and original start.
- **FR-013**: Where a stored exception exists for an occurrence, queries MUST return the exception
  in place of that occurrence; excluded and cancelled occurrences MUST never be returned.
- **FR-014**: Records of a recurring model without a rule MUST behave exactly as ordinary model
  records in all queries.
- **FR-015**: Retrieving a record by its primary key MUST return that stored record (series,
  exception or plain record) without expansion.
- **FR-016**: Ordering and pagination results MUST be deterministic: occurrences that compare
  equal on all requested orderings MUST be returned in a stable order across repeated queries.

**Writing**

- **FR-017**: Saving a virtual occurrence through the model's normal save or update operations
  MUST persist it as a new stored record of the same model (an exception) linked to its series and
  original start, and MUST exclude that original start from the series so it is not generated
  again.
- **FR-018**: Saving a stored exception MUST update it in place like any ordinary record.
- **FR-019**: Deleting a virtual occurrence through the model's normal delete operation MUST
  cancel that occurrence (exclude its original start) without persisting a record for it.
- **FR-020**: Deleting a stored exception MUST cancel its occurrence; it MUST NOT revert to the
  series' values.
- **FR-021**: Changes to the series' own record MUST be reflected in all its virtual occurrences;
  exceptions MUST keep their own values.
- **FR-022**: When a series' rule or start time is replaced or its rule removed while exceptions
  exist, the library MUST keep every existing exception as a stored record but detach it from the
  series, so it becomes an ordinary plain record that keeps its own values. The exclusions that
  existed only because of those exceptions MUST be released, so the new rule's occurrences are
  generated normally. Changes to the series' other attributes MUST NOT detach exceptions.
- **FR-023**: Deleting (or soft-deleting) a series MUST remove (or hide) its rule, exceptions and
  occurrences; restoring a soft-deleted series MUST restore them.
- **FR-024**: Persisting and deleting via occurrences MUST fire the model's standard life-cycle
  events as the equivalent operation on an ordinary record would (creation for a new exception,
  update for an existing one, deletion for a deleted record).
- **FR-025**: Writing operations that touch several stored records (exception plus series
  exclusion) MUST be atomic: either all changes persist or none do.
- **FR-026**: Query-level bulk updates and deletes MUST act on stored records only and MUST NOT
  create exceptions or cancel occurrences.

**Packaging**

- **FR-027**: The library MUST be distributed as an installable package for the host framework,
  scaffolded from the package-skeleton-laravel template, shipping its storage migration,
  publishable configuration, and documentation of installation and usage.
- **FR-028**: The recurring capability MUST expose a series' stored rule as an `RRule\RRule`
  object through a model cast, so `humanReadable()` and all `RRuleInterface` methods are callable
  on it; assigning a string, structured definition or `RRule` object through the same attribute
  MUST set the rule (FR-004).

### Key Entities

- **Recurring model record**: Any record of an application model that has opted in. Holds the
  declared start/end attributes and all other business attributes. Plays one of three roles: plain
  record (no rule), series (has a rule), or exception (replaces one occurrence of a series).
- **Recurrence rule**: Belongs to exactly one series of any model type. Holds the RRULE
  definition, the time zone used for evaluation, and the set of excluded occurrence starts.
- **Exception link**: Associates an exception record with its series and the original start of the
  occurrence it replaces. At most one exception per series per original start.
- **Occurrence (virtual)**: Not stored. Identified by its series plus original start; carries the
  series' attributes with computed start/end.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A developer can take an existing model, make it recurring, attach a rule and list
  its occurrences in a paginated view in under 15 minutes by following the package
  documentation, writing no query code that differs from what they would write for a
  non-recurring model.
- **SC-002**: For every acceptance scenario above, the results (contents, order, counts, page
  totals) are identical to those obtained by manually materialising every occurrence as a stored
  row and running the same query against it.
- **SC-003**: With 1,000 series each repeating daily and a one-year time window, a developer
  obtains any page of 25 occurrences, including the total count, in under 1 second on a typical
  development machine.
- **SC-004**: 100% of single-occurrence edits and cancellations are reflected in the very next
  query, and leave every other occurrence of the series unchanged.
- **SC-005**: No query on a recurring model, bounded or not, fails to terminate or exhausts memory
  because of an infinite rule.

## Assumptions

- **Platform**: Laravel 13.x (the latest major at planning time) only, on the PHP versions
  required by the package-skeleton-laravel template; package name `groundhog`, vendor
  `boysfromthefactory` (from the repository remote).
- **Rule engine**: Occurrence calculation relies on the `rlanvin/php-rrule` library as requested;
  RDATE (explicit additional dates) and multiple rules per series are out of scope.
- **Exception storage**: Exceptions are stored as rows of the recurring model's own table, so
  they participate in ordinary queries, relationships and events like any other record.
- **Saving without changes**: Saving a virtual occurrence persists an exception even if no
  attribute changed, matching the framework's rule that saving a non-stored instance creates
  it; this also lets developers attach occurrence-specific related records.
- **Edit scope**: Only "this occurrence" and "the whole series" edits are supported;
  "this and all following occurrences" (series splitting) is out of scope.
- **Cursor pagination**: Out of scope for the first release; length-aware and simple pagination
  are in scope.
- **Sharing of relations**: Virtual occurrences have no primary key, so relationships keyed on the
  model's own primary key are empty on them; developers reach such data through the occurrence's
  series. Foreign-key relationships (belongs-to) resolve normally from the copied series values.
- **Calendar interchange**: Import/export of iCalendar files and reminder/notification features are
  out of scope.
