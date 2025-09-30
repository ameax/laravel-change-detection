<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
    ]);
});

describe('windvane scope filtering based on parent station', function () {
    it('only creates dependencies for windvanes whose parent station is in scope', function () {
        // Create 2 weather stations: 1 in scope, 1 outside scope
        $stationInScope = TestWeatherStation::create([
            'name' => 'München Station',
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

        // Create windvane for each station
        $windvaneInScope = TestWindvane::create([
            'weather_station_id' => $stationInScope->id,
            'direction' => 180.0,
            'accuracy' => 95.0,
            'calibration_date' => '2024-01-15',
        ]);

        $windvaneOutOfScope = TestWindvane::create([
            'weather_station_id' => $stationOutOfScope->id,
            'direction' => 90.0,
            'accuracy' => 92.0,
            'calibration_date' => '2024-01-15',
        ]);

        // Create publisher for WeatherStation
        $publisher = createWeatherStationPublisher();

        // Run sync command
        runWeatherStationSync();

        // Should only create hash for station in scope
        expectStationHashActive($stationInScope->id);
        expectStationHashNotExists($stationOutOfScope->id);

        // Only the windvane of the in-scope station should get a hash
        $windvaneInScopeHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $windvaneInScope->id)
            ->whereNull('deleted_at')
            ->first();
        expect($windvaneInScopeHash)->not->toBeNull();

        // Windvane of out-of-scope station should NOT get a hash
        $windvaneOutOfScopeHash = Hash::where('hashable_type', 'test_windvane')
            ->where('hashable_id', $windvaneOutOfScope->id)
            ->first();
        expect($windvaneOutOfScopeHash)->toBeNull();

        // The in-scope windvane should have a dependency to the station
        $inScopeDependency = \Ameax\LaravelChangeDetection\Models\HashDependent::where('hash_id', $windvaneInScopeHash->id)
            ->where('dependent_model_type', 'test_weather_station')
            ->where('dependent_model_id', $stationInScope->id)
            ->first();
        expect($inScopeDependency)->not->toBeNull();

        // Verify publish records only created for in-scope models
        $publishCount = \Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count();
        expect($publishCount)->toBe(1); // Only for the station in scope
    });
});