<?php

use BoysFromTheFactory\Groundhog\Exceptions\InvalidRecurrenceRule;
use BoysFromTheFactory\Groundhog\Exceptions\OccurrenceLimitExceeded;
use BoysFromTheFactory\Groundhog\Exceptions\RecurrenceNotSupported;
use BoysFromTheFactory\Groundhog\Models\Recurrence;
use Illuminate\Support\Facades\DB;
use RRule\RRule;
use RRule\RRuleInterface;
use Workbench\App\Models\Meeting;
use Workbench\App\Models\Shift;

it('stores one rule row for a created series (FR-003, FR-004)', function () {
    $meeting = mondaySeries();

    $rows = Recurrence::all();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->recurrable_type)->toBe($meeting->getMorphClass())
        ->and((int) $rows->first()->recurrable_id)->toBe($meeting->id);
});

it('exposes the stored rule as an RRule (FR-028)', function () {
    $id = mondaySeries()->id;

    $rule = Meeting::find($id)->recurrence_rule;

    expect($rule)->toBeInstanceOf(RRule::class)
        ->and($rule)->toBeInstanceOf(RRuleInterface::class)
        ->and($rule->humanReadable(['locale' => 'en']))->toStartWith('weekly on Monday')
        ->and($rule->getOccurrencesBetween('2026-03-01', '2026-03-31'))->toHaveCount(5)
        ->and($rule->getRule()['DTSTART']->format('Y-m-d H:i'))->toBe('2026-03-02 09:00');
});

it('exposes the series rule on a virtual occurrence and no rule on an exception', function () {
    mondaySeries();
    $exception = occurrenceOn('2026-03-16');
    $exception->save();

    expect(occurrenceOn('2026-03-09')->recurrence_rule?->humanReadable(['locale' => 'en']))->toStartWith('weekly on Monday')
        ->and(occurrenceOn('2026-03-16')->recurrence_rule)->toBeNull();
});

it('accepts a php-rrule parts array or an RRule instance', function (mixed $input) {
    mondaySeries(['recurrence_rule' => $input]);

    expect(startsOf(Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get()))
        ->toBe(['2026-03-02 09:00', '2026-03-09 09:00', '2026-03-16 09:00', '2026-03-23 09:00', '2026-03-30 09:00']);
})->with([
    'array' => [['FREQ' => 'WEEKLY', 'BYDAY' => 'MO']],
    'RRule' => [fn () => new RRule(['FREQ' => 'WEEKLY', 'BYDAY' => 'MO'])],
]);

it('turns the record into a plain record when the rule is set to null', function () {
    $series = mondaySeries();

    $series->recurrence_rule = null;
    $series->save();

    expect(Recurrence::count())->toBe(0)
        ->and(Meeting::whereBetween('starts_at', march())->get()->modelKeys())->toBe([$series->id]);
});

it('replaces the occurrences when a different rule is assigned', function () {
    $series = mondaySeries();

    $series->recurrence_rule = 'FREQ=WEEKLY;BYDAY=TU';
    $series->save();

    expect(startsOf(Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get()))
        ->toBe(['2026-03-03 09:00', '2026-03-10 09:00', '2026-03-17 09:00', '2026-03-24 09:00', '2026-03-31 09:00']);
});

it('rejects an invalid rule on assignment and writes nothing (FR-006)', function () {
    expect(fn () => mondaySeries(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=XX']))
        ->toThrow(InvalidRecurrenceRule::class, 'BYDAY');

    expect(DB::table('meetings')->count())->toBe(0)
        ->and(Recurrence::count())->toBe(0);
});

it('stores the time zone of a DTSTART;TZID input', function () {
    mondaySeries(['recurrence_rule' => "DTSTART;TZID=Europe/Budapest:20260302T090000\nRRULE:FREQ=WEEKLY;BYDAY=MO"]);

    expect(Recurrence::sole()->timezone)->toBe('Europe/Budapest');
});

it('accepts the rule through mass assignment on a $fillable model', function () {
    Shift::create(['label' => 'Early', 'starts_at' => '2026-03-02 06:00:00', 'recurrence_rule' => 'FREQ=DAILY;COUNT=3']);

    expect(startsOf(Shift::orderBy('starts_at')->get()))
        ->toBe(['2026-03-02 06:00', '2026-03-03 06:00', '2026-03-04 06:00']);
});

it('refuses to save a series without a start', function () {
    $meeting = new Meeting(['title' => 'Standup', 'ends_at' => '2026-03-02 10:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    expect(fn () => $meeting->save())->toThrow(RecurrenceNotSupported::class);
    expect(DB::table('meetings')->count())->toBe(0);
});

it('refuses a finite rule with more occurrences than the per-series limit', function () {
    config(['groundhog.max_occurrences_per_series' => 10]);

    expect(fn () => mondaySeries(['recurrence_rule' => 'FREQ=DAILY;COUNT=11']))
        ->toThrow(OccurrenceLimitExceeded::class);

    expect(DB::table('meetings')->count())->toBe(0)
        ->and(Recurrence::count())->toBe(0);
});

it('refuses an infinite rule too dense for the per-series limit up to the horizon', function () {
    expect(fn () => mondaySeries(['recurrence_rule' => 'FREQ=MINUTELY']))
        ->toThrow(OccurrenceLimitExceeded::class);

    expect(DB::table('meetings')->count())->toBe(0)
        ->and(DB::table('groundhog_occurrences')->count())->toBe(0);
});

it('persists the rule on saveQuietly()', function () {
    $meeting = new Meeting([
        'title' => 'Standup',
        'starts_at' => '2026-03-02 09:00:00',
        'ends_at' => '2026-03-02 10:00:00',
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    ]);

    $meeting->saveQuietly();

    expect(Recurrence::count())->toBe(1)
        ->and(Meeting::whereBetween('starts_at', march())->count())->toBe(5);
});

it('exposes the stored rule row through recurrence()', function () {
    $series = mondaySeries();

    $recurrence = $series->recurrence;

    expect($recurrence)->toBeInstanceOf(Recurrence::class)
        ->and($recurrence->rule)->toBe("DTSTART:20260302T090000Z\nRRULE:FREQ=WEEKLY;BYDAY=MO")
        ->and($recurrence->timezone)->toBe('UTC')
        ->and($recurrence->is_infinite)->toBeTrue();

    $series->update(['recurrence_rule' => null]);

    expect(Meeting::find($series->id)->recurrence)->toBeNull();
});
