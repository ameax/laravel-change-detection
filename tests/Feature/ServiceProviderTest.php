<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Commands\ProcessPublishesCommand;
use Ameax\LaravelChangeDetection\Commands\PurgeDeletedHashesCommand;
use Ameax\LaravelChangeDetection\Commands\SyncCommand;
use Ameax\LaravelChangeDetection\Commands\TruncateCommand;
use Ameax\LaravelChangeDetection\LaravelChangeDetectionServiceProvider;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\CompositeHashCalculator;
use Ameax\LaravelChangeDetection\Services\CrossDatabaseQueryBuilder;
use Ameax\LaravelChangeDetection\Services\DependencyHashCalculator;
use Ameax\LaravelChangeDetection\Services\HashPurger;
use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

describe('LaravelChangeDetectionServiceProvider', function () {
    test('it registers the service provider', function () {
        // Given: The application is booted

        // Then: Service provider is registered
        $providers = app()->getLoadedProviders();
        expect($providers)->toHaveKey(LaravelChangeDetectionServiceProvider::class);
        expect($providers[LaravelChangeDetectionServiceProvider::class])->toBeTrue();
    });

    test('it publishes the configuration file', function () {
        // Given: Config publishing is available
        $configPath = config_path('change-detection.php');

        // Clean up any existing config
        if (File::exists($configPath)) {
            File::delete($configPath);
        }

        // When: We publish the config
        Artisan::call('vendor:publish', [
            '--provider' => LaravelChangeDetectionServiceProvider::class,
            '--tag' => 'change-detection-config',
        ]);

        // Then: Config file is published
        expect(File::exists($configPath))->toBeTrue();

        // Verify config content
        $config = include $configPath;
        expect($config)->toHaveKeys([
            'database_connection',
            'tables',
            'retry_intervals',
            'queues',
            'hash_algorithm',
        ]);

        // Cleanup
        File::delete($configPath);
    });

    test('it publishes the migration file', function () {
        // Given: Migration publishing is available
        $migrationPath = database_path('migrations');
        $migrationPattern = $migrationPath . '/*_create_change_detection_tables.php';

        // Clean up any existing migrations
        foreach (glob($migrationPattern) as $file) {
            File::delete($file);
        }

        // When: We publish the migration
        Artisan::call('vendor:publish', [
            '--provider' => LaravelChangeDetectionServiceProvider::class,
            '--tag' => 'change-detection-migrations',
        ]);

        // Then: Migration file is published
        $migrations = glob($migrationPattern);
        expect(count($migrations))->toBe(1);

        // Verify migration content
        $migrationContent = File::get($migrations[0]);
        expect($migrationContent)->toContain('create_change_detection_tables');
        expect($migrationContent)->toContain('hashes');
        expect($migrationContent)->toContain('publishers');
        expect($migrationContent)->toContain('publishes');
        expect($migrationContent)->toContain('hash_dependents');

        // Cleanup
        foreach ($migrations as $file) {
            File::delete($file);
        }
    })->skip();

    test('it registers all required commands', function () {
        // Then: All commands are registered
        $commands = Artisan::all();

        expect($commands)->toHaveKey('change-detection:process-publishes');
        expect($commands)->toHaveKey('change-detection:purge');
        expect($commands)->toHaveKey('change-detection:sync');
        expect($commands)->toHaveKey('change-detection:truncate');

        // Verify command classes
        expect($commands['change-detection:process-publishes'])->toBeInstanceOf(ProcessPublishesCommand::class);
        expect($commands['change-detection:purge'])->toBeInstanceOf(PurgeDeletedHashesCommand::class);
        expect($commands['change-detection:sync'])->toBeInstanceOf(SyncCommand::class);
        expect($commands['change-detection:truncate'])->toBeInstanceOf(TruncateCommand::class);
    });

    test('it registers all required services as singletons', function () {
        // Then: All services are registered as singletons
        $services = [
            CrossDatabaseQueryBuilder::class,
            MySQLHashCalculator::class,
            DependencyHashCalculator::class,
            CompositeHashCalculator::class,
            ChangeDetector::class,
            BulkHashProcessor::class,
            OrphanedHashDetector::class,
            HashPurger::class,
        ];

        foreach ($services as $service) {
            // Verify service is bound
            expect(app()->bound($service))->toBeTrue();

            // Verify it's a singleton (same instance returned)
            $instance1 = app($service);
            $instance2 = app($service);
            expect($instance1)->toBe($instance2);
        }
    });

    test('it loads configuration with correct defaults', function () {
        // Given: Default configuration is loaded

        // Then: Configuration has expected structure and defaults
        expect(config('change-detection'))->toBeArray();
        expect(config('change-detection.database_connection'))->toBeNull();
        expect(config('change-detection.tables'))->toBeArray();
        expect(config('change-detection.tables.hashes'))->toBe('hashes');
        expect(config('change-detection.tables.publishers'))->toBe('publishers');
        expect(config('change-detection.tables.publishes'))->toBe('publishes');
        expect(config('change-detection.tables.hash_dependents'))->toBe('hash_dependents');
        expect(config('change-detection.retry_intervals'))->toBeArray();
        expect(config('change-detection.retry_intervals.1'))->toBe(30);
        expect(config('change-detection.retry_intervals.2'))->toBe(300);
        expect(config('change-detection.retry_intervals.3'))->toBe(21600);
        expect(config('change-detection.queues.publish'))->toBe('default');
        expect(config('change-detection.queues.detect_changes'))->toBe('default');
        expect(config('change-detection.hash_algorithm'))->toBe('md5');
    });

    test('it allows configuration overrides', function () {
        // Given: Custom configuration
        Config::set('change-detection.database_connection', 'custom_connection');
        Config::set('change-detection.tables.hashes', 'custom_hashes');
        Config::set('change-detection.hash_algorithm', 'sha256');

        // Then: Configuration is overridden
        expect(config('change-detection.database_connection'))->toBe('custom_connection');
        expect(config('change-detection.tables.hashes'))->toBe('custom_hashes');
        expect(config('change-detection.hash_algorithm'))->toBe('sha256');

        // Reset configuration
        Config::set('change-detection.database_connection', null);
        Config::set('change-detection.tables.hashes', 'hashes');
        Config::set('change-detection.hash_algorithm', 'md5');
    });

    test('it provides package name correctly', function () {
        // Given: Service provider instance
        $provider = app()->getProvider(LaravelChangeDetectionServiceProvider::class);

        // Use reflection to check the package name
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('configurePackage');
        $method->setAccessible(true);

        // Create a mock package to capture configuration
        $package = new class {
            public $name;
            public $hasConfig = false;
            public $hasMigration = false;
            public $commands = [];

            public function name($name) {
                $this->name = $name;
                return $this;
            }

            public function hasConfigFile() {
                $this->hasConfig = true;
                return $this;
            }

            public function hasMigration($migration) {
                $this->hasMigration = $migration;
                return $this;
            }

            public function hasCommand($command) {
                $this->commands[] = $command;
                return $this;
            }
        };

        $method->invoke($provider, $package);

        // Then: Package is configured correctly
        expect($package->name)->toBe('laravel-change-detection');
        expect($package->hasConfig)->toBeTrue();
        expect($package->hasMigration)->toBe('create_change_detection_tables');
        expect($package->commands)->toContain(ProcessPublishesCommand::class);
        expect($package->commands)->toContain(PurgeDeletedHashesCommand::class);
        expect($package->commands)->toContain(SyncCommand::class);
        expect($package->commands)->toContain(TruncateCommand::class);
    })->skip();

    test('it registers services before booting', function () {
        // Given: A fresh application instance
        // This is already handled by the test setup

        // When: Service provider is registered
        // This happens automatically

        // Then: Services are available immediately
        expect(app()->make(CrossDatabaseQueryBuilder::class))->toBeInstanceOf(CrossDatabaseQueryBuilder::class);
        expect(app()->make(MySQLHashCalculator::class))->toBeInstanceOf(MySQLHashCalculator::class);
        expect(app()->make(DependencyHashCalculator::class))->toBeInstanceOf(DependencyHashCalculator::class);
        expect(app()->make(CompositeHashCalculator::class))->toBeInstanceOf(CompositeHashCalculator::class);
        expect(app()->make(ChangeDetector::class))->toBeInstanceOf(ChangeDetector::class);
        expect(app()->make(BulkHashProcessor::class))->toBeInstanceOf(BulkHashProcessor::class);
        expect(app()->make(OrphanedHashDetector::class))->toBeInstanceOf(OrphanedHashDetector::class);
        expect(app()->make(HashPurger::class))->toBeInstanceOf(HashPurger::class);
    });

    test('it handles missing configuration gracefully', function () {
        // Given: Configuration is temporarily removed
        $originalConfig = config('change-detection');
        Config::set('change-detection', null);

        // When: We try to access services
        $service = app(CrossDatabaseQueryBuilder::class);

        // Then: Service still works with defaults
        expect($service)->toBeInstanceOf(CrossDatabaseQueryBuilder::class);

        // Restore configuration
        Config::set('change-detection', $originalConfig);
    });

    test('it supports dependency injection for services', function () {
        // Given: A class that depends on package services
        $testClass = new class(
            app(ChangeDetector::class),
            app(BulkHashProcessor::class)
        ) {
            public function __construct(
                public ChangeDetector $detector,
                public BulkHashProcessor $processor
            ) {}
        };

        // Then: Dependencies are injected correctly
        expect($testClass->detector)->toBeInstanceOf(ChangeDetector::class);
        expect($testClass->processor)->toBeInstanceOf(BulkHashProcessor::class);
    });

    test('it maintains singleton instances across requests', function () {
        // Given: Services are resolved multiple times
        $detector1 = app(ChangeDetector::class);
        $detector2 = app()->make(ChangeDetector::class);
        $detector3 = resolve(ChangeDetector::class);

        // Then: Same instance is returned
        expect($detector1)->toBe($detector2);
        expect($detector2)->toBe($detector3);
    });

    test('it allows command discovery through artisan', function () {
        // When: We list artisan commands
        $output = Artisan::call('list', ['namespace' => 'change-detection']);

        // Then: Our commands are listed
        $outputString = Artisan::output();
        expect($outputString)->toContain('change-detection:detect');
        expect($outputString)->toContain('change-detection:process-publishes');
        expect($outputString)->toContain('change-detection:purge');
        expect($outputString)->toContain('change-detection:truncate');
    })->skip();
});
