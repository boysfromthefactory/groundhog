<?php

namespace BoysFromTheFactory\Groundhog\Tests;

use BoysFromTheFactory\Groundhog\GroundhogServiceProvider;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Open-ended queries and save-time limit checks measure the horizon from now, so every
        // test runs at a fixed instant; tests that need another one call travelTo() themselves.
        $this->travelTo(Carbon::parse('2026-03-01 00:00:00'));
    }

    protected function getPackageProviders($app)
    {
        return [
            GroundhogServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');

        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('database.connections.sqlite.foreign_key_constraints', true);

            return;
        }

        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $variable) {
            $value = getenv($variable);

            if ($value !== false) {
                $app['config']->set("database.connections.{$connection}.{$key}", $value);
            }
        }
    }
}
