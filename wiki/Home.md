Groundhog makes Eloquent models recurring: add one trait, attach an RFC 5545 rule to a record,
and every ordinary query returns one model instance per occurrence. This wiki explains what the
package does, gets you to a first result in a few minutes, and then covers every feature with
examples.

## What Groundhog does

Recurring events are awkward to store. Writing one row per occurrence fills the table with
copies that all have to change when the schedule changes, and an endless rule cannot be stored
that way at all. Computing occurrences in PHP instead breaks the things Eloquent is good at:
`where` constraints, ordering, pagination totals and aggregates no longer see the occurrences.

Groundhog keeps one record per series plus its rule, and lets the database see the occurrences
as if they were rows. You keep writing normal Eloquent:

```php
Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])
    ->where('location', 'Room A')
    ->orderBy('starts_at')
    ->paginate(25);
// => one Meeting per occurrence in March, plus ordinary one-off meetings
```

Change one occurrence and save it, and only that occurrence changes; delete one, and only that
occurrence disappears. The rule itself is parsed and expanded by
[rlanvin/php-rrule](https://github.com/rlanvin/php-rrule).

## When to use it

Groundhog fits models whose records repeat on a schedule and whose single instances sometimes
move or get cancelled: meetings, shifts, classes, room bookings, recurring tasks or reminders.

It is not the right tool when you need:

- "this and all following occurrences" edits (splitting a series);
- explicit extra dates (RDATE) or excluded dates written into the rule (EXDATE);
- iCalendar (`.ics`) import or export;
- SQL Server, or cursor pagination.

See [Limitations](Limitations) for these and their workarounds.

## Supported platforms

- PHP 8.4 or newer, Laravel 13
- SQLite 3.35+, MySQL 8.4 or PostgreSQL 14+
- `ext-intl` is recommended for localised rule descriptions

## Core concepts

- **Recurring model**: a model class that uses the `HasRecurrence` trait, such as `Meeting`.
- **Series**: a stored record of a recurring model that has a recurrence rule. Its own start and
  end define the first occurrence and the length of every occurrence.
- **Recurrence rule**: when a series repeats, written in the RFC 5545 RRULE vocabulary, for
  example `FREQ=WEEKLY;BYDAY=MO` for "every Monday".
- **Occurrence (virtual occurrence)**: one instance of a series at one computed start time.
  Queries return it as a full model instance that is not stored in the database and has no
  primary key.
- **Exception**: a stored record that replaces exactly one occurrence of a series. You create one
  by changing an occurrence and saving it.
- **Cancellation**: an occurrence removed from its series without a replacement. You create one
  by deleting an occurrence.
- **Horizon**: how far ahead a rule without an end is expanded when a query gives no upper time
  bound; one year by default.

## Contents

**Start**

- [Quick Start](Quick-Start): install, create a series, list, edit and cancel occurrences

**Guide**

- [Declaring Recurring Models](Declaring-Recurring-Models): the trait, columns, soft deletes, custom builders
- [Recurrence Rules](Recurrence-Rules): assigning, validating, reading and replacing rules
- [Querying Occurrences](Querying-Occurrences): time windows, the horizon, relationships
- [Pagination and Collections](Pagination-and-Collections): paginating, counting, ordering, chunking
- [Editing Occurrences](Editing-Occurrences): turning one occurrence into an exception
- [Cancelling Occurrences](Cancelling-Occurrences): removing one occurrence
- [Managing a Series](Managing-a-Series): changing or deleting the whole series
- [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes): key lookups, route binding, bulk updates
- [Identifying Occurrences](Identifying-Occurrences): addressing an occurrence in APIs and forms

**Reference**

- [Configuration](Configuration): horizon and safety limits
- [Errors](Errors): every exception, its causes and fixes
- [Limitations](Limitations): what is not supported and what to do instead
- [How It Works](How-It-Works): how queries see occurrences, and performance per database

**Contributing**

- [Maintaining the Wiki](Maintaining-the-Wiki): editing and publishing these pages
