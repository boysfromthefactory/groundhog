<?php

namespace BoysFromTheFactory\Groundhog;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the publishable configuration (tag `groundhog-config`) and the migration that
 * creates the package tables (tag `groundhog-migrations`).
 */
class GroundhogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('groundhog')
            ->hasConfigFile()
            ->hasMigration('create_groundhog_tables');
    }
}
