<?php

use BoysFromTheFactory\Groundhog\Exceptions\IncompatibleEloquentBuilder;
use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use BoysFromTheFactory\Groundhog\Models\Recurrence;
use BoysFromTheFactory\Groundhog\Query\RecurringBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Attendee;
use Workbench\App\Models\Meeting;
use Workbench\App\Models\Room;

#[UseEloquentBuilder(Builder::class)]
class MeetingWithPlainBuilder extends Meeting
{
    protected $table = 'meetings';
}

/**
 * @extends RecurringBuilder<MeetingWithRecurringBuilder>
 */
class CustomRecurringBuilder extends RecurringBuilder {}

#[UseEloquentBuilder(CustomRecurringBuilder::class)]
class MeetingWithRecurringBuilder extends Meeting
{
    protected $table = 'meetings';
}

it('keeps the local wall-clock time across a daylight-saving change (FR-011)', function () {
    mondaySeries([
        'starts_at' => '2026-03-26 08:00:00',
        'ends_at' => '2026-03-26 09:00:00',
        'recurrence_rule' => "DTSTART;TZID=Europe/Budapest:20260326T090000\nRRULE:FREQ=DAILY;COUNT=6",
    ]);

    $meetings = Meeting::orderBy('starts_at')->get();

    expect($meetings->map(fn (Meeting $m) => $m->starts_at->setTimezone('Europe/Budapest')->format('m-d H:i'))->all())
        ->toBe(['03-26 09:00', '03-27 09:00', '03-28 09:00', '03-29 09:00', '03-30 09:00', '03-31 09:00'])
        ->and($meetings->map(fn (Meeting $m) => $m->ends_at->setTimezone('Europe/Budapest')->format('H:i'))->unique()->all())
        ->toBe(['10:00']);
});

it('matches occurrences that started before the window but are still running (end constraint)', function () {
    mondaySeries(['recurrence_rule' => 'FREQ=DAILY']);

    $running = Meeting::where('ends_at', '>', '2026-03-03 09:30:00')->where('starts_at', '<', '2026-03-03 09:30:00')->get();

    expect(startsOf($running))->toBe(['2026-03-03 09:00']);
});

it('never drops matching occurrences because of an OR-combined bound', function () {
    mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    $meetings = Meeting::where(fn ($q) => $q->where('starts_at', '<', '2026-03-10 00:00:00')->orWhere('title', 'x'))->get();

    expect($meetings)->toHaveCount(9);
});

it('contributes nothing for a rule without occurrences but keeps the record retrievable by key', function () {
    $series = mondaySeries(['recurrence_rule' => 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30']);

    expect(Meeting::count())->toBe(0)
        ->and(Meeting::find($series->id)?->is($series))->toBeTrue();
});

it('binds a route parameter to the stored series', function () {
    $series = mondaySeries();
    Route::get('/m/{meeting}', fn (Meeting $meeting) => $meeting->title.'#'.$meeting->getKey())->middleware(SubstituteBindings::class);

    $this->get('/m/'.$series->id)->assertOk()->assertContent('Standup#'.$series->id);
});

it('eager loads foreign-key relations from the series values and the series itself', function () {
    $room = Room::create(['name' => 'A']);
    $series = mondaySeries(['room_id' => $room->id]);
    Attendee::create(['meeting_id' => $series->id, 'name' => 'Ada']);

    $meetings = Meeting::with(['room', 'attendees', 'series'])->whereBetween('starts_at', march())->get();

    expect($meetings)->toHaveCount(5)
        ->and($meetings->every(fn (Meeting $m) => $m->room?->is($room)))->toBeTrue()
        ->and($meetings->every(fn (Meeting $m) => $m->attendees->isEmpty()))->toBeTrue()
        ->and($meetings->every(fn (Meeting $m) => $m->series?->is($series)))->toBeTrue();
});

it('fails instead of generating past the per-series limit on a far query', function () {
    $series = mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);
    $materializedUntil = Recurrence::sole()->materialized_until;
    config(['groundhog.max_occurrences_per_series' => 1000]);

    expect(fn () => Meeting::where('starts_at', '<', '2034-01-01 00:00:00')->count())
        ->toThrow(OccurrenceLimitExceeded::class);

    expect(Recurrence::sole()->materialized_until->equalTo($materializedUntil))->toBeTrue();
});

it('fails instead of generating past the materialisation ceiling', function () {
    config(['groundhog.max_materialization_ahead' => 'P2Y']);
    mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    expect(fn () => Meeting::where('starts_at', '<', '2030-06-01 00:00:00')->count())
        ->toThrow(OccurrenceLimitExceeded::class);

    expect(DB::table('groundhog_occurrences')->where('starts_at', '>=', '2027-03-01 00:00:00')->count())->toBe(0);
});

it('applies the ceiling only when occurrences would have to be generated', function () {
    config(['groundhog.max_materialization_ahead' => 'P2Y']);
    mondaySeries(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2']);
    Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    expect(Meeting::where('starts_at', '<', '2100-01-01 00:00:00')->count())->toBe(3);
});

it('requires a custom builder to extend RecurringBuilder', function () {
    expect(fn () => MeetingWithPlainBuilder::query())->toThrow(IncompatibleEloquentBuilder::class);

    MeetingWithRecurringBuilder::create([
        'title' => 'Standup',
        'starts_at' => '2026-03-02 09:00:00',
        'ends_at' => '2026-03-02 10:00:00',
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    ]);

    expect(MeetingWithRecurringBuilder::query())->toBeInstanceOf(CustomRecurringBuilder::class)
        ->and(MeetingWithRecurringBuilder::whereBetween('starts_at', march())->count())->toBe(5);
});

it('returns stored rows only through withoutOccurrences()', function () {
    $series = mondaySeries();
    $plain = Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    $stored = Meeting::withoutOccurrences()->orderBy('id')->get();

    expect($stored->modelKeys())->toBe([$series->id, $plain->id])
        ->and($stored->every(fn (Meeting $m) => $m->exists))->toBeTrue();
});

it('resolves relations that point at a series by its key to the stored series (FR-015)', function () {
    $series = mondaySeries();
    $attendee = Attendee::create(['meeting_id' => $series->id, 'name' => 'Ada']);

    expect($attendee->meeting?->is($series))->toBeTrue()
        ->and(Attendee::with('meeting')->find($attendee->id)->meeting?->is($series))->toBeTrue()
        ->and(Attendee::whereHas('meeting')->pluck('id')->all())->toBe([$attendee->id])
        ->and(Meeting::whereIn('id', [$series->id])->get()->modelKeys())->toBe([$series->id]);
});

it('reports the series role only for the stored series', function () {
    $series = mondaySeries();
    $plain = Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);
    $virtual = Meeting::whereBetween('starts_at', march())->first();

    expect(Meeting::find($series->id)->isRecurringSeries())->toBeTrue()
        ->and($virtual->isRecurringSeries())->toBeFalse()
        ->and(Meeting::find($plain->id)->isRecurringSeries())->toBeFalse();
});

it('rejects key-based iteration over expanded occurrences (FR-007)', function (Closure $iterate) {
    mondaySeries();

    expect(fn () => $iterate(Meeting::whereBetween('starts_at', march())))->toThrow(RecurrenceNotSupported::class);
})->with([
    'chunkById' => [fn ($query) => $query->chunkById(3, fn () => null)],
    'lazyById' => [fn ($query) => $query->lazyById(3)->all()],
    'eachById' => [fn ($query) => $query->eachById(fn () => null)],
]);

it('allows key-based iteration over stored rows', function () {
    $series = mondaySeries();
    $seen = [];

    Meeting::withoutOccurrences()->chunkById(3, function ($chunk) use (&$seen) {
        $seen = [...$seen, ...$chunk->modelKeys()];
    });

    expect($seen)->toBe([$series->id]);
});

it('excludes a key from stored rows with whereKeyNot()', function () {
    $series = mondaySeries();
    $plain = Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    expect(Meeting::whereKeyNot($plain->id)->get()->modelKeys())->toBe([$series->id]);
});
