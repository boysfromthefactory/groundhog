<?php

use BoysFromTheFactory\Groundhog\Models\Recurrence;
use Illuminate\Support\Carbon;
use Workbench\App\Models\Meeting;
use Workbench\App\Models\Room;
use Workbench\App\Models\Shift;

it('returns one instance per occurrence in the window with the series attributes (US1-1)', function () {
    mondaySeries();

    $meetings = Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get();

    expect(startsOf($meetings))->toBe(['2026-03-02 09:00', '2026-03-09 09:00', '2026-03-16 09:00', '2026-03-23 09:00', '2026-03-30 09:00'])
        ->and(startsOf($meetings, 'ends_at'))->toBe(['2026-03-02 10:00', '2026-03-09 10:00', '2026-03-16 10:00', '2026-03-23 10:00', '2026-03-30 10:00'])
        ->and($meetings->pluck('title')->unique()->all())->toBe(['Standup'])
        ->and($meetings->pluck('location')->unique()->all())->toBe(['Room A']);
});

it('marks every occurrence as virtual and links it to its series (US1-2, FR-012)', function () {
    $series = mondaySeries();

    $meetings = Meeting::whereBetween('starts_at', march())->get();

    expect($meetings)->toHaveCount(5);

    foreach ($meetings as $meeting) {
        expect($meeting->exists)->toBeFalse()
            ->and($meeting->getKey())->toBeNull()
            ->and($meeting->isVirtualOccurrence())->toBeTrue()
            ->and($meeting->series->is($series))->toBeTrue()
            ->and($meeting->originalOccurrenceStart()->equalTo($meeting->starts_at))->toBeTrue();
    }
});

it('returns a plain record once, as a stored model (US1-3, FR-014)', function () {
    mondaySeries();
    $plain = Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    $returned = Meeting::whereBetween('starts_at', march())->get()->filter(fn (Meeting $m) => $m->exists)->values();

    expect($returned->modelKeys())->toBe([$plain->id]);
});

it('evaluates non-time constraints against each occurrence (US1-4)', function () {
    mondaySeries();
    mondaySeries(['location' => 'Room B', 'title' => 'Retro']);

    $meetings = Meeting::whereBetween('starts_at', march())->where('location', 'Room A')->get();

    expect($meetings)->toHaveCount(5)
        ->and($meetings->pluck('title')->unique()->all())->toBe(['Standup']);
});

it('returns exactly the occurrences of a finite rule for a huge window (US1-5)', function () {
    mondaySeries(['recurrence_rule' => 'FREQ=DAILY;COUNT=3']);

    expect(Meeting::whereBetween('starts_at', ['2000-01-01 00:00:00', '2099-12-31 00:00:00'])->count())->toBe(3);
});

it('keeps each model type to its own occurrences while sharing one rule store (US1-6)', function () {
    $meeting = mondaySeries();
    // Equal keys across types are what a type-blind join would confuse; auto-increment values
    // are not reset between tests on every database, so the key is forced.
    $shift = (new Shift)->forceFill(['id' => $meeting->id, 'label' => 'Early', 'starts_at' => '2026-03-03 06:00:00', 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=TU']);
    $shift->save();

    expect($meeting->id)->toBe($shift->id)
        ->and(startsOf(Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get()))->toBe(['2026-03-02 09:00', '2026-03-09 09:00', '2026-03-16 09:00', '2026-03-23 09:00', '2026-03-30 09:00'])
        ->and(startsOf(Shift::whereBetween('starts_at', march())->orderBy('starts_at')->get()))->toBe(['2026-03-03 06:00', '2026-03-10 06:00', '2026-03-17 06:00', '2026-03-24 06:00', '2026-03-31 06:00'])
        ->and(Recurrence::pluck('recurrable_type')->sort()->values()->all())->toBe([$meeting->getMorphClass(), $shift->getMorphClass()]);
});

it('expands an infinite series up to one year after now when unconstrained (US2-6, FR-008)', function () {
    mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    expect(Meeting::count())->toBe(365)
        ->and(Meeting::max('starts_at'))->toStartWith('2027-02-28 09:00');
});

it('expands an infinite series up to one year after the lower bound (US2-7, FR-008)', function () {
    mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    $meetings = Meeting::where('starts_at', '>=', '2030-01-01 00:00:00')->orderBy('starts_at')->get();

    expect($meetings)->toHaveCount(365)
        ->and($meetings->first()->starts_at->format('Y-m-d H:i'))->toBe('2030-01-01 09:00')
        ->and($meetings->last()->starts_at->format('Y-m-d H:i'))->toBe('2030-12-31 09:00');
});

it('honours a configured horizon length (FR-008)', function () {
    config(['groundhog.horizon' => 'P1M']);
    mondaySeries(['starts_at' => '2026-03-01 09:00:00', 'ends_at' => '2026-03-01 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    expect(Meeting::count())->toBe(31);
});

it('gives occurrences of a model without an end column only a start (FR-010)', function () {
    Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=DAILY;COUNT=2']);

    $shifts = Shift::orderBy('starts_at')->get();

    expect(startsOf($shifts))->toBe(['2026-03-02 06:00', '2026-03-03 06:00'])
        ->and(array_key_exists('ends_at', $shifts->first()->getAttributes()))->toBeFalse();
});

it('returns the stored series when looked up by key (FR-015)', function () {
    $series = mondaySeries();

    $found = Meeting::find($series->id);

    expect($found->exists)->toBeTrue()
        ->and($found->getKey())->toBe($series->id)
        ->and($found->starts_at->format('Y-m-d H:i'))->toBe('2026-03-02 09:00');
});

it('applies relationship queries, whereHas and joins to occurrences (FR-009)', function () {
    $roomA = Room::create(['name' => 'A']);
    $roomB = Room::create(['name' => 'B']);
    mondaySeries(['room_id' => $roomA->id]);
    Meeting::create(['title' => 'Offsite', 'room_id' => $roomB->id, 'starts_at' => '2026-04-15 09:00:00', 'ends_at' => '2026-04-15 17:00:00']);

    $roomsUsedInMarch = Room::whereHas('meetings', fn ($q) => $q->whereBetween('starts_at', march()))->pluck('id')->all();
    $joined = Meeting::select('meetings.*')
        ->join('rooms', 'rooms.id', '=', 'meetings.room_id')
        ->where('rooms.name', 'A')
        ->whereBetween('meetings.starts_at', march())
        ->get();

    expect($roomsUsedInMarch)->toBe([$roomA->id])
        ->and($roomA->meetings()->whereBetween('starts_at', march())->count())->toBe(5)
        ->and($joined)->toHaveCount(5);
});

it('answers pluck, value and sole over occurrences', function () {
    mondaySeries();

    expect(Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->pluck('starts_at')->map(fn ($s) => Carbon::parse($s)->format('Y-m-d'))->all())
        ->toBe(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'])
        ->and(Meeting::whereBetween('starts_at', march())->value('title'))->toBe('Standup')
        ->and(Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole()->starts_at->format('Y-m-d H:i'))->toBe('2026-03-16 09:00');
});

it('finds an occurrence again by series key and original start', function () {
    $series = mondaySeries();
    $byIdentity = fn () => Meeting::where('groundhog_series_key', $series->id)
        ->where('groundhog_original_starts_at', '2026-03-16 09:00:00')
        ->sole();

    $virtual = $byIdentity();
    $virtual->update(['location' => 'Room B']);
    $exception = $byIdentity();

    expect($virtual->starts_at->format('Y-m-d H:i'))->toBe('2026-03-16 09:00')
        ->and($exception->exists)->toBeTrue()
        ->and($exception->location)->toBe('Room B');
});
