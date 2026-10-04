<?php

use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Meeting;
use Workbench\App\Models\Shift;

it('persists an edited occurrence as an exception linked to its series (US3-1, FR-017)', function () {
    $series = mondaySeries();
    $occurrence = occurrenceOn('2026-03-16');

    $occurrence->location = 'Room B';
    $occurrence->save();

    $exclusion = DB::table('groundhog_exclusions')->sole();

    expect(DB::table('meetings')->count())->toBe(2)
        ->and((int) $exclusion->exception_id)->toBe($occurrence->id)
        ->and(substr((string) $exclusion->original_starts_at, 0, 16))->toBe('2026-03-16 09:00')
        ->and(DB::table('meetings')->find($series->id)->location)->toBe('Room A')
        ->and($occurrence->exists)->toBeTrue()
        ->and($occurrence->isOccurrenceException())->toBeTrue();
});

it('returns the exception in place of its occurrence (US3-2, FR-013)', function () {
    $series = mondaySeries();
    tap(occurrenceOn('2026-03-16'), fn (Meeting $m) => $m->fill(['location' => 'Room B'])->save());

    $march = Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get();
    $exception = $march->first(fn (Meeting $m) => $m->exists);

    expect(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room B (stored)', '23 Room A', '30 Room A'])
        ->and($exception->isOccurrenceException())->toBeTrue()
        ->and((int) $exception->groundhog_series_key)->toBe($series->id);
});

it('shows a moved exception at its new time only (US3-3)', function () {
    mondaySeries();
    tap(occurrenceOn('2026-03-16'), fn (Meeting $m) => $m->fill(['starts_at' => '2026-03-18 14:00:00', 'ends_at' => '2026-03-18 15:00:00'])->save());

    expect(Meeting::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->count())->toBe(0)
        ->and(startsOf(Meeting::whereBetween('starts_at', ['2026-03-18 00:00:00', '2026-03-18 23:59:59'])->get()))->toBe(['2026-03-18 14:00']);
});

it('updates a stored exception in place (US3-4, FR-018)', function () {
    mondaySeries();
    tap(occurrenceOn('2026-03-16'), fn (Meeting $m) => $m->fill(['location' => 'Room B'])->save());

    $exception = occurrenceOn('2026-03-16');
    $exception->location = 'Room C';
    $exception->save();

    expect(DB::table('meetings')->count())->toBe(2)
        ->and(DB::table('groundhog_exclusions')->count())->toBe(1)
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room C (stored)', '23 Room A', '30 Room A']);
});

it('persists an exception through update() and increment() on a virtual occurrence (US3-5)', function () {
    $series = mondaySeries(['capacity' => 10]);
    $plain = Meeting::create(['title' => 'Review', 'location' => 'Room A', 'capacity' => 3, 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);

    occurrenceOn('2026-03-16')->update(['location' => 'Room B']);
    occurrenceOn('2026-03-23')->increment('capacity');

    expect(DB::table('meetings')->count())->toBe(4)
        ->and(DB::table('groundhog_exclusions')->count())->toBe(2)
        ->and(occurrenceOn('2026-03-23')->capacity)->toBe(11)
        ->and(occurrenceOn('2026-03-23')->exists)->toBeTrue()
        ->and(DB::table('meetings')->find($series->id)->capacity)->toBe(10)
        ->and(DB::table('meetings')->find($plain->id)->capacity)->toBe(3)
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '11 Room A (stored)', '16 Room B (stored)', '23 Room A (stored)', '30 Room A']);
});

it('persists nothing for an edited but unsaved occurrence (US3-6)', function () {
    mondaySeries();

    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->location = 'Room B';

    expect(DB::table('meetings')->count())->toBe(1)
        ->and(DB::table('groundhog_exclusions')->count())->toBe(0)
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room A', '23 Room A', '30 Room A']);
});

it('persists an exception even when an occurrence is saved unchanged', function () {
    mondaySeries();

    occurrenceOn('2026-03-16')->save();

    expect(DB::table('meetings')->count())->toBe(2)
        ->and(marchLocations())->toBe(['02 Room A', '09 Room A', '16 Room A (stored)', '23 Room A', '30 Room A']);
});

it('fires the creation events on promotion and the update events on an exception edit (FR-024)', function () {
    mondaySeries();
    $events = [];
    foreach (['saving', 'creating', 'created', 'updating', 'updated', 'saved'] as $event) {
        Meeting::{$event}(function () use (&$events, $event) {
            $events[] = $event;
        });
    }

    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->save();
    $promotion = $events;
    $events = [];

    $occurrence->location = 'Room B';
    $occurrence->save();

    expect($promotion)->toBe(['saving', 'creating', 'created', 'saved'])
        ->and($events)->toBe(['saving', 'updating', 'updated', 'saved']);
});

it('rejects a second exception for the same occurrence and leaves no orphan row (FR-025)', function () {
    mondaySeries();
    $first = occurrenceOn('2026-03-16');
    $second = occurrenceOn('2026-03-16');

    $first->save();

    expect(fn () => $second->save())->toThrow(QueryException::class);
    expect(DB::table('meetings')->count())->toBe(2)
        ->and(DB::table('groundhog_exclusions')->count())->toBe(1);
});

it('returns both an exception moved onto another occurrence and that occurrence', function () {
    mondaySeries();
    tap(occurrenceOn('2026-03-16'), fn (Meeting $m) => $m->fill(['location' => 'Room B', 'starts_at' => '2026-03-23 09:00:00', 'ends_at' => '2026-03-23 10:00:00'])->save());

    expect(Meeting::whereBetween('starts_at', ['2026-03-23 00:00:00', '2026-03-23 23:59:59'])->orderBy('location')->pluck('location')->all())
        ->toBe(['Room A', 'Room B']);
});

it('refuses a rule on an exception', function () {
    mondaySeries();
    tap(occurrenceOn('2026-03-16'), fn (Meeting $m) => $m->save());

    $exception = occurrenceOn('2026-03-16');

    expect(fn () => $exception->recurrence_rule = 'FREQ=DAILY')->toThrow(RecurrenceNotSupported::class);
});

it('recognises an exception loaded by key', function () {
    $series = mondaySeries();
    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->save();

    $exception = Meeting::find($occurrence->id);

    expect($exception->isOccurrenceException())->toBeTrue()
        ->and($exception->series->is($series))->toBeTrue()
        ->and($exception->originalOccurrenceStart()->format('Y-m-d H:i'))->toBe('2026-03-16 09:00')
        ->and(fn () => $exception->recurrence_rule = 'FREQ=DAILY')->toThrow(RecurrenceNotSupported::class);
});

it('keeps exceptions of different model types with the same key apart', function () {
    mondaySeries();
    Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO']);

    $shiftException = Shift::whereBetween('starts_at', ['2026-03-16 00:00:00', '2026-03-16 23:59:59'])->sole();
    $shiftException->id = 1000;
    $shiftException->save();

    $meetingException = occurrenceOn('2026-03-16');
    $meetingException->id = 1000;
    $meetingException->save();

    expect(Meeting::whereBetween('starts_at', march())->get()->where('id', 1000))->toHaveCount(1)
        ->and(Meeting::whereBetween('starts_at', march())->count())->toBe(5);
});

it('returns stored exceptions through withoutOccurrences()', function () {
    $series = mondaySeries();
    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->save();

    $exception = Meeting::withoutOccurrences()->get()->firstWhere('id', $occurrence->id);

    expect($exception)->not->toBeNull()
        ->and((int) $exception->groundhog_series_key)->toBe($series->id);
});

it('gives stored rows loaded without expansion the same identity attributes', function () {
    $series = mondaySeries();
    $plain = Meeting::create(['title' => 'Review', 'starts_at' => '2026-03-11 14:00:00', 'ends_at' => '2026-03-11 15:00:00']);
    $occurrence = occurrenceOn('2026-03-16');
    $occurrence->save();

    $stored = Meeting::withoutOccurrences()->with('series')->get()->keyBy('id');

    expect((int) Meeting::find($occurrence->id)->toArray()['groundhog_series_key'])->toBe($series->id)
        ->and($stored[$occurrence->id]->series?->is($series))->toBeTrue()
        ->and($stored[$plain->id]->series)->toBeNull();
});
