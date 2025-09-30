<?php

namespace Ameax\LaravelChangeDetection\Tests;

use Ameax\LaravelChangeDetection\LaravelChangeDetectionServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Ameax\\LaravelChangeDetection\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelChangeDetectionServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel_change_detection_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
    }

    protected function setUpDatabase()
    {
        $isMigrated = (bool) env('MIGRATED');

        if (! RefreshDatabaseState::$migrated) {
            RefreshDatabaseState::$migrated = $isMigrated;
        }

        if (! RefreshDatabaseState::$migrated) {
            // Drop all existing tables first
            $this->dropAllTables();

            // Run package migrations
            $packageMigration = include __DIR__.'/../database/migrations/create_change_detection_tables.php.stub';
            $packageMigration->up();

            // Run test migrations
            $testMigration = include __DIR__.'/migrations/create_test_tables.php';
            $testMigration->up();

            RefreshDatabaseState::$migrated = true;
        } else {
            // Clear data but keep structure
            $this->clearDatabaseData();
        }
    }

    protected function dropAllTables()
    {
        DB::connection('testing')->statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            // Test tables
            'test_microscope_manufacturer_registry',
            'test_microscope_certification_registry',
            'test_microscopes',
            'test_laboratory_facilities',
            'test_anemometers',
            'test_windvanes',
            'test_weather_stations',
            'test_animals',
            'test_cars',
            'test_models',
            // Package tables
            'hash_dependents',
            'publishes',
            'publishers',
            'hashes',
            // Laravel tables
            'migrations',
        ];

        foreach ($tables as $table) {
            DB::connection('testing')->statement("DROP TABLE IF EXISTS `{$table}`");
        }

        DB::connection('testing')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function clearDatabaseData()
    {
        DB::connection('testing')->statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            // Test tables
            'test_microscope_manufacturer_registry',
            'test_microscope_certification_registry',
            'test_microscopes',
            'test_laboratory_facilities',
            'test_anemometers',
            'test_windvanes',
            'test_weather_stations',
            'test_animals',
            'test_cars',
            'test_models',
            // Package tables
            'hash_dependents',
            'publishes',
            'publishers',
            'hashes',
        ];

        foreach ($tables as $table) {
            DB::connection('testing')->table($table)->truncate();
        }

        DB::connection('testing')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
