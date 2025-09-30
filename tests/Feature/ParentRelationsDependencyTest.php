<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
        'test_anemometer' => TestAnemometer::class,
    ]);
});

describe('parent relations dependency building', function () {
    it('creates parent dependencies based on getHashParentRelations', function () {
        // Create a weather station
        $station = TestWeatherStation::create([
            'name' => 'Munich Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create windvane and anemometer
        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 180.0,
            'accuracy' => 95.0,
            'calibration_date' => now(),
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 12.5,
            'max_speed' => 25.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $processor = app(BulkHashProcessor::class);

        // Process the station to create its hash
        $processor->processChangedModels(TestWeatherStation::class);

        // Build pending dependencies for station (this creates child dependencies)
        $processor->buildPendingDependencies(TestWeatherStation::class);

        // Process windvane and anemometer to create their hashes
        $processor->processChangedModels(TestWindvane::class);
        $processor->processChangedModels(TestAnemometer::class);

        // Build pending dependencies which now includes parent dependencies
        $processor->buildPendingDependencies(TestWindvane::class);
        $processor->buildPendingDependencies(TestAnemometer::class);

        // Check that parent dependencies were created with relation names
        $windvaneHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $windvane->id)
            ->first();

        $anemometerHash = Hash::where('hashable_type', 'test_anemometer')
            ->where('hashable_id', $anemometer->id)
            ->first();

        // Verify windvane parent dependency
        $windvaneDependency = HashDependent::where('hash_id', $windvaneHash->id)
            ->where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->first();

        expect($windvaneDependency)->not->toBeNull();
        expect($windvaneDependency->relation_name)->toBe('weatherStation');

        // Verify anemometer parent dependency
        $anemometerDependency = HashDependent::where('hash_id', $anemometerHash->id)
            ->where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $station->id)
            ->first();

        expect($anemometerDependency)->not->toBeNull();
        expect($anemometerDependency->relation_name)->toBe('weatherStation');
    });

    it('respects parent model scope when building dependencies', function () {
        // Create station outside of scope
        $berlinStation = TestWeatherStation::create([
            'name' => 'Berlin Station',
            'location' => 'Berlin',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create station within scope
        $bayernStation = TestWeatherStation::create([
            'name' => 'Munich Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create windvanes for both stations
        $berlinWindvane = TestWindvane::create([
            'weather_station_id' => $berlinStation->id,
            'direction' => 90.0,
            'accuracy' => 92.0,
            'calibration_date' => now(),
        ]);

        $bayernWindvane = TestWindvane::create([
            'weather_station_id' => $bayernStation->id,
            'direction' => 180.0,
            'accuracy' => 95.0,
            'calibration_date' => now(),
        ]);

        $processor = app(BulkHashProcessor::class);

        // Process all models
        $processor->processChangedModels(TestWeatherStation::class);
        $processor->processChangedModels(TestWindvane::class);

        // Build pending dependencies
        $processor->buildPendingDependencies(TestWeatherStation::class);
        $processor->buildPendingDependencies(TestWindvane::class);

        // Berlin windvane should NOT have a hash (parent station is out of scope)
        $berlinWindvaneHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $berlinWindvane->id)
            ->first();
        expect($berlinWindvaneHash)->toBeNull();

        // Bayern windvane should have a hash (parent station is in scope)
        $bayernWindvaneHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $bayernWindvane->id)
            ->first();
        expect($bayernWindvaneHash)->not->toBeNull();

        // Bayern station is in scope, so dependency should be created
        $bayernDependency = HashDependent::where('hash_id', $bayernWindvaneHash->id)
            ->where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $bayernStation->id)
            ->first();
        expect($bayernDependency)->not->toBeNull();
        expect($bayernDependency->relation_name)->toBe('weatherStation');
    });

    it('does not create parent dependencies when getHashParentRelations returns empty array', function () {
        // TestWeatherStation has empty getHashParentRelations()
        $station = TestWeatherStation::create([
            'name' => 'Test Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $processor = app(BulkHashProcessor::class);
        $processor->processChangedModels(TestWeatherStation::class);

        // Build pending dependencies (should not create parent deps since getHashParentRelations is empty)
        $processor->buildPendingDependencies(TestWeatherStation::class);

        $stationHash = Hash::where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->first();

        // No parent dependencies should exist
        $dependencies = HashDependent::where('hash_id', $stationHash->id)->get();
        expect($dependencies)->toHaveCount(0);
    });
});
