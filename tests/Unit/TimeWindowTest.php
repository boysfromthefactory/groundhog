<?php

use BoysFromTheFactory\Groundhog\Query\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

function window(Closure $constraints): TimeWindow
{
    $query = DB::table('meetings');
    $constraints($query);

    return TimeWindow::fromQuery($query, 'starts_at', 'ends_at', 'meetings');
}

function at(?CarbonImmutable $date): ?string
{
    return $date?->format('Y-m-d H:i:s');
}

it('derives bounds from comparisons on the start column', function (Closure $constraints, ?string $lower, ?string $upper) {
    $window = window($constraints);

    expect(at($window->lowerStart()))->toBe($lower)
        ->and(at($window->upperStart()))->toBe($upper);
})->with([
    '<' => [fn (Builder $q) => $q->where('starts_at', '<', '2026-03-10 00:00:00'), null, '2026-03-10 00:00:00'],
    '<=' => [fn (Builder $q) => $q->where('starts_at', '<=', '2026-03-10 00:00:00'), null, '2026-03-10 00:00:00'],
    '=' => [fn (Builder $q) => $q->where('starts_at', '2026-03-10 09:00:00'), '2026-03-10 09:00:00', '2026-03-10 09:00:00'],
    '>' => [fn (Builder $q) => $q->where('starts_at', '>', '2026-03-10 00:00:00'), '2026-03-10 00:00:00', null],
    '>=' => [fn (Builder $q) => $q->where('starts_at', '>=', '2026-03-10 00:00:00'), '2026-03-10 00:00:00', null],
    'between' => [fn (Builder $q) => $q->whereBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-31 23:59:59']), '2026-03-01 00:00:00', '2026-03-31 23:59:59'],
    'tightest of several' => [fn (Builder $q) => $q->where('starts_at', '>', '2026-03-01 00:00:00')->where('starts_at', '>', '2026-03-05 00:00:00')->where('starts_at', '<', '2026-03-20 00:00:00')->where('starts_at', '<', '2026-03-10 00:00:00'), '2026-03-05 00:00:00', '2026-03-10 00:00:00'],
    'no time constraint' => [fn (Builder $q) => $q->where('title', 'Standup'), null, null],
]);

it('derives an upper start bound from end comparisons, because a start never follows its end', function (Closure $constraints, ?string $upper) {
    expect(at(window($constraints)->upperStart()))->toBe($upper);
})->with([
    '<' => [fn (Builder $q) => $q->where('ends_at', '<', '2026-03-10 00:00:00'), '2026-03-10 00:00:00'],
    '<=' => [fn (Builder $q) => $q->where('ends_at', '<=', '2026-03-10 00:00:00'), '2026-03-10 00:00:00'],
    'between' => [fn (Builder $q) => $q->whereBetween('ends_at', ['2026-03-01 00:00:00', '2026-03-10 00:00:00']), '2026-03-10 00:00:00'],
]);

it('uses a lower end bound only as the horizon base, never as a start bound', function () {
    $window = window(fn (Builder $q) => $q->where('ends_at', '>', '2026-03-10 00:00:00'));

    expect($window->lowerStart())->toBeNull()
        ->and($window->upperStart())->toBeNull()
        ->and(at($window->horizonBase()))->toBe('2026-03-10 00:00:00');
});

it('prefers the lower start bound as the horizon base', function () {
    $window = window(fn (Builder $q) => $q->where('starts_at', '>=', '2026-04-01 00:00:00')->where('ends_at', '>', '2026-03-10 00:00:00'));

    expect(at($window->horizonBase()))->toBe('2026-04-01 00:00:00');
});

it('treats the original-start identity column like the start column', function () {
    $window = window(fn (Builder $q) => $q->whereBetween('groundhog_original_starts_at', ['2026-03-01 00:00:00', '2026-03-31 00:00:00']));

    expect(at($window->lowerStart()))->toBe('2026-03-01 00:00:00')
        ->and(at($window->upperStart()))->toBe('2026-03-31 00:00:00');
});

it('traverses nested groups that are AND-ed', function () {
    $window = window(fn (Builder $q) => $q->where('title', 'Standup')->where(fn (Builder $q) => $q->where('starts_at', '<', '2026-03-10 00:00:00')->where('location', 'Room A')));

    expect(at($window->upperStart()))->toBe('2026-03-10 00:00:00');
});

it('ignores bounds that are not provably AND-ed with the whole query', function (Closure $constraints) {
    $window = window($constraints);

    expect($window->lowerStart())->toBeNull()
        ->and($window->upperStart())->toBeNull()
        ->and($window->horizonBase())->toBeNull();
})->with([
    'OR sibling' => [fn (Builder $q) => $q->where('starts_at', '<', '2026-03-10 00:00:00')->orWhere('title', 'x')],
    'inside an OR group' => [fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('starts_at', '<', '2026-03-10 00:00:00')->orWhere('title', 'x'))],
    'OR-ed group' => [fn (Builder $q) => $q->where('title', 'x')->orWhere(fn (Builder $q) => $q->where('starts_at', '<', '2026-03-10 00:00:00'))],
    'raw expression' => [fn (Builder $q) => $q->whereRaw('starts_at < ?', ['2026-03-10 00:00:00'])],
    'subquery' => [fn (Builder $q) => $q->whereExists(fn (Builder $q) => $q->from('rooms')->where('starts_at', '<', '2026-03-10 00:00:00'))],
    'negated between' => [fn (Builder $q) => $q->whereNotBetween('starts_at', ['2026-03-01 00:00:00', '2026-03-10 00:00:00'])],
    'another table' => [fn (Builder $q) => $q->where('rooms.starts_at', '<', '2026-03-10 00:00:00')],
]);

it('recognises qualified columns and DateTimeInterface values', function () {
    $window = window(fn (Builder $q) => $q->where('meetings.starts_at', '<', new DateTimeImmutable('2026-03-10 00:00:00')));

    expect(at($window->upperStart()))->toBe('2026-03-10 00:00:00');
});
