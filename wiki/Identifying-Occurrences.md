A virtual occurrence has no primary key, so Groundhog gives every row two identity attributes that name the series and the start the rule generated. This page shows how to read them, send them to a client, and use them to find and edit the same occurrence in a later request.

The examples use the reference series: weekly Monday "Standup", "Room A", from 2026-03-02 09:00–10:00, with March occurrences on 2, 9, 16, 23 and 30 March.

## The identity attributes

Every row returned by a query carries two attributes:

- `groundhog_series_key`: the primary key of the series the row belongs to.
- `groundhog_original_starts_at`: the start the rule generated for this occurrence.

Together they identify one occurrence. They stay the same when the occurrence is edited or moved: an exception that moves 16 March to 18 March keeps `groundhog_original_starts_at` at 16 March 09:00. Both are `null` on plain records and on a series row itself.

```php
$occurrence = Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();

$occurrence->groundhog_series_key;      // => $series->id
$occurrence->originalOccurrenceStart(); // => CarbonImmutable 2026-03-16 09:00
```

Stored rows loaded without expansion, for example with `withoutOccurrences()->get()`, carry the same attributes: an exception has the series key, a plain record has `null`.

## In JSON / `toArray()`

`toArray()` and JSON serialisation include both attributes, so an API response already contains what a client needs to address the occurrence. A virtual occurrence of the reference series looks like this:

```json
{
    "id": null,
    "room_id": null,
    "title": "Standup",
    "location": "Room A",
    "capacity": null,
    "starts_at": "2026-03-16T09:00:00.000000Z",
    "ends_at": "2026-03-16T10:00:00.000000Z",
    "created_at": "2026-03-01T00:00:00.000000Z",
    "updated_at": "2026-03-01T00:00:00.000000Z",
    "deleted_at": null,
    "groundhog_series_key": 1,
    "groundhog_original_starts_at": "2026-03-16 09:00:00"
}
```

Here the series has key 1 and was created on 1 March 2026. The exact formatting of the two identity values comes from the database driver; send them back unchanged.

## Finding an occurrence again from its identity

Query by both attributes to get the same occurrence back. You get the virtual occurrence while it is unchanged, and the stored exception once it has been edited.

```php
$find = fn () => Meeting::where('groundhog_series_key', $series->id)
    ->where('groundhog_original_starts_at', '2026-03-16 09:00:00')
    ->sole();

$virtual = $find();
$virtual->exists;                          // => false
$virtual->starts_at->format('Y-m-d H:i'); // => '2026-03-16 09:00'

$virtual->update(['location' => 'Room B']);

$exception = $find();
$exception->exists;   // => true
$exception->location; // => 'Room B'
```

## Editing an occurrence from a form or API request

Have the client send back the two identity values, find the occurrence with the query above, and call `update()`. The same code edits a virtual occurrence (stored as a new exception) and an existing exception (updated in place).

```php
namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Http\Request;

class OccurrenceController extends Controller
{
    public function update(Request $request)
    {
        $identity = $request->validate([
            'series_key' => ['required', 'integer'],
            'original_starts_at' => ['required', 'date'],
        ]);

        $validated = $request->validate([
            'title' => ['sometimes', 'string'],
            'location' => ['sometimes', 'string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
        ]);

        $occurrence = Meeting::where('groundhog_series_key', $identity['series_key'])
            ->where('groundhog_original_starts_at', $identity['original_starts_at'])
            ->firstOrFail();

        $occurrence->update($validated);

        return $occurrence;
    }
}
```

Result: a request with `series_key` = the series key, `original_starts_at` = `2026-03-16 09:00:00` and `location` = `Room B` stores the 16 March occurrence as an exception in "Room B"; sending it again updates that exception. To cancel instead, call `delete()` on the found model; see [Cancelling Occurrences](Cancelling-Occurrences).

Route model binding cannot do this: it binds by primary key and returns the stored series (see [Stored Records and Bulk Writes](Stored-Records-and-Bulk-Writes#route-model-binding)).

## Stored rows streamed with `cursor()`

Stored rows streamed by `withoutOccurrences()->cursor()` do not get the identity attributes when they are loaded. They are resolved the first time you call `isOccurrenceException()`, `originalOccurrenceStart()` or `series`, and kept on the model from then on.

```php
$exception = Meeting::withoutOccurrences()
    ->where('title', 'Standup')
    ->cursor()
    ->first(fn (Meeting $m) => $m->id === $exceptionId);

array_key_exists('groundhog_series_key', $exception->getAttributes()); // => false
$exception->isOccurrenceException();                                   // => true
$exception->toArray()['groundhog_series_key'];                         // => $series->id
```

When you serialise streamed rows, use `get()` or `lazy()` instead, or call one of the predicates first. See [Limitations](Limitations).

Next: [Configuration](Configuration)
