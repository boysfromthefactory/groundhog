<?php

namespace BoysFromTheFactory\Groundhog;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the publishable configuration (tag `groundhog-config`) and the migrations that
 * create the package tables and upgrade older installations (tag `groundhog-migrations`).
 */
class GroundhogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('groundhog')
            ->hasConfigFile()
            ->hasMigrations(['create_groundhog_tables', 'drop_groundhog_occurrence_index']);
    }
}
