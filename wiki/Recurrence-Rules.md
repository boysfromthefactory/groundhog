This page explains how to attach an RFC 5545 recurrence rule to a record, which rule parts are supported, how time zones work, and how to read, replace and remove a rule. Read it when you create or change series. Rules are parsed and expanded by [rlanvin/php-rrule](https://github.com/rlanvin/php-rrule).

## Assigning a rule

A record becomes a series when you assign the `recurrence_rule` attribute and save it. The rule and the record are saved in the same transaction. The rule accepts four forms:

```php
// 1. An RRULE string
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

// 2. A string with a DTSTART;TZID line (see the next section)
'recurrence_rule' => "DTSTART;TZID=Europe/Budapest:20260302T090000\nRRULE:FREQ=WEEKLY;BYDAY=MO",

// 3. A php-rrule parts array
'recurrence_rule' => ['FREQ' => 'WEEKLY', 'BYDAY' => 'MO'],

// 4. An RRule instance
'recurrence_rule' => new \RRule\RRule(['FREQ' => 'WEEKLY', 'BYDAY' => 'MO']),
```

The RRULE string, the parts array and the `RRule` instance give the same result for the reference series:

```php
Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get();
// => starts 2026-03-02 09:00, 03-09 09:00, 03-16 09:00, 03-23 09:00, 03-30 09:00
```

The first occurrence and the duration of every occurrence always come from the record's own start and end columns. A `DTSTART` in the input contributes only its time zone; its date and time are ignored. Each saved series stores exactly one rule row.

You can also assign the attribute and call `save()`, or `saveQuietly()`; both store the rule. Assigning a rule to a virtual occurrence or an exception throws `RecurrenceNotSupported` (see [Rules on exceptions are rejected](Editing-Occurrences#rules-on-exceptions-are-rejected)), and saving a series without a start throws the same exception.

## Time zones and daylight saving

Without a `DTSTART;TZID` line, the rule runs in the application time zone (`config('app.timezone')`, `UTC` by default). With one, the stored rule takes that line's zone:

```php
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => "DTSTART;TZID=Europe/Budapest:20260302T090000\nRRULE:FREQ=WEEKLY;BYDAY=MO",
]);

\BoysFromTheFactory\Groundhog\Models\Recurrence::sole()->timezone;
// => 'Europe/Budapest'
```

The date-time still comes from `starts_at`, read in the application time zone. With an application time zone of `UTC`, a start of `2026-03-02 09:00:00` is 10:00 in Budapest, and the occurrences fall on Mondays at 10:00 Budapest time.

Occurrences keep their local wall-clock time in the rule's zone across daylight-saving changes. Central European Summer Time begins on 29 March 2026. This daily series starts at 08:00 UTC, which is 09:00 in Budapest:

```php
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-26 08:00:00',
    'ends_at' => '2026-03-26 09:00:00',
    'recurrence_rule' => "DTSTART;TZID=Europe/Budapest:20260326T090000\nRRULE:FREQ=DAILY;COUNT=6",
]);

Meeting::orderBy('starts_at')->get()
    ->map(fn (Meeting $m) => $m->starts_at->setTimezone('Europe/Budapest')->format('m-d H:i'))
    ->all();
// => ['03-26 09:00', '03-27 09:00', '03-28 09:00', '03-29 09:00', '03-30 09:00', '03-31 09:00']
```

Every occurrence also ends at 10:00 Budapest time, before and after the change.

## Supported RRULE parts

All 14 RRULE parts of RFC 5545 are supported:

| Part | Example |
|---|---|
| `FREQ` | `FREQ=WEEKLY` (`SECONDLY` to `YEARLY`) |
| `INTERVAL` | `FREQ=DAILY;INTERVAL=3` |
| `COUNT` | `FREQ=DAILY;COUNT=5` |
| `UNTIL` | `FREQ=DAILY;UNTIL=20260320T000000Z` |
| `BYSECOND` | `FREQ=MINUTELY;BYSECOND=0,30` |
| `BYMINUTE` | `FREQ=HOURLY;BYMINUTE=0,15,45` |
| `BYHOUR` | `FREQ=DAILY;BYHOUR=9,17` |
| `BYDAY` | `FREQ=MONTHLY;BYDAY=1MO,-1FR` |
| `BYMONTHDAY` | `FREQ=MONTHLY;BYMONTHDAY=1,15,-1` |
| `BYYEARDAY` | `FREQ=YEARLY;BYYEARDAY=1,100,200` |
| `BYWEEKNO` | `FREQ=YEARLY;BYWEEKNO=10,20;BYDAY=MO` |
| `BYMONTH` | `FREQ=YEARLY;BYMONTH=3,6,9;BYMONTHDAY=2` |
| `BYSETPOS` | `FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1` |
| `WKST` | `FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,SU;WKST=SU` |

Every part survives storage unchanged: the stored rule generates the same occurrences as the input.

A rule is a single RRULE. The input may contain only a `DTSTART` line and an `RRULE` line. `EXDATE`, `RDATE` and `EXRULE` lines throw `InvalidRecurrenceRule`, because they would turn the rule into a set:

```php
$meeting->recurrence_rule = "RRULE:FREQ=DAILY\nEXDATE:20260303T090000Z";
// => throws InvalidRecurrenceRule: Invalid recurrence rule: unsupported line "EXDATE:20260303T090000Z";
//    only DTSTART and RRULE are accepted.
```

To skip or change single dates, cancel or edit occurrences instead: see [Cancelling Occurrences](Cancelling-Occurrences) and [Editing Occurrences](Editing-Occurrences). The [Limitations](Limitations) page lists the alternatives.

## Validation errors

A rule is validated when you assign it, before anything is saved. Invalid input throws `InvalidRecurrenceRule`, and its message names the offending part:

```php
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=XX',
]);
// => throws InvalidRecurrenceRule: Invalid recurrence rule "FREQ=WEEKLY;BYDAY=XX": followed by
//    php-rrule's message naming BYDAY
```

Result: no `meetings` row and no rule row are written. See [InvalidRecurrenceRule](Errors#invalidrecurrencerule) for every cause.

A valid rule can still be rejected on save when it generates too many occurrences; see [max_occurrences_per_series](Configuration#max_occurrences_per_series).

## Reading the rule

Reading `recurrence_rule` returns an `RRule\RRule`, so every php-rrule method works. For the reference series:

```php
$rule = Meeting::find($series->id)->recurrence_rule;

$rule->humanReadable(['locale' => 'en']);
// => starts with "weekly on Monday"

$rule->getOccurrencesBetween('2026-03-01', '2026-03-31');
// => 5 dates: 2, 9, 16, 23 and 30 March 2026 at 09:00

$rule->getRule()['DTSTART']->format('Y-m-d H:i');
// => '2026-03-02 09:00'
```

`humanReadable()` without a `locale` option uses PHP's default locale (`Locale::getDefault()` when `ext-intl` is installed), not your Laravel application locale; pass `['locale' => app()->getLocale()]` to follow the application. Without `ext-intl`, translations fall back to English.

A virtual occurrence returns its series' rule. An exception (a saved occurrence) has no rule of its own and returns `null`. Plain records also return `null`.

```php
occurrenceOn('2026-03-09')->recurrence_rule?->humanReadable(['locale' => 'en']);
// => starts with "weekly on Monday"

// after the 16 March occurrence was saved as an exception
occurrenceOn('2026-03-16')->recurrence_rule;
// => null
```

Here `occurrenceOn($date)` stands for `Meeting::whereBetween('starts_at', ["$date 00:00:00", "$date 23:59:59"])->sole()`.

The rule reflects the rule only. Cancellations and exceptions are not applied to it, so `getOccurrencesBetween()` still lists a cancelled 23 March. To get the occurrences as they really are, query the model: see [Querying Occurrences](Querying-Occurrences).

## Replacing and removing a rule

Assign a different rule to replace the occurrences:

```php
$series->recurrence_rule = 'FREQ=WEEKLY;BYDAY=TU';
$series->save();

Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get();
// => starts 2026-03-03 09:00, 03-10 09:00, 03-17 09:00, 03-24 09:00, 03-31 09:00
```

Changing the rule detaches the series' exceptions and discards its cancellations; assigning the identical rule again changes nothing. See [Changing the rule or the start](Managing-a-Series#changing-the-rule-or-the-start).

Assign `null` to turn the series back into a plain record:

```php
$series->recurrence_rule = null;
$series->save();

Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])->get()->modelKeys();
// => [$series->id]: the record is returned once, as a stored model
```

Result: the rule row is deleted. See [Removing the rule](Managing-a-Series#removing-the-rule).

## The stored rule

Each series has one row in `groundhog_recurrences`, available through the `recurrence()` relation as a `BoysFromTheFactory\Groundhog\Models\Recurrence` model. It holds the RFC 5545 text, the time zone and whether the rule is infinite (no `COUNT` or `UNTIL`):

```php
$series = Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

$series->recurrence->rule;
// => "DTSTART:20260302T090000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO"

$series->recurrence->timezone;
// => 'UTC'

$series->recurrence->is_infinite;
// => true

$series->update(['recurrence_rule' => null]);

Meeting::find($series->id)->recurrence;
// => null
```

Use the relation for reading only, for example to display or export the rule text. Change rules through `recurrence_rule`: it validates the input and keeps exceptions and cancellations consistent, which writing to the `Recurrence` model directly does not.

Next: [Querying Occurrences](Querying-Occurrences)
