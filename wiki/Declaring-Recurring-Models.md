This page shows how to make an Eloquent model recurring with the `HasRecurrence` trait. Read it before you attach rules to records: it covers column names, models without an end, mass assignment, soft deletes and custom builders.

## Adding the trait

Add `HasRecurrence` to the model. The trait registers a global scope that expands every series into its occurrences, and a cast for the `recurrence_rule` attribute. Your table needs no new columns: the rule and its occurrences live in the package's own tables.

The examples in this wiki use this `Meeting` model:

```php
<?php

namespace App\Models;

use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use HasRecurrence;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }
}
```

The `meetings` table has `title`, `location`, `capacity`, `room_id`, `starts_at`, `ends_at`, timestamps and `deleted_at`. Create a series by giving a record a rule, then query it like any other model:

```php
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00:00',
    'ends_at' => '2026-03-02 10:00:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);

Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get();
// => 5 meetings titled "Standup" in "Room A", starting 2, 9, 16, 23 and 30 March at 09:00
//    and ending at 10:00 on the same days
```

## Start and end columns

By default the trait reads each occurrence's start from `starts_at` and its end from `ends_at`. If your columns have other names, declare them with the `RECURRENCE_STARTS_AT` and `RECURRENCE_ENDS_AT` constants:

```php
class Booking extends Model
{
    use HasRecurrence;

    public const RECURRENCE_STARTS_AT = 'begins_at';
    public const RECURRENCE_ENDS_AT = 'finishes_at';
}
```

The series record's own start is the first occurrence, and the difference between its start and end is the duration of every occurrence. Cast both columns to `datetime` so you get Carbon instances back.

## Models without an end

When a model has no end column, set `RECURRENCE_ENDS_AT` to `null`. Occurrences then carry only a start.

```php
class Shift extends Model
{
    use HasRecurrence;

    public const RECURRENCE_ENDS_AT = null;

    protected $fillable = ['label', 'starts_at', 'recurrence_rule'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }
}
```

```php
Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=DAILY;COUNT=2']);

$shifts = Shift::orderBy('starts_at')->get();
// => 2 shifts starting 2026-03-02 06:00 and 2026-03-03 06:00

array_key_exists('ends_at', $shifts->first()->getAttributes());
// => false
```

## Mass assignment

`recurrence_rule` is an attribute like any other, so mass assignment rules apply to it. A model that uses `$fillable` must list `recurrence_rule`; a model with `$guarded = []`, such as `Meeting`, accepts it as is.

```php
// Shift lists 'recurrence_rule' in $fillable
Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=DAILY;COUNT=3']);

Shift::orderBy('starts_at')->get();
// => shifts starting 2026-03-02 06:00, 2026-03-03 06:00 and 2026-03-04 06:00
```

If you leave `recurrence_rule` out of `$fillable`, Laravel discards it during `create()` and the record is saved as a plain, non-recurring record. You can still assign it directly: `$shift->recurrence_rule = 'FREQ=DAILY'; $shift->save();`.

## Soft deletes

Use Laravel's `SoftDeletes` trait next to `HasRecurrence`, as `Meeting` does. Nothing else is needed. Soft-deleting a series hides it and its occurrences until you restore it, and soft-deleting an exception keeps its occurrence cancelled while it is trashed. See [Soft deleting and restoring a series](Managing-a-Series#soft-deleting-and-restoring-a-series) and [Deleting an exception (hard vs soft)](Cancelling-Occurrences#deleting-an-exception-hard-vs-soft).

## Custom Eloquent builders

Recurring models use `BoysFromTheFactory\Groundhog\Query\RecurringBuilder`, which keeps key lookups and query-level writes on stored rows. If you declare your own builder, it must extend `RecurringBuilder`:

```php
use BoysFromTheFactory\Groundhog\Query\RecurringBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

class MeetingBuilder extends RecurringBuilder
{
    public function inRoomA(): static
    {
        return $this->where('location', 'Room A');
    }
}

#[UseEloquentBuilder(MeetingBuilder::class)]
class Meeting extends Model
{
    use HasRecurrence;
}
```

```php
Meeting::query();
// => an instance of MeetingBuilder

Meeting::whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])->count();
// => 5 for the weekly Monday "Standup" series
```

A builder that extends Laravel's plain `Illuminate\Database\Eloquent\Builder` instead throws `IncompatibleEloquentBuilder` as soon as a query is started, with a message like "The Eloquent builder App\Models\MeetingBuilder of recurring model App\Models\Meeting must extend BoysFromTheFactory\Groundhog\Query\RecurringBuilder." See [IncompatibleEloquentBuilder](Errors#incompatibleeloquentbuilder).

Next: [Recurrence Rules](Recurrence-Rules)
