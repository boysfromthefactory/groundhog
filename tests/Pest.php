<?php

use BoysFromTheFactory\Groundhog\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\Meeting;

uses(TestCase::class)->in(__DIR__);
uses(RefreshDatabase::class)->in('Feature');

/**
 * The spec's reference series: every Monday 09:00–10:00 from 2 March 2026 (US1-1).
 *
 * @param  array<string, mixed>  $attributes
 */
function mondaySeries(array $attributes = []): Meeting
{
    return Meeting::create(array_merge([
        'title' => 'Standup',
        'location' => 'Room A',
        'starts_at' => '2026-03-02 09:00:00',
        'ends_at' => '2026-03-02 10:00:00',
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    ], $attributes));
}

/**
 * @return array{string, string}
 */
function march(): array
{
    return ['2026-03-01 00:00:00', '2026-03-31 23:59:59'];
}

/**
 * The single row (virtual occurrence or stored record) starting on the given date.
 */
function occurrenceOn(string $date): Meeting
{
    return Meeting::whereBetween('starts_at', ["{$date} 00:00:00", "{$date} 23:59:59"])->sole();
}

/**
 * "day location" of every March row in start order; stored rows are marked.
 *
 * @return list<string>
 */
function marchLocations(): array
{
    return Meeting::whereBetween('starts_at', march())->orderBy('starts_at')->get()
        ->map(fn (Meeting $m) => $m->starts_at->format('d').' '.$m->location.($m->exists ? ' (stored)' : ''))
        ->all();
}

/**
 * Start of each model as "Y-m-d H:i", in result order.
 *
 * @param  iterable<Model>  $models
 * @return list<string>
 */
function startsOf(iterable $models, string $column = 'starts_at'): array
{
    $starts = [];

    foreach ($models as $model) {
        $starts[] = $model->{$column}?->format('Y-m-d H:i');
    }

    return $starts;
}
