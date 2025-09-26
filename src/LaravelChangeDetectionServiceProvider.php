<?php

namespace Ameax\LaravelChangeDetection;

use Ameax\LaravelChangeDetection\Commands\ProcessPublishesCommand;
use Ameax\LaravelChangeDetection\Commands\PurgeDeletedHashesCommand;
use Ameax\LaravelChangeDetection\Commands\SyncCommand;
use Ameax\LaravelChangeDetection\Commands\TruncateCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelChangeDetectionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-change-detection')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_change_detection_tables')
            ->hasCommand(ProcessPublishesCommand::class)
            ->hasCommand(PurgeDeletedHashesCommand::class)
            ->hasCommand(SyncCommand::class)
            ->hasCommand(TruncateCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\CrossDatabaseQueryBuilder::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\MySQLHashCalculator::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\DependencyHashCalculator::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\CompositeHashCalculator::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\ChangeDetector::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\BulkHashProcessor::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\OrphanedHashDetector::class);
        $this->app->singleton(\Ameax\LaravelChangeDetection\Services\HashPurger::class);
    }
}
