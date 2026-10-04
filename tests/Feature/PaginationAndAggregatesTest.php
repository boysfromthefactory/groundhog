<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Meeting;

/**
 * Spec US2 data: series A daily 08:00, series B daily 12:00 and a plain record on day 3 at
 * 10:00, over days 1–5 (2–6 March 2026): 11 rows.
 */
beforeEach(function () {
    Meeting::create(['title' => 'Alpha', 'location' => 'Room A', 'capacity' => 10, 'starts_at' => '2026-03-02 08:00:00', 'ends_at' => '2026-03-02 09:00:00', 'recurrence_rule' => 'FREQ=DAILY']);
    Meeting::create(['title' => 'Bravo', 'location' => 'Room B', 'capacity' => 20, 'starts_at' => '2026-03-02 12:00:00', 'ends_at' => '2026-03-02 13:00:00', 'recurrence_rule' => 'FREQ=DAILY']);
    Meeting::create(['title' => 'Charlie', 'location' => 'Room A', 'capacity' => 5, 'starts_at' => '2026-03-04 10:00:00', 'ends_at' => '2026-03-04 11:00:00']);
});

function daysOneToFive(): Builder
{
    return Meeting::whereBetween('starts_at', ['2026-03-02 00:00:00', '2026-03-06 23:59:59']);
}

/**
 * @param  iterable<Meeting>  $meetings
 * @return list<string>
 */
function labels(iterable $meetings): array
{
    $labels = [];

    foreach ($meetings as $meeting) {
        $labels[] = $meeting->title.' '.$meeting->starts_at->format('d H:i');
    }

    return $labels;
}

it('pages over the merged, start-ordered occurrences (US2-1)', function () {
    $page = daysOneToFive()->orderBy('starts_at')->paginate(4, page: 2);

    expect($page->total())->toBe(11)
        ->and(labels($page->items()))->toBe(['Alpha 04 08:00', 'Charlie 04 10:00', 'Bravo 04 12:00', 'Alpha 05 08:00']);
});

it('counts occurrences and answers existence over them (US2-2)', function () {
    expect(daysOneToFive()->count())->toBe(11)
        ->and(Meeting::whereBetween('starts_at', ['2026-03-07 00:00:00', '2026-03-07 23:59:59'])->where('title', 'Charlie')->exists())->toBeFalse()
        ->and(Meeting::whereBetween('starts_at', ['2026-03-07 00:00:00', '2026-03-07 23:59:59'])->exists())->toBeTrue();
});

it('returns the latest occurrence first when ordered descending (US2-3)', function () {
    expect(labels([daysOneToFive()->orderByDesc('starts_at')->first()]))->toBe(['Bravo 06 12:00']);
});

it('orders by a non-time attribute and then by start like stored rows (US2-4)', function () {
    expect(labels(daysOneToFive()->orderBy('title')->orderBy('starts_at')->get()))->toBe([
        'Alpha 02 08:00', 'Alpha 03 08:00', 'Alpha 04 08:00', 'Alpha 05 08:00', 'Alpha 06 08:00',
        'Bravo 02 12:00', 'Bravo 03 12:00', 'Bravo 04 12:00', 'Bravo 05 12:00', 'Bravo 06 12:00',
        'Charlie 04 10:00',
    ]);
});

it('aggregates over occurrences', function () {
    expect(substr((string) daysOneToFive()->min('starts_at'), 0, 16))->toBe('2026-03-02 08:00')
        ->and(substr((string) daysOneToFive()->max('starts_at'), 0, 16))->toBe('2026-03-06 12:00')
        ->and((int) daysOneToFive()->sum('capacity'))->toBe(155)
        ->and(round((float) daysOneToFive()->avg('capacity'), 4))->toBe(round(155 / 11, 4));
});

it('visits every occurrence exactly once when chunking or iterating lazily (US2-5)', function (Closure $iterate) {
    $seen = $iterate(daysOneToFive());

    sort($seen);

    expect($seen)->toHaveCount(11)
        ->and(array_unique($seen))->toHaveCount(11);
})->with([
    'chunk' => [function ($query) {
        $seen = [];
        $query->chunk(3, function ($chunk) use (&$seen) {
            $seen = [...$seen, ...labels($chunk)];
        });

        return $seen;
    }],
    'lazy' => [fn ($query) => labels($query->lazy(3))],
    'cursor' => [fn ($query) => labels($query->cursor())],
]);

it('paginates simply over occurrences', function () {
    $page = daysOneToFive()->orderBy('starts_at')->simplePaginate(4, page: 3);

    expect(labels($page->items()))->toBe(['Bravo 05 12:00', 'Alpha 06 08:00', 'Bravo 06 12:00'])
        ->and($page->hasMorePages())->toBeFalse();
});

it('pages occurrences that tie on every requested ordering stably (FR-016)', function () {
    Meeting::create(['title' => 'Alpha', 'location' => 'Room C', 'starts_at' => '2026-03-02 08:00:00', 'ends_at' => '2026-03-02 09:00:00', 'recurrence_rule' => 'FREQ=DAILY']);

    $pages = fn () => collect(range(1, 4))
        ->flatMap(fn (int $page) => daysOneToFive()->orderBy('starts_at')->orderBy('title')->paginate(4, page: $page)->items())
        ->map(fn (Meeting $m) => $m->location.' '.$m->starts_at->format('d H:i'))
        ->all();

    $first = $pages();

    expect($first)->toHaveCount(16)
        ->and(array_unique($first))->toHaveCount(16)
        ->and($pages())->toBe($first);
});

it('keeps occurrences distinct in collection set operations', function () {
    $all = daysOneToFive()->get();

    expect($all->unique())->toHaveCount(11)
        ->and($all->merge($all))->toHaveCount(11)
        ->and($all->diff($all->take(4)))->toHaveCount(7)
        ->and($all->intersect($all->take(4)))->toHaveCount(4);
});

it('keeps the identity of occurrences fetched with an explicit select', function () {
    $meetings = daysOneToFive()->select(['title', 'starts_at'])->get();

    $virtual = $meetings->first(fn (Meeting $m) => $m->title === 'Alpha');

    expect($meetings)->toHaveCount(11)
        ->and($virtual->getAttributes()['groundhog_series_key'] ?? null)->not->toBeNull()
        ->and($virtual->isVirtualOccurrence())->toBeTrue();
});

it('groups and de-duplicates over occurrences', function () {
    $perLocation = daysOneToFive()
        ->select('location', DB::raw('count(*) as n'))
        ->groupBy('location')
        ->havingRaw('count(*) > 1')
        ->orderBy('location')
        ->get()
        ->map(fn (Meeting $m) => $m->location.'='.$m->n)
        ->all();

    $titles = daysOneToFive()->distinct()->select('title')->orderBy('title')->pluck('title')->all();

    expect($perLocation)->toBe(['Room A=6', 'Room B=5'])
        ->and($titles)->toBe(['Alpha', 'Bravo', 'Charlie']);
});
