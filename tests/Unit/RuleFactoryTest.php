<?php

use BoysFromTheFactory\Groundhog\Exceptions\InvalidRecurrenceRule;
use BoysFromTheFactory\Groundhog\Rules\RuleFactory;
use Carbon\CarbonImmutable;
use RRule\RRule;

function seriesStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC');
}

/**
 * @return list<string>
 */
function firstStarts(RRule $rule, int $count = 5): array
{
    return array_map(
        fn (DateTimeInterface $date) => $date->format('Y-m-d H:i T'),
        $rule->getOccurrences($count),
    );
}

it('accepts an RRULE string and anchors DTSTART at the series start', function () {
    $rule = RuleFactory::make('FREQ=WEEKLY;BYDAY=MO', seriesStart(), 'UTC');

    expect(firstStarts($rule, 2))->toBe(['2026-03-02 09:00 UTC', '2026-03-09 09:00 UTC'])
        ->and(RuleFactory::timezoneOf($rule))->toBe('UTC');
});

it('takes the time zone from a DTSTART;TZID line and the date-time from the series start', function () {
    $rule = RuleFactory::make(
        "DTSTART;TZID=Europe/Budapest:20200101T120000\nRRULE:FREQ=WEEKLY;BYDAY=MO",
        seriesStart(),
        'UTC',
    );

    expect(RuleFactory::timezoneOf($rule))->toBe('Europe/Budapest')
        ->and(firstStarts($rule, 1))->toBe(['2026-03-02 10:00 CET']);
});

it('uses the default time zone when the input has no DTSTART zone', function () {
    $rule = RuleFactory::make('FREQ=DAILY', seriesStart(), 'Europe/Budapest');

    expect(RuleFactory::timezoneOf($rule))->toBe('Europe/Budapest')
        ->and(firstStarts($rule, 1))->toBe(['2026-03-02 10:00 CET']);
});

it('accepts a php-rrule parts array and an RRule instance', function () {
    $fromArray = RuleFactory::make(['FREQ' => 'WEEKLY', 'BYDAY' => 'MO'], seriesStart(), 'UTC');
    $fromInstance = RuleFactory::make(new RRule(['FREQ' => 'WEEKLY', 'BYDAY' => 'MO', 'DTSTART' => '2020-01-01 00:00:00']), seriesStart(), 'UTC');

    expect(firstStarts($fromArray, 2))->toBe(['2026-03-02 09:00 UTC', '2026-03-09 09:00 UTC'])
        ->and(firstStarts($fromInstance, 2))->toBe(['2026-03-02 09:00 UTC', '2026-03-09 09:00 UTC']);
});

it('rejects an invalid component and names it', function () {
    RuleFactory::make('FREQ=WEEKLY;BYDAY=XX', seriesStart(), 'UTC');
})->throws(InvalidRecurrenceRule::class, 'BYDAY');

it('rejects lines that would turn the rule into a set', function (string $line) {
    RuleFactory::make("RRULE:FREQ=DAILY\n{$line}", seriesStart(), 'UTC');
})->throws(InvalidRecurrenceRule::class)->with([
    'EXDATE:20260303T090000Z',
    'RDATE:20260303T090000Z',
    'EXRULE:FREQ=WEEKLY',
]);

it('round-trips the stored RFC text', function () {
    $rule = RuleFactory::make(
        "DTSTART;TZID=Europe/Budapest:20200101T120000\nRRULE:FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20271231T230000Z",
        seriesStart(),
        'UTC',
    );

    $stored = RuleFactory::fromStored($rule->rfcString());

    expect($stored->rfcString())->toBe($rule->rfcString())
        ->and(firstStarts($stored))->toBe(firstStarts($rule));
});

it('keeps every RFC 5545 component through make, rfcString and fromStored (FR-005)', function (string $rrule) {
    $rule = RuleFactory::make($rrule, seriesStart(), 'UTC');

    expect(firstStarts(RuleFactory::fromStored($rule->rfcString())))->toBe(firstStarts($rule))
        ->and(firstStarts($rule))->toHaveCount(5);
})->with([
    'INTERVAL' => 'FREQ=DAILY;INTERVAL=3',
    'COUNT' => 'FREQ=DAILY;COUNT=5',
    'UNTIL' => 'FREQ=DAILY;UNTIL=20260320T000000Z',
    'BYSECOND' => 'FREQ=MINUTELY;BYSECOND=0,30',
    'BYMINUTE' => 'FREQ=HOURLY;BYMINUTE=0,15,45',
    'BYHOUR' => 'FREQ=DAILY;BYHOUR=9,17',
    'BYDAY with ordinals' => 'FREQ=MONTHLY;BYDAY=1MO,-1FR',
    'BYMONTHDAY' => 'FREQ=MONTHLY;BYMONTHDAY=1,15,-1',
    'BYYEARDAY' => 'FREQ=YEARLY;BYYEARDAY=1,100,200',
    'BYWEEKNO' => 'FREQ=YEARLY;BYWEEKNO=10,20;BYDAY=MO',
    'BYMONTH' => 'FREQ=YEARLY;BYMONTH=3,6,9;BYMONTHDAY=2',
    'BYSETPOS' => 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1',
    'WKST' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,SU;WKST=SU',
]);
