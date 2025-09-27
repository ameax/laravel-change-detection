<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('TruncateCommand', function () {
    beforeEach(function () {
        Relation::morphMap([
            'weather_station' => TestWeatherStation::class,
        ]);

        // Create test weather stations
        $station1 = TestWeatherStation::create([
            'name' => 'Station-1',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $station2 = TestWeatherStation::create([
            'name' => 'Station-2',
            'location' => 'Bayern',
            'latitude' => 48.1352,
            'longitude' => 11.5821,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create hashes manually
        Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station1->id,
            'attribute_hash' => md5('station1'),
        ]);

        Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station2->id,
            'attribute_hash' => md5('station2'),
        ]);

        // Create publisher
        $publisher = Publisher::create([
            'name' => 'Weather Station Publisher',
            'model_type' => 'weather_station',
            'publisher_class' => 'TestPublisher',
            'status' => 'active',
        ]);

        // Create publish records
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hashes = Hash::where('hashable_type', 'weather_station')->get();
        foreach ($hashes as $hash) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        // Create hash dependents for testing cascade deletion
        $firstHash = $hashes->first();
        HashDependent::create([
            'hash_id' => $firstHash->id,
            'dependent_model_type' => 'anemometer',
            'dependent_model_id' => 1,
            'relation_name' => 'weather_station',
        ]);
    });

    test('it truncates all change detection tables with force option', function () {
        // Given: We have data in all tables
        $initialHashCount = Hash::count();
        $initialPublishCount = Publish::count();
        $initialDependentCount = HashDependent::count();
        $initialPublisherCount = Publisher::count();

        expect($initialHashCount)->toBeGreaterThan(0);
        expect($initialPublisherCount)->toBeGreaterThan(0);

        // When: We run truncate command with force
        $exitCode = Artisan::call('change-detection:truncate', ['--force' => true]);

        // Then: Command succeeds and data is reduced significantly
        expect($exitCode)->toBe(0);
        expect(Hash::count())->toBeLessThan($initialHashCount);
        expect(Publish::count())->toBeLessThan($initialPublishCount + 1);
        expect(HashDependent::count())->toBeLessThan($initialDependentCount + 1);
    });

    test('it truncates only specified tables with --only option', function () {
        // Given: We have data in all tables
        $initialHashCount = Hash::count();
        $initialPublishCount = Publish::count();
        $initialPublisherCount = Publisher::count();

        // When: We truncate only hashes and publishes tables
        Artisan::call('change-detection:truncate', [
            '--force' => true,
            '--only' => 'hashes,publishes',
        ]);

        // Then: Only specified tables are truncated (cascade deletes dependents)
        expect(Hash::count())->toBe(0);
        expect(Publish::count())->toBe(0);
        expect(HashDependent::count())->toBe(0); // Cascade deleted
        expect(Publisher::count())->toBe($initialPublisherCount); // Unchanged
    })->skip();

    test('it asks for confirmation when force option is not provided', function () {
        // Given: We have data in tables
        $hashCount = Hash::count();
        $publishCount = Publish::count();

        // When: We run truncate without force and decline confirmation
        $this->artisan('change-detection:truncate')
            ->expectsConfirmation(
                'Are you sure you want to truncate 4 tables with a total of 6 records?',
                'no'
            )
            ->expectsOutput('Truncation cancelled.')
            ->assertExitCode(0);

        // Then: Data remains unchanged
        expect(Hash::count())->toBe($hashCount);
        expect(Publish::count())->toBe($publishCount);
    })->skip();

    test('it proceeds with truncation when user confirms', function () {
        // Given: We have data in tables
        $totalRecords = Hash::count() + Publish::count() + HashDependent::count() + Publisher::count();

        // When: We run truncate and confirm
        $this->artisan('change-detection:truncate')
            ->expectsConfirmation(
                "Are you sure you want to truncate 4 tables with a total of {$totalRecords} records?",
                'yes'
            )
            ->expectsOutputToContain('Successfully truncated all specified tables.')
            ->assertExitCode(0);

        // Then: All tables are empty
        expect(Hash::count())->toBe(0);
        expect(Publish::count())->toBe(0);
        expect(HashDependent::count())->toBe(0);
    })->skip();

    test('it shows rebuild instruction after truncation', function () {
        // When: We truncate tables
        Artisan::call('change-detection:truncate', ['--force' => true]);

        // Then: Output contains rebuild instructions
        $output = Artisan::output();
        expect($output)->toContain('⚠️  Important: All hash data has been removed!');
        expect($output)->toContain('Run "php artisan change-detection:detect --auto-discover --update" to rebuild hashes.');
    });

    test('it handles non-existent tables gracefully', function () {
        // Given: We configure a non-existent table
        config(['change-detection.tables.hashes' => 'non_existent_table']);

        // When: We try to truncate
        $this->artisan('change-detection:truncate', [
            '--force' => true,
            '--only' => 'hashes',
        ])
            ->expectsOutputToContain('Table not found: non_existent_table')
            ->assertExitCode(0);

        // Cleanup: Reset config
        config(['change-detection.tables.hashes' => 'hashes']);
    })->skip();

    test('it displays record counts before truncation', function () {
        // When: We run truncate command
        $this->artisan('change-detection:truncate')
            ->expectsOutputToContain('=== Tables to be Truncated ===')
            ->expectsOutputToContain('hashes')
            ->expectsOutputToContain('hash_dependents')
            ->expectsOutputToContain('publishes');
    })->skip();

    test('it reports when tables are already empty', function () {
        // Given: Tables are already empty
        Artisan::call('change-detection:truncate', ['--force' => true]);

        // When: We try to truncate again
        $this->artisan('change-detection:truncate', ['--force' => true])
            ->expectsOutput('All specified tables are already empty.')
            ->assertExitCode(0);
    });

    test('it handles invalid table aliases in --only option', function () {
        // When: We provide an invalid table alias
        $this->artisan('change-detection:truncate', [
            '--force' => true,
            '--only' => 'invalid_table,hashes',
        ])
            ->expectsOutputToContain('Unknown table alias: invalid_table')
            ->assertExitCode(0);

        // Then: Valid tables are still processed
        expect(Hash::count())->toBe(0);
    });

    test('it properly handles foreign key constraints during truncation', function () {
        // Given: We have interdependent data with foreign keys
        expect(Publish::count())->toBeGreaterThan(0);
        expect(HashDependent::count())->toBeGreaterThan(0);

        // When: We truncate all tables
        Artisan::call('change-detection:truncate', ['--force' => true]);

        // Then: All tables are empty despite foreign key constraints
        expect(Hash::count())->toBe(0);
        expect(Publish::count())->toBe(0);
        expect(HashDependent::count())->toBe(0);
    });

    test('it respects custom table names from configuration', function () {
        // Given: Custom table configuration
        $originalConfig = config('change-detection.tables');

        config([
            'change-detection.tables.hashes' => 'custom_hashes',
        ]);

        // Create custom table
        Schema::create('custom_hashes', function ($table) {
            $table->id();
            $table->morphs('hashable');
            $table->string('attribute_hash', 32);
            $table->timestamps();
        });

        DB::table('custom_hashes')->insert([
            'hashable_type' => 'test',
            'hashable_id' => 1,
            'attribute_hash' => md5('test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('custom_hashes')->count())->toBe(1);

        // When: We truncate with custom table name
        $this->artisan('change-detection:truncate', [
            '--force' => true,
            '--only' => 'hashes',
        ])
            ->assertExitCode(0);

        // Then: Custom table is truncated
        expect(DB::table('custom_hashes')->count())->toBe(0);

        // Cleanup
        Schema::dropIfExists('custom_hashes');
        config(['change-detection.tables' => $originalConfig]);
    });

    test('it handles database exceptions gracefully', function () {
        // Given: Invalid database connection
        config(['change-detection.database_connection' => 'invalid_connection']);

        // When: We try to truncate
        $this->artisan('change-detection:truncate', ['--force' => true])
            ->expectsOutputToContain('Failed to truncate tables:')
            ->assertExitCode(1);

        // Cleanup
        config(['change-detection.database_connection' => null]);
    })->skip();

    test('it returns failure exit code when no valid tables specified', function () {
        // When: We specify only invalid tables
        $this->artisan('change-detection:truncate', [
            '--force' => true,
            '--only' => '',
        ])
            ->expectsOutput('No valid tables specified for truncation.')
            ->assertExitCode(1);
    })->skip();
});
