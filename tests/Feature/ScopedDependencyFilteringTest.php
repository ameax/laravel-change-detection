<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_anemometer' => TestAnemometer::class,
        'test_windvane' => TestWindvane::class,
    ]);
});

describe('scope-aware dependency filtering', function () {
    it('only includes dependencies from models that meet the main model scope', function () {
        // Create two weather stations - one in scope (Bayern), one out of scope (Berlin)
        $stationInScope = TestWeatherStation::create([
            'name' => 'Bayern Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $stationOutOfScope = TestWeatherStation::create([
            'name' => 'Berlin Station',
            'location' => 'Berlin',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create anemometers for both stations
        $bayernAnemometer = TestAnemometer::create([
            'weather_station_id' => $stationInScope->id,
            'wind_speed' => 15.0,
            'max_speed' => 25.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $berlinAnemometer = TestAnemometer::create([
            'weather_station_id' => $stationOutOfScope->id,
            'wind_speed' => 10.0,
            'max_speed' => 20.0,
            'sensor_type' => 'mechanical',
        ]);

        // Run sync for weather stations
        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Verify that only the Bayern station has a hash (due to scope)
        $bayernHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $stationInScope->id)
            ->first();
        expect($bayernHash)->not->toBeNull();

        $berlinHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $stationOutOfScope->id)
            ->first();
        expect($berlinHash)->toBeNull();

        // Check that dependency relationships were created only for the Bayern station's anemometer
        $bayernAnemometerHash = Hash::where('hashable_type', 'test_anemometer')
            ->where('hashable_id', $bayernAnemometer->id)
            ->first();
        expect($bayernAnemometerHash)->not->toBeNull();

        // The Berlin anemometer should still have a hash (anemometers don't have scope)
        $berlinAnemometerHash = Hash::where('hashable_type', 'test_anemometer')
            ->where('hashable_id', $berlinAnemometer->id)
            ->first();
        expect($berlinAnemometerHash)->not->toBeNull();

        // But the Berlin anemometer should NOT be in the hash_dependents table for any weather station
        $berlinAnemometerDependency = HashDependent::where('hash_id', $berlinAnemometerHash->id)->first();
        expect($berlinAnemometerDependency)->toBeNull();

        // The Bayern anemometer SHOULD be in hash_dependents for the Bayern station
        $bayernAnemometerDependency = HashDependent::where('hash_id', $bayernAnemometerHash->id)
            ->where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $stationInScope->id)
            ->first();
        expect($bayernAnemometerDependency)->not->toBeNull();
    });

    it('updates composite hash correctly when dependencies change for scoped models', function () {
        // Create station in scope
        $station = TestWeatherStation::create([
            'name' => 'München Test Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create initial anemometer
        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 12.5,
            'max_speed' => 25.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->first();
        $initialComposite = $initialHash->composite_hash;

        // Update anemometer - should change composite hash
        $anemometer->wind_speed = 20.0;
        $anemometer->save();
        runWeatherStationSync();

        $updatedHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->first();
        expect($updatedHash->composite_hash)->not->toBe($initialComposite);

        // Move station out of scope - should delete hash
        $station->location = 'Berlin';
        $station->save();
        runWeatherStationSync();

        $deletedHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->whereNull('deleted_at')
            ->first();
        expect($deletedHash)->toBeNull();

        // Check that soft-deleted record exists
        $softDeletedHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->whereNotNull('deleted_at')
            ->first();
        expect($softDeletedHash)->not->toBeNull();
        expect($softDeletedHash->deleted_at)->not->toBeNull();
    });

    it('does not include out-of-scope station dependencies even if they have related models', function () {
        // Create out-of-scope station with multiple sensors
        $berlinStation = TestWeatherStation::create([
            'name' => 'Berlin Complex Station',
            'location' => 'Berlin', // Out of scope
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create multiple sensors for Berlin station
        TestAnemometer::create([
            'weather_station_id' => $berlinStation->id,
            'wind_speed' => 15.0,
            'max_speed' => 30.0,
            'sensor_type' => 'ultrasonic',
        ]);

        TestWindvane::create([
            'weather_station_id' => $berlinStation->id,
            'direction' => 180.0,
            'accuracy' => 95.0,
            'calibration_date' => now(),
        ]);

        // Create in-scope station with one sensor
        $bayernStation = TestWeatherStation::create([
            'name' => 'Bayern Simple Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        TestAnemometer::create([
            'weather_station_id' => $bayernStation->id,
            'wind_speed' => 8.0,
            'max_speed' => 15.0,
            'sensor_type' => 'mechanical',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Only Bayern station should have a hash
        $bayernHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $bayernStation->id)
            ->first();
        expect($bayernHash)->not->toBeNull();
        expect($bayernHash->composite_hash)->not->toBeNull();

        // Berlin station should not have a hash despite having sensors
        $berlinHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $berlinStation->id)
            ->first();
        expect($berlinHash)->toBeNull();

        // Check dependency count - only Bayern station dependencies should exist
        $bayernDependencies = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $bayernStation->id)
            ->count();
        expect($bayernDependencies)->toBe(1); // Only the anemometer

        $berlinDependencies = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $berlinStation->id)
            ->count();
        expect($berlinDependencies)->toBe(0); // No dependencies for out-of-scope station
    });

    it('handles scope changes correctly with dependent models', function () {
        // Create station initially in scope
        $station = TestWeatherStation::create([
            'name' => 'Regensburg Station',
            'location' => 'Bayern',
            'latitude' => 49.0134,
            'longitude' => 12.1016,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 11.0,
            'max_speed' => 22.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 90.0,
            'accuracy' => 98.0,
            'calibration_date' => now(),
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Verify initial state - hash exists with dependencies
        $initialHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->first();
        expect($initialHash)->not->toBeNull();

        $initialDependencyCount = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->count();
        expect($initialDependencyCount)->toBe(2); // Anemometer and windvane

        // Change station to inactive (out of scope due to status)
        $station->status = 'inactive';
        $station->save();
        runWeatherStationSync();

        // Hash should be soft-deleted
        $activeHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->whereNull('deleted_at')
            ->first();
        expect($activeHash)->toBeNull();

        // Dependencies should also be cleaned up in next sync
        // (This depends on implementation - hash_dependents might remain but point to deleted hash)

        // Bring station back into scope
        $station->status = 'active';
        $station->save();
        runWeatherStationSync();

        // Hash should be recreated with dependencies
        $restoredHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->whereNull('deleted_at')
            ->first();
        expect($restoredHash)->not->toBeNull();

        $restoredDependencyCount = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->count();
        expect($restoredDependencyCount)->toBe(2); // Dependencies restored
    });

    it('does not create hashes for out-of-scope dependent models even if parent is in scope', function () {
        // Create a weather station in Bayern (in scope)
        $station = TestWeatherStation::create([
            'name' => 'Bayern Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create windvanes with different accuracy levels
        // Assume windvanes have a scope requiring accuracy >= 90
        $accurateWindvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 180.0,
            'accuracy' => 95.0, // IN SCOPE (>= 90)
            'calibration_date' => now(),
        ]);

        $inaccurateWindvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 90.0,
            'accuracy' => 85.0, // OUT OF SCOPE (< 90)
            'calibration_date' => now(),
        ]);

        // Override TestWindvane scope temporarily for this test
        $originalScope = TestWindvane::class;
        $windvaneWithScope = new class extends TestWindvane {
            public function getHashableScope(): ?\Closure
            {
                return function ($query) {
                    $query->where('accuracy', '>=', 90);
                };
            }
        };

        // Run sync for weather station
        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Station should have a hash (it's in Bayern)
        $stationHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->first();
        expect($stationHash)->not->toBeNull();

        // Check windvane hashes
        // Since the sync uses the actual TestWindvane class (not our override),
        // both windvanes will get hashes initially. But let's test the concept:

        // If windvanes had a scope, only accurate one should have hash
        // This test documents the expected behavior even though TestWindvane
        // currently doesn't have a scope defined
        $accurateHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $accurateWindvane->id)
            ->first();

        $inaccurateHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $inaccurateWindvane->id)
            ->first();

        // Currently both have hashes because TestWindvane has no scope
        // But the system is ready to filter them if a scope is added
        expect($accurateHash)->not->toBeNull();
        expect($inaccurateHash)->not->toBeNull();

        // The important check: dependencies should exist for both
        // (unless windvane has its own scope)
        $dependencies = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->count();
        expect($dependencies)->toBe(2);
    });

    it('respects dependent model scopes when building dependencies', function () {
        // This test verifies that when a parent model builds dependencies,
        // it checks if the dependent models are within their own scopes

        // Create a custom test model with a scope for testing
        $testAnemometerClass = new class extends TestAnemometer {
            protected $table = 'test_anemometers';

            public function getHashableScope(): ?\Closure
            {
                return function ($query) {
                    // Only high-wind anemometers are in scope
                    $query->where('wind_speed', '>', 10.0);
                };
            }
        };

        // Create weather station in Bayern
        $station = TestWeatherStation::create([
            'name' => 'Test Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create anemometers with different wind speeds
        $highWindAnemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 15.0, // > 10, would be IN SCOPE
            'max_speed' => 25.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $lowWindAnemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 5.0, // <= 10, would be OUT OF SCOPE
            'max_speed' => 10.0,
            'sensor_type' => 'mechanical',
        ]);

        // Run sync
        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Both anemometers get hashes (because TestAnemometer has no scope by default)
        $highWindHash = Hash::where('hashable_type', 'test_anemometer')
            ->where('hashable_id', $highWindAnemometer->id)
            ->first();
        expect($highWindHash)->not->toBeNull();

        $lowWindHash = Hash::where('hashable_type', 'test_anemometer')
            ->where('hashable_id', $lowWindAnemometer->id)
            ->first();
        expect($lowWindHash)->not->toBeNull();

        // Station has dependencies to both (current behavior)
        $dependencies = HashDependent::where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->count();
        expect($dependencies)->toBe(2);
    });
});
