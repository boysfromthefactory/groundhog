<?php

use BoysFromTheFactory\Groundhog\Models\Recurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Meeting;
use Workbench\App\Models\Shift;

/**
 * Turns the 16 March occurrence into a stored exception in Room B.
 */
function exceptionOnSixteenth(): Meeting
{
    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->location = 'Room B';
    $occurrence->save();

    return $occurrence;
}

it('cancels a deleted virtual occurrence without storing a record (US4-1, FR-019, FR-024)', function () {
    mondaySeries();
    $events = [];
    Meeting::deleting(function () use (&$events) {
        $events[] = 'deleting';
    });
    Meeting::deleted(function () use (&$events) {
        $events[] = 'deleted';
    });

    occurrenceOn('2026-03-23')->delete();

    expect($events)->toBe(['deleting', 'deleted'])
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room A', '30 Room A'])
        ->and(DB::table('meetings')->count())->toBe(1)
        ->and(DB::table('groundhog_exclusions')->whereNull('exception_id')->count())->toBe(1);
});

it('lets a deleting listener veto the cancellation', function () {
    mondaySeries();
    Meeting::deleting(fn () => false);

    expect(occurrenceOn('2026-03-23')->delete())->toBeFalse()
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room A', '23 Room A', '30 Room A'])
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0);
});

it('keeps the occurrence cancelled when its exception is hard-deleted (US4-2, FR-020)', function () {
    mondaySeries();
    exceptionOnSixteenth();

    occurrenceOn('2026-03-16')->forceDelete();

    expect(marchLocations())->toBe(['02 Room A', '09 Room A', '23 Room A', '30 Room A'])
        ->and(DB::table('meetings')->count())->toBe(1);
});

it('keeps the occurrence cancelled when an exception loaded by key is hard-deleted', function () {
    mondaySeries();
    $exception = exceptionOnSixteenth();

    Meeting::find($exception->id)->forceDelete();

    expect(marchLocations())->toBe(['02 Room A', '09 Room A', '23 Room A', '30 Room A'])
        ->and(DB::table('groundhog_exclusions')->sole()->exception_id)->toBeNull();
});

it('propagates series edits to virtual occurrences but not to exceptions (US4-3, US4-7, FR-021)', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();

    $series->title = 'Daily sync';
    $series->save();

    $march = Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get();

    expect($march->pluck('title')->all())->toBe(['Daily sync', 'Daily sync', 'Standup', 'Daily sync', 'Daily sync'])
        ->and((int) $march[2]->groundhog_series_key)->toBe($series->id);
});

it('removes the rule, index, exclusions and exceptions when the series is force-deleted (US4-4, FR-023)', function () {
    $series = mondaySeries();
    $kept = exceptionOnSixteenth();
    $trashed = occurrenceOn('2026-03-23');
    $trashed->save();
    $trashed->delete();
    $deleted = [];
    Meeting::deleted(function (Meeting $meeting) use (&$deleted) {
        $deleted[] = $meeting->id;
    });

    $series->forceDelete();

    sort($deleted);

    expect(Recurrence::count())->toBe(0)
        ->and(DB::table('groundhog_occurrences')->count())->toBe(0)
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0)
        ->and(Meeting::withTrashed()->withoutOccurrences()->count())->toBe(0)
        ->and($deleted)->toBe([$series->id, $kept->id, $trashed->id]);
});

it('shows the new occurrences of a replaced rule and a plain record once the rule is removed (US4-5)', function () {
    $series = mondaySeries();

    $series->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU']);
    $tuesdays = startsOf(Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get());

    $series->update(['recurrence_rule' => null]);

    expect($tuesdays)->toBe(['2026-03-03 09:00', '2026-03-10 09:00', '2026-03-17 09:00', '2026-03-24 09:00', '2026-03-31 09:00'])
        ->and(Meeting::whereBetween('starts_at', march())->get()->modelKeys())->toBe([$series->id]);
});

it('detaches exceptions as plain records when the rule changes (US4-6, FR-022)', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();

    $series->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU']);

    $former = Meeting::whereBetween('starts_at', march())->get()->first(fn (Meeting $m) => $m->exists);

    expect(marchLocations())->toBe(['03 Room A', '10 Room A', '16 Room B (stored)', '17 Room A', '24 Room A', '31 Room A'])
        ->and($former->groundhog_series_key)->toBeNull()
        ->and($former->isOccurrenceException())->toBeFalse()
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0);
});

it('detaches exceptions when the series start changes', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();

    $series->update(['starts_at' => '2026-03-02 10:00:00', 'ends_at' => '2026-03-02 11:00:00']);

    expect(Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->orderBy('starts_at')->get()
        ->map(fn (Meeting $m) => $m->starts_at->format('H:i').' '.$m->location.' '.($m->groundhog_series_key === null ? 'plain' : 'linked'))->all())
        ->toBe(['09:00 Room B plain', '10:00 Room A linked'])
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0);
});

it('discards cancellations when the series start changes (FR-022)', function () {
    $series = mondaySeries();
    occurrenceOn('2026-03-23')->delete();

    $series->update(['starts_at' => '2026-03-02 10:00:00', 'ends_at' => '2026-03-02 11:00:00']);

    expect(startsOf(Meeting::whereBetween('starts_at', ['2026-03-23 00:00:00', '2026-03-23 23:59:59'])->get()))->toBe(['2026-03-23 10:00']);
});

it('changes nothing when the identical rule is assigned again (FR-022)', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();
    occurrenceOn('2026-03-23')->delete();

    $series->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO']);

    expect(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room B (stored)', '30 Room A'])
        ->and(occurrenceOn('2026-03-16')->isOccurrenceException())->toBeTrue();
});

it('returns a detached exception next to a new occurrence at the same time', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();

    $series->update(['recurrence_rule' => 'FREQ=DAILY']);

    expect(Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->orderBy('location')->get()
        ->map(fn (Meeting $m) => $m->location.' '.($m->exists ? 'stored' : 'virtual'))->all())
        ->toBe(['Room A virtual', 'Room B stored']);
});

it('keeps exceptions and cancellations when only the duration changes', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();
    occurrenceOn('2026-03-23')->delete();

    $series->update(['ends_at' => '2026-03-02 11:00:00']);

    expect(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room B (stored)', '30 Room A'])
        ->and(occurrenceOn('2026-03-09')->ends_at->format('H:i'))->toBe('11:00')
        ->and(occurrenceOn('2026-03-16')->isOccurrenceException())->toBeTrue();
});

it('hides and restores a soft-deleted series with its exceptions (FR-023)', function () {
    $series = mondaySeries();
    exceptionOnSixteenth();

    $series->delete();
    $hidden = Meeting::whereBetween('starts_at', march())->count();
    $withTrashed = Meeting::withTrashed()->whereBetween('starts_at', march())->count();

    $series->restore();

    expect($hidden)->toBe(0)
        ->and($withTrashed)->toBe(5)
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room B (stored)', '23 Room A', '30 Room A']);
});

it('cancels an occurrence while its exception is soft-deleted and shows it again on restore', function () {
    mondaySeries();
    $exception = exceptionOnSixteenth();

    $exception->delete();
    $whileTrashed = marchLocations();

    $exception->restore();

    expect($whileTrashed)->toBe(['02 Room A', '09 Room A', '23 Room A', '30 Room A'])
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room B (stored)', '23 Room A', '30 Room A']);
});

it('applies query-level updates and deletes to stored rows only (FR-026)', function () {
    $series = mondaySeries();
    $exception = exceptionOnSixteenth();
    $plain = Meeting::create(['title' => 'Review', 'location' => 'Room A', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    $updated = Meeting::where('title', 'Standup')->update(['location' => 'X']);
    $deleted = Meeting::where('title', 'Standup')->delete();

    expect($updated)->toBe(2)
        ->and($deleted)->toBe(2)
        ->and(DB::table('meetings')->whereNotNull('deleted_at')->pluck('id')->sort()->values()->all())->toBe([$series->id, $exception->id])
        ->and(DB::table('meetings')->find($plain->id)->location)->toBe('Room A')
        ->and(DB::table('groundhog_exclusions')->count())->toBe(1)
        ->and(DB::table('meetings')->count())->toBe(3);
});

it('applies the other query-level writes to stored rows only (FR-026)', function (Closure $write, Closure $verify) {
    $series = mondaySeries(['capacity' => 10]);
    $exception = exceptionOnSixteenth();
    $plain = Meeting::create(['title' => 'Review', 'capacity' => 3, 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    $write(Meeting::where('title', 'Standup'));

    expect(DB::table('groundhog_exclusions')->count())->toBe(1)
        ->and(DB::table('meetings')->find($plain->id)->capacity)->toBe(3);

    $verify($series->id, $exception->id);
})->with([
    'forceDelete' => [
        fn ($query) => $query->forceDelete(),
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->count())->toBe(0),
    ],
    'increment' => [
        fn ($query) => $query->increment('capacity'),
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->pluck('capacity')->all())->toBe([11, 11]),
    ],
    'decrement' => [
        fn ($query) => $query->decrement('capacity'),
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->pluck('capacity')->all())->toBe([9, 9]),
    ],
    'incrementEach' => [
        fn ($query) => $query->incrementEach(['capacity' => 2]),
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->pluck('capacity')->all())->toBe([12, 12]),
    ],
    'decrementEach' => [
        fn ($query) => $query->decrementEach(['capacity' => 2]),
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->pluck('capacity')->all())->toBe([8, 8]),
    ],
    'touch' => [
        function ($query) {
            Carbon::setTestNow('2026-03-02 00:00:00');
            $query->touch();
        },
        fn (int $series, int $exception) => expect(DB::table('meetings')->whereIn('id', [$series, $exception])->pluck('updated_at')->map(fn ($at) => substr((string) $at, 0, 10))->all())->toBe(['2026-03-02', '2026-03-02']),
    ],
]);

it('inserts and upserts stored rows through the model query (FR-026)', function () {
    mondaySeries();
    $before = Meeting::withoutOccurrences()->count();
    $row = fn (string $title) => ['title' => $title, 'starts_at' => '2026-04-01 09:00:00', 'ends_at' => '2026-04-01 10:00:00'];

    Meeting::insert($row('Inserted'));
    $id = Meeting::insertGetId($row('With id'));
    Meeting::insertOrIgnore($row('Ignored-or-not'));
    Meeting::upsert([['id' => $id, ...$row('Upserted')]], ['id'], ['title']);

    expect(Meeting::withoutOccurrences()->count())->toBe($before + 3)
        ->and(DB::table('meetings')->find($id)->title)->toBe('Upserted')
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0);
});

it('destroys a series through its hard delete branch, or soft-deletes it when soft-deletable', function () {
    $shift = Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=DAILY;COUNT=5']);
    $meeting = mondaySeries();

    Shift::destroy($shift->id);
    Meeting::destroy($meeting->id);
    $whileTrashed = Meeting::whereBetween('starts_at', march())->count();

    Meeting::withTrashed()->find($meeting->id)->restore();

    expect(Recurrence::where('recurrable_type', $shift->getMorphClass())->count())->toBe(0)
        ->and(Shift::count())->toBe(0)
        ->and($whileTrashed)->toBe(0)
        ->and(Meeting::whereBetween('starts_at', march())->count())->toBe(5);
});
