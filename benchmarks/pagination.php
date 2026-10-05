<?php

/*
 * SC-003 measurement: with 1,000 daily series and a one-year window, one page of 25
 * occurrences including the total count. Occurrences are generated per query, so the reference
 * times are 7.5 s on PostgreSQL and SQLite and 8 s on MySQL (spec SC-003); slower is a regression.
 *
 * Wall-clock timings cannot be a deterministic test (Constitution II), so this is a script:
 * `composer bench`. It uses the database from DB_CONNECTION (default: in-memory SQLite) and
 * recreates the workbench schema there, so point it at a scratch database only.
 */

use BoysFromTheFactory\Groundhog\GroundhogServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Workbench\App\Models\Meeting;

require __DIR__.'/../vendor/autoload.php';

const SERIES = 1000;
const PER_PAGE = 25;

$app = Application::create(options: ['extra' => ['providers' => [GroundhogServiceProvider::class]]]);
configureDatabase($app);

$app->make(Kernel::class)->call('migrate:fresh', [
    '--path' => realpath(__DIR__.'/../workbench/database/migrations'),
    '--realpath' => true,
    '--force' => true,
]);

$start = CarbonImmutable::today();
$seeded = microtime(true);

DB::transaction(function () use ($start) {
    for ($series = 1; $series <= SERIES; $series++) {
        $startsAt = $start->addMinutes($series % 600)->addHours(6);

        Meeting::create([
            'title' => "Series {$series}",
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'recurrence_rule' => 'FREQ=DAILY',
        ]);
    }
});

printf("Seeded %d daily series in %.1f s on %s\n", SERIES, microtime(true) - $seeded, DB::connection()->getDriverName());

$window = fn () => Meeting::whereBetween('starts_at', [$start, $start->addYear()])->orderBy('starts_at');
$lastPage = (int) ceil($window()->count() / PER_PAGE);
$budget = match (DB::connection()->getDriverName()) {
    'mysql' => 8.0,
    default => 7.5,
};
$failed = false;

foreach ([1, 200, $lastPage] as $page) {
    $began = microtime(true);
    $paginator = $window()->paginate(PER_PAGE, page: $page);
    $seconds = microtime(true) - $began;
    $failed = $failed || $seconds >= $budget;

    printf("page %5d of %d: %d rows, total %d, %.3f s (budget %.1f s)%s\n", $page, $lastPage, count($paginator->items()), $paginator->total(), $seconds, $budget, $seconds >= $budget ? '  OVER BUDGET' : '');
}

exit($failed ? 1 : 0);

function configureDatabase(LaravelApplication $app): void
{
    $connection = getenv('DB_CONNECTION') ?: 'sqlite';
    $config = $app->make('config');

    $config->set('database.default', $connection);

    if ($connection === 'sqlite') {
        $config->set('database.connections.sqlite.database', ':memory:');
        $config->set('database.connections.sqlite.foreign_key_constraints', true);

        return;
    }

    foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $variable) {
        $value = getenv($variable);

        if ($value !== false) {
            $config->set("database.connections.{$connection}.{$key}", $value);
        }
    }
}
