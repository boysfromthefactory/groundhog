From installation to a weekly meeting whose occurrences you can list, edit and cancel. Follow the
steps in order in a Laravel 13 application; each step shows what you should see.

## 1. Install the package

```bash
composer require boysfromthefactory/groundhog
```

## 2. Publish and run the migration

```bash
php artisan vendor:publish --tag="groundhog-migrations"
php artisan migrate
```

Result: three new tables, `groundhog_recurrences` (one rule per series), `groundhog_occurrences`
(the occurrences each rule generates) and `groundhog_exclusions` (edited and cancelled
occurrences). Your own tables need no new columns.

Optionally publish the configuration to change the defaults described in
[Configuration](Configuration):

```bash
php artisan vendor:publish --tag="groundhog-config"
```

## 3. Make a model recurring

Groundhog works with any model that has a start and an end column, named `starts_at` and
`ends_at` by default. If you do not have such a model yet, create one:

```php
// database/migrations/2026_03_01_000000_create_meetings_table.php
Schema::create('meetings', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->string('location')->nullable();
    $table->dateTime('starts_at');
    $table->dateTime('ends_at');
    $table->timestamps();
});
```

Then add the `HasRecurrence` trait, and list `recurrence_rule` in `$fillable`:

```php
<?php

namespace App\Models;

use BoysFromTheFactory\Groundhog\Concerns\HasRecurrence;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasRecurrence;

    protected $fillable = ['title', 'location', 'starts_at', 'ends_at', 'recurrence_rule'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
```

Run `php artisan migrate` again if you added the migration.

## 4. Create a series

A record becomes a series when you give it a recurrence rule. Its own start and end are the first
occurrence:

```php
Meeting::create([
    'title' => 'Standup',
    'location' => 'Room A',
    'starts_at' => '2026-03-02 09:00',
    'ends_at' => '2026-03-02 10:00',
    'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
]);
```

Result: one `meetings` row and its rule, "every Monday, 09:00–10:00, starting 2 March 2026".

## 5. Query and paginate the occurrences

Query the model exactly as you would any other:

```php
$meetings = Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->paginate(25);

$meetings->total(); // => 5

foreach ($meetings as $meeting) {
    echo $meeting->starts_at->format('D j M H:i').'–'.$meeting->ends_at->format('H:i')
        .' '.$meeting->title.', '.$meeting->location.PHP_EOL;
}
// Mon 2 Mar 09:00–10:00 Standup, Room A
// Mon 9 Mar 09:00–10:00 Standup, Room A
// Mon 16 Mar 09:00–10:00 Standup, Room A
// Mon 23 Mar 09:00–10:00 Standup, Room A
// Mon 30 Mar 09:00–10:00 Standup, Room A
```

Each item is a full `Meeting` with the series' attributes and its own start and end. It is not
stored: `$meeting->exists` is `false` and `$meeting->getKey()` is `null`.

## 6. Edit one occurrence

Change an occurrence and save it like any model:

```php
$meeting = Meeting::whereBetween('starts_at', ['2026-03-16', '2026-03-16 23:59:59'])->sole();

$meeting->location = 'Room B';
$meeting->save();

$meeting->exists;                  // => true
$meeting->isOccurrenceException(); // => true
```

Result: the 16 March meeting is now stored as an exception of the series. The series itself and
the other occurrences are unchanged.

## 7. Cancel one occurrence

```php
Meeting::whereBetween('starts_at', ['2026-03-23', '2026-03-23 23:59:59'])->sole()->delete();
```

Result: the 23 March meeting is cancelled. Nothing is stored for it; it simply stops being
generated.

## 8. Query again

Run the query from step 5 again:

```php
Meeting::whereBetween('starts_at', ['2026-03-01', '2026-03-31 23:59:59'])
    ->orderBy('starts_at')
    ->get()
    ->map(fn (Meeting $m) => $m->starts_at->format('j M').' '.$m->location.($m->exists ? ' (stored)' : ''));
// => ['2 Mar Room A', '9 Mar Room A', '16 Mar Room B (stored)', '30 Mar Room A']
```

That is the whole cycle: one series, five generated occurrences, one edited, one cancelled, all
through ordinary Eloquent calls.

## Where next

- [Declaring Recurring Models](Declaring-Recurring-Models): other column names, models without an end, soft deletes
- [Recurrence Rules](Recurrence-Rules): every way to write a rule, time zones, validation
- [Querying Occurrences](Querying-Occurrences): open-ended rules, relationships, joins
- [Editing Occurrences](Editing-Occurrences) and [Cancelling Occurrences](Cancelling-Occurrences): moving occurrences, events
- [Managing a Series](Managing-a-Series): what happens when the rule itself changes

Next: [Declaring Recurring Models](Declaring-Recurring-Models)
