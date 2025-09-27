<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

describe('PurgeDeletedHashesCommand', function () {
    beforeEach(function () {
        Relation::morphMap([
            'weather_station' => TestWeatherStation::class,
            'anemometer' => TestAnemometer::class,
        ]);

        // Create weather stations manually
        $station1 = TestWeatherStation::create([
            'name' => 'Station-Active-1',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $station2 = TestWeatherStation::create([
            'name' => 'Station-Active-2',
            'location' => 'Bayern',
            'latitude' => 48.1352,
            'longitude' => 11.5821,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create anemometers
        $anemometer1 = TestAnemometer::create([
            'weather_station_id' => $station1->id,
            'wind_speed' => 25.5,
            'max_speed' => 35.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $anemometer2 = TestAnemometer::create([
            'weather_station_id' => $station1->id,
            'wind_speed' => 30.0,
            'max_speed' => 40.0,
            'sensor_type' => 'mechanical',
        ]);

        // Create hashes manually
        $hash1 = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station1->id,
            'attribute_hash' => md5('station1'),
            'deleted_at' => Carbon::now()->subDays(10),
        ]);

        $hash2 = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station2->id,
            'attribute_hash' => md5('station2'),
            'deleted_at' => Carbon::now()->subDays(5),
        ]);

        $hash3 = Hash::create([
            'hashable_type' => 'anemometer',
            'hashable_id' => $anemometer1->id,
            'attribute_hash' => md5('anemometer1'),
            'deleted_at' => Carbon::now()->subDays(30),
        ]);

        $hash4 = Hash::create([
            'hashable_type' => 'anemometer',
            'hashable_id' => $anemometer2->id,
            'attribute_hash' => md5('anemometer2'),
            // Not deleted - active hash
        ]);
    });

    test('it purges all deleted hashes with force option', function () {
        // Given: We have 3 deleted hashes and 1 active hash
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(3);
        expect(Hash::whereNull('deleted_at')->count())->toBe(1);

        // When: We purge all deleted hashes
        Artisan::call('change-detection:purge', ['--force' => true]);

        // Then: Only active hashes remain
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(0);
        expect(Hash::whereNull('deleted_at')->count())->toBe(1);
    });

    test('it purges only hashes older than specified days', function () {
        // Given: We have hashes deleted 30, 10, and 5 days ago
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(3);

        // When: We purge hashes older than 7 days
        Artisan::call('change-detection:purge', [
            '--older-than' => 7,
            '--force' => true,
        ]);

        // Then: Only hash deleted 5 days ago remains
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(1);
        expect(
            Hash::where('hashable_type', 'weather_station')
                ->where('hashable_id', 2)
                ->whereNotNull('deleted_at')
                ->exists()
        )->toBeTrue();
    });

    test('it shows dry-run preview without actually purging', function () {
        // Given: We have deleted hashes
        $deletedCount = Hash::whereNotNull('deleted_at')->count();

        // When: We run in dry-run mode
        $this->artisan('change-detection:purge', ['--dry-run' => true])
            ->expectsOutputToContain('=== All Deleted Hashes ===')
            ->expectsOutputToContain('weather_station')
            ->expectsOutputToContain('anemometer')
            ->expectsOutputToContain('Dry run mode - no records were actually deleted.')
            ->assertExitCode(0);

        // Then: No hashes are actually purged
        expect(Hash::whereNotNull('deleted_at')->count())->toBe($deletedCount);
    });

    test('it asks for confirmation without force option', function () {
        // Given: We have deleted hashes
        $deletedCount = Hash::whereNotNull('deleted_at')->count();

        // When: We decline the confirmation
        $this->artisan('change-detection:purge')
            ->expectsConfirmation(
                "Are you sure you want to purge ALL {$deletedCount} deleted hash records?",
                'no'
            )
            ->expectsOutput('Purge cancelled.')
            ->assertExitCode(0);

        // Then: Hashes remain unpurged
        expect(Hash::whereNotNull('deleted_at')->count())->toBe($deletedCount);
    });

    test('it proceeds with purge when user confirms', function () {
        // Given: We have deleted hashes
        $deletedCount = Hash::whereNotNull('deleted_at')->count();

        // When: We confirm the purge
        $this->artisan('change-detection:purge')
            ->expectsConfirmation(
                "Are you sure you want to purge ALL {$deletedCount} deleted hash records?",
                'yes'
            )
            ->expectsOutputToContain("Successfully purged {$deletedCount} hash records.")
            ->assertExitCode(0);

        // Then: Deleted hashes are purged
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(0);
    });

    test('it displays statistics grouped by model type', function () {
        // When: We run the command
        $this->artisan('change-detection:purge', ['--dry-run' => true])
            ->expectsOutputToContain('=== All Deleted Hashes ===')
            ->expectsOutputToContain('weather_station')
            ->expectsOutputToContain('anemometer')
            ->expectsOutputToContain('Total records to purge: 3');
    });

    test('it reports when no deleted hashes found', function () {
        // Given: No deleted hashes exist
        Hash::whereNotNull('deleted_at')->forceDelete();

        // When: We try to purge
        $this->artisan('change-detection:purge', ['--force' => true])
            ->expectsOutput('No deleted hashes found to purge.')
            ->assertExitCode(0);
    });

    test('it cascades deletion to related publishes and dependents', function () {
        // Given: We have a deleted hash with related records
        $hash = Hash::where('hashable_type', 'weather_station')
            ->whereNotNull('deleted_at')
            ->first();

        // Create related records
        $publisher = Publisher::create([
            'name' => 'Weather Station Publisher',
            'model_type' => 'weather_station',
            'publisher_class' => 'TestPublisher',
            'status' => 'active',
        ]);

        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        HashDependent::create([
            'hash_id' => $hash->id,
            'dependent_model_type' => 'anemometer',
            'dependent_model_id' => 1,
            'relation_name' => 'station',
        ]);

        expect(Publish::where('hash_id', $hash->id)->exists())->toBeTrue();
        expect(HashDependent::where('hash_id', $hash->id)->exists())->toBeTrue();

        // When: We purge the hash
        Artisan::call('change-detection:purge', ['--force' => true]);

        // Then: Related records are cascade deleted
        expect(Hash::find($hash->id))->toBeNull();
        expect(Publish::where('hash_id', $hash->id)->exists())->toBeFalse();
        expect(HashDependent::where('hash_id', $hash->id)->exists())->toBeFalse();
    });

    test('it shows cascade deletion note when records are purged', function () {
        // Given: We have deleted hashes
        expect(Hash::whereNotNull('deleted_at')->count())->toBeGreaterThan(0);

        // When: We purge them
        Artisan::call('change-detection:purge', ['--force' => true]);

        // Then: Output mentions cascade deletion
        $output = Artisan::output();
        expect($output)->toContain('Note: Related records in publishes and hash_dependents tables were automatically deleted via cascade.');
    });

    test('it handles date filtering correctly with --older-than option', function () {
        // Given: We have hashes deleted at specific times
        Hash::whereNotNull('deleted_at')->forceDelete();

        // Create hashes with precise timestamps
        $hash1 = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 100,
            'attribute_hash' => md5('test1'),
            'deleted_at' => Carbon::now()->subDays(15),
        ]);

        $hash2 = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 101,
            'attribute_hash' => md5('test2'),
            'deleted_at' => Carbon::now()->subDays(8),
        ]);

        $hash3 = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 102,
            'attribute_hash' => md5('test3'),
            'deleted_at' => Carbon::now()->subDays(3),
        ]);

        // When: We purge hashes older than 10 days
        Artisan::call('change-detection:purge', [
            '--older-than' => 10,
            '--force' => true,
        ]);

        // Then: Only the hash older than 10 days is purged
        expect(Hash::find($hash1->id))->toBeNull();
        expect(Hash::find($hash2->id))->not->toBeNull();
        expect(Hash::find($hash3->id))->not->toBeNull();
    });

    test('it displays correct confirmation message with --older-than option', function () {
        // Given: We have hashes deleted more than 7 days ago
        $olderThan = 7;
        $count = Hash::whereNotNull('deleted_at')
            ->where('deleted_at', '<', Carbon::now()->subDays($olderThan))
            ->count();

        // When: We run with --older-than option
        $this->artisan('change-detection:purge', ['--older-than' => $olderThan])
            ->expectsConfirmation(
                "Are you sure you want to purge {$count} hash records deleted more than {$olderThan} days ago?",
                'no'
            );
    });

    test('it handles zero value for --older-than option', function () {
        // When: We use --older-than=0 (should purge all)
        $count = Hash::whereNotNull('deleted_at')->count();

        Artisan::call('change-detection:purge', [
            '--older-than' => 0,
            '--force' => true,
        ]);

        // Then: All deleted hashes are purged
        expect(Hash::whereNotNull('deleted_at')->count())->toBe(0);
    });

    test('it displays statistics with --older-than filter', function () {
        // When: We run with date filter in dry-run mode
        $this->artisan('change-detection:purge', [
            '--older-than' => 7,
            '--dry-run' => true,
        ])
        ->expectsOutputToContain('=== Deleted Hashes Older Than 7 Days ===')
        ->expectsOutputToContain('weather_station')
        ->expectsOutputToContain('anemometer');
    });
});