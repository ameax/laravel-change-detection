<?php

use Ameax\LaravelChangeDetection\Helpers\ModelDiscoveryHelper;
use Ameax\LaravelChangeDetection\Models\Publisher;
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

it('discovers all models for weather station sync', function () {
    // Create a weather station publisher
    $publisher = Publisher::create([
        'name' => 'Test Weather Publisher',
        'model_type' => 'test_weather_station',
        'publisher_class' => 'Ameax\\LaravelChangeDetection\\Publishers\\LogPublisher',
        'is_active' => true,
    ]);

    // Get all models that should be synced for this publisher
    $models = ModelDiscoveryHelper::getAllModelsForSync('test_weather_station');

    dump('Models discovered for sync:');
    foreach ($models as $index => $model) {
        dump(($index + 1) . '. ' . $model);
    }

    // Verify the order is correct: dependencies first, then main model
    expect($models)->toHaveCount(3);
    expect($models[0])->toBe(TestWindvane::class); // Dependency 1
    expect($models[1])->toBe(TestAnemometer::class); // Dependency 2
    expect($models[2])->toBe(TestWeatherStation::class); // Main model last
});

it('runs autodiscovery sync and processes all models', function () {
    // Create test data
    $station = TestWeatherStation::create([
        'name' => 'Autodiscovery Test Station',
        'location' => 'Bayern',
        'latitude' => 48.1351,
        'longitude' => 11.5820,
        'status' => 'active',
        'is_operational' => true,
    ]);

    $anemometer = TestAnemometer::create([
        'weather_station_id' => $station->id,
        'wind_speed' => 12.5,
        'max_speed' => 25.0,
        'sensor_type' => 'ultrasonic',
    ]);

    // Create publisher
    $publisher = Publisher::create([
        'name' => 'Weather Station Publisher',
        'model_type' => 'test_weather_station',
        'publisher_class' => 'Ameax\\LaravelChangeDetection\\Publishers\\LogPublisher',
        'is_active' => true,
    ]);

    // Run autodiscovery sync
    test()->artisan('change-detection:sync')->assertExitCode(0);

    // Check that all models have hashes
    $stationHash = \Ameax\LaravelChangeDetection\Models\Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();
    expect($stationHash)->not->toBeNull();

    $anemometerHash = \Ameax\LaravelChangeDetection\Models\Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();
    expect($anemometerHash)->not->toBeNull();

    // Check that dependencies are built
    expect($stationHash->has_dependencies_built)->toBeTrue();

    // Check that hash_dependent records exist
    $dependents = \Ameax\LaravelChangeDetection\Models\HashDependent::where('dependent_model_type', 'test_weather_station')
        ->where('dependent_model_id', $station->id)
        ->get();
    expect($dependents)->toHaveCount(1); // One anemometer dependency
});