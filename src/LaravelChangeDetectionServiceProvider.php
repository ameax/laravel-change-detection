<?php

namespace Ameax\LaravelChangeDetection;

use Ameax\LaravelChangeDetection\Commands\ProcessPublishesCommand;
use Ameax\LaravelChangeDetection\Commands\PurgeDeletedHashesCommand;
use Ameax\LaravelChangeDetection\Commands\SyncCommand;
use Ameax\LaravelChangeDetection\Commands\TruncateCommand;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\CompositeHashCalculator;
use Ameax\LaravelChangeDetection\Services\CrossDatabaseQueryBuilder;
use Ameax\LaravelChangeDetection\Services\DependencyHashCalculator;
use Ameax\LaravelChangeDetection\Services\HashPurger;
use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
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
            ->hasMigration('create_change_detection_tables')
            ->hasCommand(ProcessPublishesCommand::class)
            ->hasCommand(PurgeDeletedHashesCommand::class)
            ->hasCommand(SyncCommand::class)
            ->hasCommand(TruncateCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(CrossDatabaseQueryBuilder::class);
        $this->app->singleton(MySQLHashCalculator::class);
        $this->app->singleton(DependencyHashCalculator::class);
        $this->app->singleton(CompositeHashCalculator::class);
        $this->app->singleton(ChangeDetector::class);
        $this->app->singleton(BulkHashProcessor::class);
        $this->app->singleton(OrphanedHashDetector::class);
        $this->app->singleton(HashPurger::class);
    }
}
